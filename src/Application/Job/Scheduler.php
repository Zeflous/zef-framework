<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Cache\LockStoreInterface;
use Zef\Framework\Validation\Identifier;

/**
 * Cron-like scheduler feeding due scheduled jobs into a JobQueueInterface.
 *
 * Registrations pair a job type + payload with a ScheduleInterface. Each
 * `tick($nowUnixNano)` enqueues one envelope per registration whose next due
 * time has arrived, then advances that registration's cursor. Fire times come
 * from the schedule itself (epoch-aligned for fixed intervals, wall-clock
 * aligned for cron), so restarts never drift or double-fire within a minute.
 *
 * The worker that drains the queue provides retries; the scheduler only
 * guarantees "due jobs become visible".
 *
 * v2.24.0 (issue #68): optional cluster safety. When constructed with a
 * LockStoreInterface (e.g. RedisLockStore in production, InMemoryLockStore
 * in tests), each tick first acquires a named lease on the store; a node
 * that does not hold the lease skips the tick entirely (returns 0) instead
 * of double-enqueueing. The lease is sticky and never released after a
 * tick: as long as the acting node keeps ticking within the TTL its lease
 * is refreshed, and when it stops (crash, pause) the lease lapses after
 * clusterTtlSeconds and another node takes over — standard leader-election
 * behaviour with no operator action.
 *
 * TTL budgeting is the caller's responsibility and cannot be enforced by
 * the scheduler itself, because tick duration is a runtime property:
 * size clusterTtlSeconds to comfortably exceed the WORST-CASE tick duration
 * (registrations x enqueue cost, including catch-up bursts after downtime).
 * A TTL shorter than a tick can let the lease lapse mid-tick and admit a
 * duplicate enqueue window; a TTL sized with headroom trades a slightly
 * slower takeover for exactly-once ticks. Use
 * relinquishClusterLeadership() for graceful handover.
 */
final class Scheduler
{
    private const string CLUSTER_KEY_PREFIX = 'zef:scheduler:';

    /**
     * @var array<string,array{schedule:ScheduleInterface,payload:mixed,correlationId:?string,nextRunUnixNano:null|int}>
     */
    private array $registrations = [];

    private readonly string $clusterOwner;

    private readonly string $clusterLockKey;

    public function __construct(
        private readonly JobQueueInterface $queue,
        private readonly int $maxRegistrations = 256,
        private readonly int $maxCatchUpPerTick = 8,
        private readonly ?LockStoreInterface $clusterLockStore = null,
        private readonly string $clusterName = 'default',
        private readonly int $clusterTtlSeconds = 30,
    ) {
        if ($this->maxRegistrations < 1 || $this->maxCatchUpPerTick < 1) {
            throw new \InvalidArgumentException('Scheduler budgets must be positive.');
        }
        if ($clusterName === '' || strlen($clusterName) > 128) {
            throw new \InvalidArgumentException('Scheduler cluster name must be 1..128 bytes.');
        }
        if ($this->clusterTtlSeconds < 1 || $this->clusterTtlSeconds > 86400) {
            throw new \InvalidArgumentException('Scheduler cluster lease TTL must be 1..86400 seconds.');
        }
        $this->clusterLockKey = self::CLUSTER_KEY_PREFIX . $clusterName;
        $this->clusterOwner = 'sched-' . bin2hex(random_bytes(8));
    }

    /**
     * Register a recurring job. Re-registering the same jobType replaces the
     * previous schedule (keeping its cursor).
     */
    public function register(
        string $jobType,
        mixed $payload,
        ScheduleInterface $schedule,
        ?string $correlationId = null,
    ): void {
        Identifier::assertMessageType($jobType, 'scheduled job type');
        if ($correlationId !== null) {
            Identifier::assertOpaqueId($correlationId, 'scheduler correlation ID');
        }
        if (!isset($this->registrations[$jobType]) && count($this->registrations) >= $this->maxRegistrations) {
            throw new \OverflowException("Scheduler registration budget exceeded ({$this->maxRegistrations}).");
        }
        $this->registrations[$jobType] = [
            'schedule' => $schedule,
            'payload' => $payload,
            'correlationId' => $correlationId,
            'nextRunUnixNano' => null,
        ];
    }

    public function unregister(string $jobType): bool
    {
        Identifier::assertMessageType($jobType, 'scheduled job type');
        if (!isset($this->registrations[$jobType])) {
            return false;
        }
        unset($this->registrations[$jobType]);

        return true;
    }

    /** @return list<string> registered job types in registration order */
    public function jobTypes(): array
    {
        return array_keys($this->registrations);
    }

    public function nextRunOf(string $jobType): ?int
    {
        return $this->registrations[$jobType]['nextRunUnixNano'] ?? null;
    }

    public function scheduleOf(string $jobType): ?ScheduleInterface
    {
        return $this->registrations[$jobType]['schedule'] ?? null;
    }

    /**
     * Advance the clock: enqueue due jobs. Returns the number of envelopes
     * enqueued. Per registration at most $maxCatchUpPerTick envelopes are
     * produced per tick (protects against huge catch-up bursts after
     * downtime); the cursor still advances past the due horizon.
     *
     * Cluster mode: when another node currently holds the acting-scheduler
     * lease, this tick is skipped and 0 is returned — due jobs stay due and
     * the lease holder's own tick enqueues them exactly once.
     */
    public function tick(int $nowUnixNano): int
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Scheduler tick time must be non-negative.');
        }
        if (
            $this->clusterLockStore instanceof LockStoreInterface
            && !$this->clusterLockStore->acquire($this->clusterLockKey, $this->clusterOwner, $this->clusterTtlSeconds)
        ) {
            return 0;
        }
        $enqueued = 0;
        foreach ($this->registrations as $jobType => $state) {
            // N-18 (issue #176): clamp the "compute first due" seed to 0 —
            // tick(0) previously passed -1 to nextRunAfter(), which
            // CronExpression rejects, making the epoch-origin tick throw
            // instead of being a well-defined zero-enqueue pass.
            $next = $state['nextRunUnixNano']
                ?? $state['schedule']->nextRunAfter(max(0, $nowUnixNano - 1));
            $produced = 0;
            while ($next <= $nowUnixNano && $produced < $this->maxCatchUpPerTick) {
                $this->queue->enqueue(new JobEnvelope(
                    jobId: $this->newJobId(),
                    jobType: $jobType,
                    payload: $state['payload'],
                    availableAtUnixNano: $next,
                    correlationId: $state['correlationId'],
                ));
                ++$produced;
                ++$enqueued;
                $next = $state['schedule']->nextRunAfter($next);
                // Commit the successor cursor incrementally (#312): the cursor
                // semantics are "next scheduled fire", so after every
                // successful enqueue the successor is recorded. If a LATER
                // enqueue throws (queue capacity, storage failure), the fires
                // that already succeeded are accounted for and the next tick
                // resumes at the first un-enqueued fire instead of replaying
                // the committed ones.
                $this->registrations[$jobType]['nextRunUnixNano'] = $next;
            }
            $this->registrations[$jobType]['nextRunUnixNano'] = $next;
        }

        return $enqueued;
    }

    /**
     * Whether this instance is the acting cluster scheduler (lease holder).
     * Always true in single-node mode (no lock store configured).
     */
    public function isClusterLeader(): bool
    {
        if (!$this->clusterLockStore instanceof LockStoreInterface) {
            return true;
        }

        return $this->clusterLockStore->holder($this->clusterLockKey) === $this->clusterOwner;
    }

    /**
     * Gracefully hand over cluster leadership (rolling restarts). Returns
     * false when this instance was not the acting scheduler. Always true
     * (no-op) in single-node mode.
     */
    public function relinquishClusterLeadership(): bool
    {
        if (!$this->clusterLockStore instanceof LockStoreInterface) {
            return true;
        }

        return $this->clusterLockStore->release($this->clusterLockKey, $this->clusterOwner);
    }

    /**
     * Owner token this scheduler competes for the cluster lease with.
     */
    public function clusterOwner(): string
    {
        return $this->clusterOwner;
    }

    /**
     * Cluster name this scheduler competes under (observability).
     */
    public function clusterName(): string
    {
        return $this->clusterName;
    }

    private function newJobId(): string
    {
        return 'sched-' . bin2hex(random_bytes(8));
    }
}
