<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 (issue #68) — cluster-safe scheduler: the acting
 * node holds a named lease on a LockStoreInterface, other nodes skip their
 * ticks, and a crashed/lapsed leader is taken over automatically once the
 * TTL passes. Uses the in-memory store with an injectable clock so every
 * transition is deterministic (no sleeps).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\Job\FixedIntervalSchedule;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\Scheduler;

/**
 * @internal
 */
final class SchedulerClusterSafetyTest extends TestCase
{
    private const int T0 = 1_700_000_040; // multiple of 60s: fire times land on nano(0), nano(60), ...

    /** @var array{0:int} virtual unix-second clock shared by the lock store */
    private array $now = [self::T0];

    private InMemoryLockStore $locks;

    protected function setUp(): void
    {
        $this->now = [self::T0];
        $this->locks = new InMemoryLockStore($this->clock(...));
    }

    public function testSingleNodeModeIsUnchanged(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $scheduler->register('cleanup', null, new FixedIntervalSchedule(60));

        self::assertTrue($scheduler->isClusterLeader());
        self::assertTrue($scheduler->relinquishClusterLeadership());
        self::assertSame(1, $scheduler->tick($this->nano(0)));
        self::assertSame(1, $queue->size());
    }

    public function testFollowerSkipsTickInsteadOfDoubleEnqueueing(): void
    {
        $leaderQueue = new InMemoryJobQueue();
        $followerQueue = new InMemoryJobQueue();
        $leader = new Scheduler($leaderQueue, 256, 8, $this->locks, 'web', 30);
        $follower = new Scheduler($followerQueue, 256, 8, $this->locks, 'web', 30);
        foreach ([$leader, $follower] as $scheduler) {
            $scheduler->register('cleanup', null, new FixedIntervalSchedule(60));
        }

        // Leader ticks first and becomes the acting scheduler.
        self::assertSame(1, $leader->tick($this->nano(0)));
        self::assertTrue($leader->isClusterLeader());

        // The follower's tick is skipped: no enqueue, no cursor advance
        // lying about work it never produced.
        self::assertSame(0, $follower->tick($this->nano(1)));
        self::assertFalse($follower->isClusterLeader());
        self::assertSame(1, $leaderQueue->size());
        self::assertSame(0, $followerQueue->size());

        // Next due fire: still exactly one envelope cluster-wide.
        self::assertSame(1, $leader->tick($this->nano(60)));
        self::assertSame(0, $follower->tick($this->nano(61)));
        self::assertSame(2, $leaderQueue->size());
        self::assertSame(0, $followerQueue->size());
    }

    public function testStickyLeadershipWhileTickingWithinTtl(): void
    {
        $leader = $this->leader();
        $follower = $this->follower();
        $leader->register('cleanup', null, new FixedIntervalSchedule(60));

        self::assertSame(1, $leader->tick($this->nano(0)));

        // Many ticks later (each < TTL apart) the leader's own ticks keep
        // refreshing the lease — whether or not anything is due — so the
        // follower never steals leadership between ticks.
        for ($i = 1; $i <= 10; ++$i) {
            $this->now[0] += 20;
            $leader->tick($this->nano($i * 20));
            self::assertSame(0, $follower->tick($this->nano($i * 20)));
            self::assertTrue($leader->isClusterLeader());
            self::assertFalse($follower->isClusterLeader());
        }
    }

    public function testLeaseIsRenewedBeforeAFollowerCanTakeOver(): void
    {
        $leader = $this->leader();
        $follower = $this->follower();
        foreach ([$leader, $follower] as $scheduler) {
            $scheduler->register('cleanup', null, new FixedIntervalSchedule(60));
        }

        self::assertSame(1, $leader->tick($this->nano(0)));

        // A healthy leader renews at t+20. Its original 30-second lease
        // would have lapsed by t+35, so the follower must still be rejected
        // before the leader's next tick.
        $this->now[0] += 20;
        self::assertSame(0, $leader->tick($this->nano(20)));
        $this->now[0] += 15;
        self::assertSame(0, $follower->tick($this->nano(35)));
        self::assertTrue($leader->isClusterLeader());
        self::assertFalse($follower->isClusterLeader());
    }

    public function testLapsedLeaderIsTakenOverAutomatically(): void
    {
        $leaderQueue = new InMemoryJobQueue();
        $takeoverQueue = new InMemoryJobQueue();
        $leader = new Scheduler($leaderQueue, 256, 8, $this->locks, 'web', 30);
        $takeover = new Scheduler($takeoverQueue, 256, 8, $this->locks, 'web', 30);
        $leader->register('cleanup', null, new FixedIntervalSchedule(60));
        $takeover->register('cleanup', null, new FixedIntervalSchedule(60));

        self::assertSame(1, $leader->tick($this->nano(0)));

        // The acting node crashes (no more ticks). 31s later its lease has
        // lapsed; at the next fire time the surviving node takes over and
        // enqueues without operator action.
        $this->now[0] += 31;
        self::assertFalse($leader->isClusterLeader());
        $this->now[0] = self::T0 + 60;
        self::assertSame(1, $takeover->tick($this->nano(60)));
        self::assertTrue($takeover->isClusterLeader());
        self::assertSame(1, $leaderQueue->size());
        self::assertSame(1, $takeoverQueue->size());

        // The old node coming back is now the follower.
        self::assertSame(0, $leader->tick($this->nano(60)));
        self::assertSame(1, $leaderQueue->size());
    }

    public function testGracefulHandoverViaRelinquish(): void
    {
        $leader = $this->leader();
        $follower = $this->follower();

        self::assertSame(0, $leader->tick($this->nano(0))); // nothing due yet
        self::assertTrue($leader->isClusterLeader());

        // Rolling restart: leader steps down explicitly.
        self::assertTrue($leader->relinquishClusterLeadership());
        self::assertFalse($leader->isClusterLeader());

        self::assertSame(0, $follower->tick($this->nano(1)));
        self::assertTrue($follower->isClusterLeader());

        // Non-leader relinquish is a no-op returning false.
        self::assertFalse($leader->relinquishClusterLeadership());
    }

    public function testClusterNamesAreIndependent(): void
    {
        $webA = new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, 'web', 30);
        $webB = new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, 'web', 30);
        $cliA = new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, 'cli', 30);

        self::assertSame(0, $webA->tick($this->nano(0)));
        self::assertSame(0, $cliA->tick($this->nano(0)));
        self::assertTrue($webA->isClusterLeader());
        self::assertTrue($cliA->isClusterLeader());

        self::assertSame(0, $webB->tick($this->nano(1)));
        self::assertFalse($webB->isClusterLeader());
    }

    public function testClusterOwnerTokenIsStableAndUnique(): void
    {
        $a = $this->leader();
        $b = $this->follower();

        self::assertMatchesRegularExpression('/^sched-[0-9a-f]{16}$/', $a->clusterOwner());
        self::assertSame($a->clusterOwner(), $a->clusterOwner());
        self::assertNotSame($a->clusterOwner(), $b->clusterOwner());
    }

    /**
     * @dataProvider invalidClusterConfigurationProvider
     */
    public function testClusterConfigurationIsValidated(string $name, int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, $name, $ttl);
    }

    /**
     * @return array<string, array{0:string, 1:int}>
     */
    public static function invalidClusterConfigurationProvider(): array
    {
        return [
            'empty cluster name' => ['', 30],
            'oversized cluster name' => [str_repeat('c', 129), 30],
            'zero ttl' => ['web', 0],
            'negative ttl' => ['web', -1],
            'ttl above port bound' => ['web', 86401],
        ];
    }

    public function testFollowerDueJobsAreStillEnqueuedByLeader(): void
    {
        // The follower skipping a tick must not DROP work: its cursor only
        // advances when it is itself the acting node, so the leader's own
        // tick for the same due horizon covers the cluster exactly once.
        $leaderQueue = new InMemoryJobQueue();
        $leader = new Scheduler($leaderQueue, 256, 8, $this->locks, 'web', 30);
        $follower = $this->follower();
        $leader->register('cleanup', null, new FixedIntervalSchedule(60));
        $follower->register('cleanup', null, new FixedIntervalSchedule(60));

        self::assertSame(1, $leader->tick($this->nano(0)));
        self::assertSame(0, $follower->tick($this->nano(0)));
        $this->now[0] += 60;
        self::assertSame(1, $leader->tick($this->nano(60)));
        self::assertSame(0, $follower->tick($this->nano(60)));
        self::assertSame(2, $leaderQueue->size());
    }

    private function clock(): int
    {
        return $this->now[0];
    }

    /** @param int $secondsPastT0 seconds past T0, converted to nanoseconds */
    private function nano(int $secondsPastT0): int
    {
        return (self::T0 + $secondsPastT0) * 1_000_000_000;
    }

    private function leader(): Scheduler
    {
        return new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, 'web', 30);
    }

    private function follower(): Scheduler
    {
        return new Scheduler(new InMemoryJobQueue(), 256, 8, $this->locks, 'web', 30);
    }
}
