<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regresi I-10 (issue #175): snapshot-seeded replay must
 * not pay full-stream I/O.
 *
 * replayAfter() used to load the FULL stream and filter in PHP, so
 * snapshot hydration read (and hydrated) every pre-snapshot event just to
 * skip it. The port now carries an optional afterVersion tail cut
 * (BC-safe default 0): the PDO adapter pushes it into SQL
 * (WHERE version > X), the in-memory reference adapter filters in memory,
 * and the repository passes the snapshot version through — with an
 * in-loop guard that still protects against stores that ignore the
 * argument.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\EventStoreInterface;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * @internal
 */
final class EventSourcingReplayAfterVersionTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    /**
     * Regresi I-10 (issue #175): find() on a snapshot-seeded aggregate
     * passes the SNAPSHOT VERSION into loadStream — the store received the
     * afterVersion argument (recorded by the spy), not a full-stream read.
     */
    public function testSnapshotReplayPassesAfterVersionIntoTheStore(): void
    {
        $spy = new SpyEventStore(new InMemoryEventStore(static fn (): int => self::NANO));
        $snapshots = new InMemorySnapshotStore();
        $repo = new AggregateRepository(store: $spy, snapshots: $snapshots, policy: SnapshotPolicy::every(2));

        $aggregate = EventSourcingTestAccount::open('acc-1', 1);
        $aggregate->deposit(2);
        $repo->persist($aggregate); // version 2 — snapshot taken here
        $aggregate->deposit(5);
        $repo->persist($aggregate); // version 3 — no snapshot

        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');

        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(8, $loaded->balance());
        self::assertSame(
            [['type' => 'test.account', 'id' => 'acc-1', 'afterVersion' => 2]],
            $spy->loadCalls,
            'replayAfter must load ONLY the post-snapshot tail (afterVersion = snapshot version)',
        );
    }

    /**
     * Regresi I-10 (issue #175): the in-memory reference adapter applies
     * the tail cut with the same `version > afterVersion` semantics.
     */
    public function testInMemoryStoreAppliesTheTailCutInMemory(): void
    {
        $store = new InMemoryEventStore(static fn (): int => self::NANO);
        $store->appendToStream('test.account', 'acc-1', 0, ...$this->threeEvents());

        self::assertSame([1, 2, 3], $this->versions($store->loadStream('test.account', 'acc-1')), 'afterVersion 0 = the whole stream');
        self::assertSame([2, 3], $this->versions($store->loadStream('test.account', 'acc-1', 1)), 'the cut is exclusive');
        self::assertSame([3], $this->versions($store->loadStream('test.account', 'acc-1', 2)));
        self::assertSame([], $store->loadStream('test.account', 'acc-1', 3), 'nothing beyond the last version');
        self::assertSame([], $store->loadStream('test.account', 'missing', 1), 'an unknown stream stays an empty list');

        try {
            $store->loadStream('test.account', 'acc-1', -1);
            self::fail('a negative afterVersion must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('afterVersion must be >= 0', $e->getMessage());
        }
    }

    /**
     * Regresi I-10 (issue #175): the PDO adapter pushes the tail cut into
     * SQL — events before the cut are never read back (sqlite::memory:).
     */
    public function testPdoStorePushesTheTailCutIntoSql(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $store->createSchema();
        $store->appendToStream('test.account', 'acc-1', 0, ...$this->threeEvents());

        self::assertSame([1, 2, 3], $this->versions($store->loadStream('test.account', 'acc-1')), 'afterVersion 0 = the whole stream');
        self::assertSame([2, 3], $this->versions($store->loadStream('test.account', 'acc-1', 1)), 'WHERE version > 1');
        self::assertSame([3], $this->versions($store->loadStream('test.account', 'acc-1', 2)), 'WHERE version > 2');
        self::assertSame([], $store->loadStream('test.account', 'acc-1', 3));

        try {
            $store->loadStream('test.account', 'acc-1', -1);
            self::fail('a negative afterVersion must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('afterVersion must be >= 0', $e->getMessage());
        }
    }

    /**
     * Regresi I-10 (issue #175): the repository's in-loop guard still
     * protects replay when a store IGNORES the afterVersion argument and
     * returns the full stream — only post-snapshot events are applied.
     */
    public function testNonConformingStoreStillReplaysOnlyPostSnapshotEvents(): void
    {
        $store = new IgnoringAfterVersionStore(new InMemoryEventStore(static fn (): int => self::NANO));
        $snapshots = new InMemorySnapshotStore();
        $repo = new AggregateRepository(store: $store, snapshots: $snapshots, policy: SnapshotPolicy::every(2));

        $aggregate = EventSourcingTestAccount::open('acc-1', 1);
        $aggregate->deposit(2);
        $repo->persist($aggregate); // version 2 — snapshot (balance 3)
        $aggregate->deposit(5);
        $repo->persist($aggregate); // version 3

        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');

        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(8, $loaded->balance());
        self::assertCount(3, $loaded->log(), 'the guard must not re-apply pre-snapshot events onto the restored snapshot');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return list<PendingEvent>
     */
    private function threeEvents(): array
    {
        return [
            new PendingEvent('account.opened', ['initial' => 1]),
            new PendingEvent('account.deposited', ['amount' => 2]),
            new PendingEvent('account.deposited', ['amount' => 5]),
        ];
    }

    /**
     * @param list<StoredEvent> $events
     *
     * @return list<int>
     */
    private function versions(array $events): array
    {
        $versions = [];
        foreach ($events as $event) {
            $versions[] = $event->version;
        }

        return $versions;
    }
}

/**
 * Spy over the in-memory store: records every loadStream() argument tuple.
 *
 * @internal
 */
final class SpyEventStore implements EventStoreInterface
{
    /** @var list<array{type:string,id:string,afterVersion:int}> */
    public array $loadCalls = [];

    public function __construct(private readonly EventStoreInterface $inner) {}

    #[\Override]
    public function appendToStream(string $aggregateType, string $aggregateId, int $expectedVersion, PendingEvent ...$events): array
    {
        return $this->inner->appendToStream($aggregateType, $aggregateId, $expectedVersion, ...$events);
    }

    #[\Override]
    public function loadStream(string $aggregateType, string $aggregateId, int $afterVersion = 0): array
    {
        $this->loadCalls[] = ['type' => $aggregateType, 'id' => $aggregateId, 'afterVersion' => $afterVersion];

        return $this->inner->loadStream($aggregateType, $aggregateId, $afterVersion);
    }

    #[\Override]
    public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array
    {
        return $this->inner->streamAll($fromGlobalSequence, $limit);
    }
}

/**
 * Deliberately NON-conforming store: ignores the afterVersion tail cut and
 * always returns the full stream (the legacy behaviour the repository
 * guard must survive).
 *
 * @internal
 */
final class IgnoringAfterVersionStore implements EventStoreInterface
{
    public function __construct(private readonly EventStoreInterface $inner) {}

    #[\Override]
    public function appendToStream(string $aggregateType, string $aggregateId, int $expectedVersion, PendingEvent ...$events): array
    {
        return $this->inner->appendToStream($aggregateType, $aggregateId, $expectedVersion, ...$events);
    }

    #[\Override]
    public function loadStream(string $aggregateType, string $aggregateId, int $afterVersion = 0): array
    {
        return $this->inner->loadStream($aggregateType, $aggregateId);
    }

    #[\Override]
    public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array
    {
        return $this->inner->streamAll($fromGlobalSequence, $limit);
    }
}
