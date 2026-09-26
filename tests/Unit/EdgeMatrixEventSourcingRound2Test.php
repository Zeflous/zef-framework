<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\EventJson;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\InMemorySnapshotStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRecorder;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\OutboxStoreInterface;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoOutbox;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\Projector;
use Zef\Framework\EventSourcing\RowCast;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.19.0 — Event Sourcing mutation round 2: kills grammar-assert removals,
 * JSON error-message shape, default-clock drift, sequence continuation,
 * transaction rollback integrity (SQLite triggers), relay selective
 * failure, default limits, and DDL shape via PRAGMA introspection.
 *
 * @internal
 */
final class EdgeMatrixEventSourcingRound2Test extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    private const int CLOCK_WINDOW = 1_500_000_000; // 1.5s in nanoseconds

    // -------------------------------------------------- VO validation removals

    public function testStoredEventDefaultsAndPayloadValidation(): void
    {
        $minimal = new StoredEvent(str_repeat('1', 32), 't', 'i', 1, 1, 'e');
        self::assertSame(0, $minimal->recordedAtUnixNano, 'recordedAt default must be exactly 0');
        self::assertSame([], $minimal->payload);
        self::assertSame([], $minimal->metadata);

        try {
            new StoredEvent(str_repeat('1', 32), 't', 'i', 1, 1, 'e', ['bad' => fopen('php://memory', 'rb')]);
            self::fail('resource payload must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Stored event payload', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('1', 32), 't', 'i', 1, 1, 'e', [], ['bad' => NAN]);
            self::fail('NAN metadata must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Stored event metadata', $e->getMessage());
        }
    }

    public function testSnapshotValidationRemovals(): void
    {
        self::assertSame(0, new Snapshot('t', 'i', 1, [])->createdAtUnixNano, 'createdAt default must be exactly 0');

        try {
            new Snapshot('bad type', 'i', 1, []);
            self::fail('bad aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            new Snapshot('t', "bad\nid", 1, []);
            self::fail('bad aggregate id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }

        try {
            new Snapshot('t', 'i', 1, ['bad' => \INF]);
            self::fail('INF state must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Snapshot state must be JSON-encodable', $e->getMessage());
        }
    }

    public function testOutboxEntryValidationRemovals(): void
    {
        try {
            new OutboxEntry(str_repeat('1', 32), 'm', ['bad' => fopen('php://memory', 'rb')], [], 0, 'pending', 0, null, 0);
            self::fail('resource payload must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Outbox entry payload', $e->getMessage());
        }

        try {
            new OutboxEntry(str_repeat('1', 32), 'm', [], ['bad' => \NAN], 0, 'pending', 0, null, 0);
            self::fail('NAN metadata must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Outbox entry metadata', $e->getMessage());
        }

        try {
            new OutboxEntry(str_repeat('1', 32), 'm', [], [], 0, 'pending', 0, null, -1);
            self::fail('negative createdAt must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('createdAtUnixNano must be >= 0', $e->getMessage());
        }
    }

    public function testEventJsonErrorShape(): void
    {
        try {
            EventJson::encode(['x' => NAN], 'nanfield');
            self::fail('NAN must fail');
        } catch (EventSourcingException $e) {
            self::assertStringStartsWith('nanfield must be JSON-encodable: ', $e->getMessage());
            self::assertStringContainsString('Inf and NaN cannot be JSON encoded', $e->getMessage());
            self::assertSame(0, $e->getCode());
        }

        try {
            EventJson::decode('{broken', 'badfield');
            self::fail('broken JSON must fail');
        } catch (EventSourcingException $e) {
            self::assertStringStartsWith('Corrupt badfield: ', $e->getMessage());
            self::assertNotSame('', substr($e->getMessage(), strlen('Corrupt badfield: ')), 'detail must be present');
            self::assertSame(0, $e->getCode());
        }
    }

    public function testSeedVersionZeroIsValid(): void
    {
        $aggregate = EventSourcingTestAccount::restoreFromSnapshot(['id' => '', 'balance' => 0, 'log' => []], 0);
        self::assertSame(0, $aggregate->version(), 'a pristine snapshot at version 0 is legal');
    }

    public function testSnapshotPolicyIntervalOneIsValid(): void
    {
        $policy = SnapshotPolicy::every(1);
        self::assertSame(1, $policy->interval());
        self::assertTrue($policy->shouldSnapshot(1), 'version 1 with interval 1 must snapshot');
        self::assertTrue($policy->shouldSnapshot(2));
    }

    // -------------------------------------------------- InMemoryEventStore

    public function testAppendValidatesAggregateIdGrammar(): void
    {
        $store = new InMemoryEventStore();

        try {
            $store->appendToStream('t', "bad\nid", 0, new PendingEvent('e'));
            self::fail('bad aggregate id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    public function testDefaultRealtimeClockIsIntAndNearNow(): void
    {
        $store = new InMemoryEventStore();
        [$event] = $store->appendToStream('t', 'a', 0, new PendingEvent('e'));
        $now = (int) (microtime(true) * 1_000_000_000);
        self::assertLessThanOrEqual(self::CLOCK_WINDOW, abs($now - $event->recordedAtUnixNano) <= self::CLOCK_WINDOW ? 0 : 1);
        self::assertTrue(abs($now - $event->recordedAtUnixNano) <= self::CLOCK_WINDOW, 'default clock must be realtime nanoseconds');
    }

    public function testStreamKeepsPriorEventsAfterLaterAppend(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'));
        $store->appendToStream('t', 'a', 1, new PendingEvent('e2'));
        $stream = $store->loadStream('t', 'a');
        self::assertCount(2, $stream, 'the second append must extend, not replace, the stream');
        self::assertSame(['e1', 'e2'], array_map(static fn (StoredEvent $e): string => $e->eventType, $stream));
    }

    public function testStreamAllWithLimitOneWorks(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e2'));
        $page = $store->streamAll(1, 1);
        self::assertCount(1, $page);
        self::assertSame(1, $page[0]->globalSequence);
    }

    // -------------------------------------------------- InMemoryOutbox

    public function testDefaultClockEnqueueAndDueFreshEntries(): void
    {
        $outbox = new InMemoryOutbox();
        $entry = $outbox->enqueue('m', [], []);
        $now = (int) (microtime(true) * 1_000_000_000);
        self::assertTrue(abs($now - $entry->createdAtUnixNano) <= self::CLOCK_WINDOW, 'createdAt must be realtime nanoseconds');
        self::assertCount(1, $outbox->due(10), 'fresh entries must be due without an explicit now');
    }

    public function testDueWithExplicitPastNowIgnoresRealtime(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO + 100);
        $outbox->enqueue('m', [], []);
        self::assertSame([], $outbox->due(10, 0), 'explicit now wins over the adapter clock');
        self::assertCount(1, $outbox->due(10, self::NANO + 100));
    }

    public function testDueOrderFollowsCreatedAtNotInsertion(): void
    {
        $tick = 3;
        $outbox = new InMemoryOutbox(static function () use (&$tick): int {
            return 100 * $tick--; // reverse ticks: insertion order != chronological
        });
        $outbox->enqueue('late', [], []);
        $outbox->enqueue('mid', [], []);
        $outbox->enqueue('early', [], []);
        $types = array_map(static fn (OutboxEntry $e): string => $e->messageType, $outbox->due(10, PHP_INT_MAX));
        self::assertSame(['early', 'mid', 'late'], $types, 'due() must be FIFO by createdAt');
    }

    public function testFailedOrderingAndLimitOne(): void
    {
        $tick = 2;
        $outbox = new InMemoryOutbox(static function () use (&$tick): int {
            return 100 * $tick--; // reverse ticks: insertion order != chronological
        });
        $a = $outbox->enqueue('first', [], []);  // createdAt 200 (later)
        $b = $outbox->enqueue('second', [], []); // createdAt 100 (earlier)
        $outbox->markDead($a->id, 'x');
        $outbox->markDead($b->id, 'y');
        $dead = $outbox->failed(10);
        self::assertSame(['second', 'first'], array_map(static fn (OutboxEntry $e): string => $e->messageType, $dead), 'dead letters are FIFO by createdAt, not insertion');
        self::assertCount(1, $outbox->failed(1), 'limit 1 must work and keep the FIFO head');
        self::assertSame('second', $outbox->failed(1)[0]->messageType);
    }

    public function testMarkFailedValidatesRetryTimestamp(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $entry = $outbox->enqueue('m', [], []);

        try {
            $outbox->markFailed($entry->id, 'boom', -1);
            self::fail('negative retry timestamp must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('retryAtUnixNano must be >= 0', $e->getMessage());
        }
    }

    public function testFailedLimitOneRejectsZero(): void
    {
        $outbox = new InMemoryOutbox();

        try {
            $outbox->failed(0);
            self::fail('failed limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('failed() limit must be >= 1 (got 0).', $e->getMessage());
        }
    }

    // -------------------------------------------------- OutboxRecorder

    public function testRecordReturnsAllEntriesAlignedWithEvents(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        $recorder = new OutboxRecorder($outbox);
        $events = [
            new StoredEvent(str_repeat('a', 32), 'T', 'I', 1, 1, 'type.one'),
            new StoredEvent(str_repeat('b', 32), 'T', 'I', 2, 2, 'type.two'),
        ];
        $entries = $recorder->record($events);
        self::assertCount(2, $entries, 'one outbox entry per event, all returned');
        $dueIds = array_map(static fn (OutboxEntry $e): string => $e->id, $outbox->due(10, PHP_INT_MAX));
        sort($dueIds);
        $gotIds = array_map(static fn (OutboxEntry $e): string => $e->id, $entries);
        sort($gotIds);
        self::assertSame($dueIds, $gotIds);
    }

    public function testSystemMetadataIsPublicAndComplete(): void
    {
        $event = new StoredEvent(str_repeat('c', 32), 'AT', 'AI', 4, 9, 'ev');
        $meta = OutboxRecorder::systemMetadata($event);
        self::assertSame([
            'eventId' => str_repeat('c', 32),
            'aggregateType' => 'AT',
            'aggregateId' => 'AI',
            'version' => 4,
            'globalSequence' => 9,
        ], $meta);
    }

    /**
     * Probe store: writes a row through the SHARED connection on enqueue
     * and explodes on the second call — proving record() wraps work in a
     * transaction when a connection is present.
     */
    public function testRecorderTransactionRollsBackProbeRowsOnFailure(): void
    {
        $conn = $this->sqliteConn();
        $conn->execute(SqlQuery::raw('CREATE TABLE probe (n INTEGER NOT NULL)'));
        $probe = new class($conn) implements OutboxStoreInterface {
            private int $calls = 0;

            public function __construct(private readonly PdoConnection $conn) {}

            public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
            {
                ++$this->calls;
                $this->conn->execute(SqlQuery::raw('INSERT INTO probe (n) VALUES (' . $this->calls . ')'));
                if ($this->calls === 2) {
                    throw new \RuntimeException('probe abort on second enqueue');
                }

                return new OutboxEntry(str_repeat((string) $this->calls, 32), $messageType, $payload, $metadata, 0, OutboxEntry::STATUS_PENDING, 0, null, 0);
            }

            public function due(int $limit, ?int $nowUnixNano = null): array
            {
                return [];
            }

            public function markProcessed(string $id): void {}

            public function markFailed(string $id, string $error, int $retryAtUnixNano): void {}

            public function markDead(string $id, string $error): void {}

            public function failed(int $limit): array
            {
                return [];
            }

            public function countPending(): int
            {
                return 0;
            }

            public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
            {
                throw new \LogicException('not used by this probe');
            }
        };
        $recorder = new OutboxRecorder($probe, $conn);
        $events = [
            new StoredEvent(str_repeat('a', 32), 'T', 'I', 1, 1, 'type.one'),
            new StoredEvent(str_repeat('b', 32), 'T', 'I', 2, 2, 'type.two'),
        ];

        try {
            $recorder->record($events);
            self::fail('probe must abort the second enqueue');
        } catch (\RuntimeException $e) {
            self::assertSame('probe abort on second enqueue', $e->getMessage());
        }
        self::assertSame(0, $conn->transactionLevel());
        $rows = $conn->fetchAll(SqlQuery::raw('SELECT COUNT(*) AS aggregate FROM probe'));
        self::assertSame(0, RowCast::int($rows[0]['aggregate']), 'the transaction must roll back the first probe row');
    }

    // -------------------------------------------------- AggregateRepository

    public function testRepositoryRollsBackEventsWhenOutboxRecordFails(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $store->createSchema();
        $failingOutbox = new class implements OutboxStoreInterface {
            public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
            {
                throw new \RuntimeException('outbox down');
            }

            public function due(int $limit, ?int $nowUnixNano = null): array
            {
                return [];
            }

            public function markProcessed(string $id): void {}

            public function markFailed(string $id, string $error, int $retryAtUnixNano): void {}

            public function markDead(string $id, string $error): void {}

            public function failed(int $limit): array
            {
                return [];
            }

            public function countPending(): int
            {
                return 0;
            }

            public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
            {
                throw new \LogicException('not used by this probe');
            }
        };
        $repo = new AggregateRepository(
            store: $store,
            outbox: new OutboxRecorder($failingOutbox),
            connection: $conn,
        );
        $aggregate = EventSourcingTestAccount::open('acc-1', 5);

        try {
            $repo->persist($aggregate);
            self::fail('persist must fail when the outbox is down');
        } catch (\RuntimeException $e) {
            self::assertSame('outbox down', $e->getMessage());
        }
        self::assertSame([], $store->loadStream('test.account', 'acc-1'), 'events must roll back with the failed outbox');
        self::assertSame(0, $conn->transactionLevel());
    }

    public function testPersistWithoutSnapshotsAtIntervalVersionIsSafe(): void
    {
        // Persist 100 events incrementally: at version 100 the snapshot
        // policy fires — with no snapshot store the conjunction must
        // short-circuit instead of calling save() on null.
        $store = new InMemoryEventStore(static fn (): int => 1);
        $repo = new AggregateRepository($store);
        $aggregate = EventSourcingTestAccount::open('acc', 1);
        $repo->persist($aggregate);
        for ($i = 2; $i <= 100; ++$i) {
            $aggregate->deposit(1);
            $stored = $repo->persist($aggregate);
            self::assertCount(1, $stored);
        }
        self::assertSame(100, $aggregate->version(), 'persisting through the interval boundary must not crash');
    }

    public function testPersistWithoutSnapshotsOnExactIntervalVersion(): void
    {
        // One shot: build the aggregate to version 100 via markCommitted
        // pairs, then a single persist that lands exactly on the interval.
        $store = new InMemoryEventStore(static fn (): int => 1);
        $repo = new AggregateRepository($store);
        // Seed the store to version 99 first so expectedVersion matches.
        $seed = EventSourcingTestAccount::open('acc', 0);
        $seed->markCommitted();
        for ($i = 0; $i < 98; ++$i) {
            $seed->deposit(1);
            $seed->markCommitted();
        }
        // Persist nothing — instead replay: seed events via direct store append.
        $events = [];
        for ($i = 0; $i < 99; ++$i) {
            $events[] = new PendingEvent('account.deposited', ['amount' => 1]);
        }
        $store->appendToStream('test.account', 'acc2', 0, ...$events);
        $aggregate = $repo->find(EventSourcingTestAccount::class, 'acc2');
        self::assertInstanceOf(EventSourcingTestAccount::class, $aggregate);
        self::assertSame(99, $aggregate->version());
        $aggregate->deposit(1); // version 100
        $stored = $repo->persist($aggregate);
        self::assertCount(1, $stored);
        self::assertSame(100, $aggregate->version(), 'landing exactly on the interval without a snapshot store is safe');
    }

    public function testDefaultClockSnapshotCreatedAtIsRealtimeInt(): void
    {
        $repo = new AggregateRepository(
            new InMemoryEventStore(static fn (): int => 1),
            new InMemorySnapshotStore(),
            SnapshotPolicy::every(1),
        );
        $aggregate = EventSourcingTestAccount::open('acc', 1);
        $repo->persist($aggregate);
        // Use a fresh repo to read back: snapshot store passed through.
        $snapshots = new InMemorySnapshotStore();
        $repo2 = new AggregateRepository(new InMemoryEventStore(), $snapshots, SnapshotPolicy::every(1));
        $aggregate2 = EventSourcingTestAccount::open('acc2', 1);
        $repo2->persist($aggregate2);
        $snapshot = $snapshots->load('test.account', 'acc2');
        self::assertNotNull($snapshot);
        $now = (int) (microtime(true) * 1_000_000_000);
        self::assertTrue(abs($now - $snapshot->createdAtUnixNano) <= self::CLOCK_WINDOW, 'default snapshot clock must be realtime nanoseconds');
    }

    // -------------------------------------------------- Projector

    public function testRunSumsAppliedAcrossProjections(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $store->appendToStream(
            't',
            'a',
            0,
            new PendingEvent('e1'),
            new PendingEvent('e2'),
            new PendingEvent('e3'),
        );

        $projector = new Projector($store, new InMemoryCheckpointStore(), [
            $this->projection('one', ['e1', 'e2']),
            $this->projection('two', ['e3']),
        ]);
        self::assertSame(3, $projector->run(), 'run() must SUM applied counts across projections');
    }

    public function testRunProjectionBatchLimitOne(): void
    {
        $store = new InMemoryEventStore(static fn (): int => 1);
        $store->appendToStream('t', 'a', 0, new PendingEvent('e1'), new PendingEvent('e1'));
        $counter = new class implements ProjectionInterface {
            public int $hits = 0;

            public function projectionId(): string
            {
                return 'p';
            }

            public function handles(): array
            {
                return ['e1'];
            }

            public function handle(StoredEvent $event): void
            {
                ++$this->hits;
            }
        };
        $projector = new Projector($store, new InMemoryCheckpointStore(), [$counter]);
        self::assertSame(1, $projector->runProjection('p', 1));
        self::assertSame(1, $counter->hits);
    }

    public function testRunOnEmptyStoreTerminatesImmediately(): void
    {
        $counter = new class implements ProjectionInterface {
            public function projectionId(): string
            {
                return 'p';
            }

            public function handles(): array
            {
                return ['e1'];
            }

            public function handle(StoredEvent $event): void {}
        };
        $projector = new Projector(new InMemoryEventStore(), new InMemoryCheckpointStore(), [$counter]);
        self::assertSame(0, $projector->run(), 'an empty timeline must end the catch-up loop, not spin forever');
    }

    // -------------------------------------------------- OutboxRelay

    public function testDefaultClockRetryTimestampIsRealtimeInt(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => self::NANO);
        $outbox->enqueue('m', [], []);
        $relay = new OutboxRelay($outbox, $this->alwaysFailingBus(), null, maxAttempts: 2, backoffBaseMs: 1_000, backoffCapMs: 60_000);
        self::assertSame(0, $relay->relay(10));
        $failed = $outbox->due(10, PHP_INT_MAX)[0];
        $now = (int) (microtime(true) * 1_000_000_000);
        $expectedLow = $now + 1_000_000_000 - self::CLOCK_WINDOW;
        $expectedHigh = $now + 1_000_000_000 + self::CLOCK_WINDOW;
        self::assertTrue(
            $failed->nextAttemptAtUnixNano >= $expectedLow && $failed->nextAttemptAtUnixNano <= $expectedHigh,
            'retry timestamp must be now + backoff in realtime nanoseconds',
        );
    }

    public function testRelayDefaultLimitProcessesExactlyOneHundred(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        for ($i = 0; $i < 120; ++$i) {
            $outbox->enqueue('m' . $i, [], []);
        }
        $received = [];
        $relay = new OutboxRelay($outbox, $this->passingBus($received), static fn (): int => 2);
        self::assertSame(100, $relay->relay(), 'the default limit is exactly 100');
        self::assertSame(20, $outbox->countPending());
    }

    public function testDeadLettersDefaultLimitIsOneHundred(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        for ($i = 0; $i < 120; ++$i) {
            $outbox->enqueue('m' . $i, [], []);
        }
        $relay = new OutboxRelay($outbox, $this->alwaysFailingBus(), static fn (): int => 2, maxAttempts: 1);
        self::assertSame(0, $relay->relay(), 'an always-failing bus processes nothing');
        self::assertSame(100, count($relay->deadLetters()), 'deadLetters() default limit is exactly 100');
    }

    public function testRelayLimitOneWorks(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        $outbox->enqueue('a', [], []);
        $outbox->enqueue('b', [], []);
        $received = [];
        $relay = new OutboxRelay($outbox, $this->passingBus($received), static fn (): int => 2);
        self::assertSame(1, $relay->relay(1));
        self::assertSame(1, $outbox->countPending());
    }

    public function testRelayContinuesPastFailingEntry(): void
    {
        $outbox = new InMemoryOutbox(static fn (): int => 1);
        $outbox->enqueue('bad', [], []);
        $outbox->enqueue('good1', [], []);
        $outbox->enqueue('good2', [], []);
        $received = [];
        $bus = new class($received) implements EventBusInterface {
            /** @var list<string> */
            private array $received; // @phpstan-ignore-line written through the by-ref binding, read by the test // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /** @param list<string> $received */
            public function __construct(array &$received)
            {
                $this->received = &$received;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($event instanceof OutboxMessage) {
                    $this->received[] = $event->entry->messageType;
                    if ($event->entry->messageType === 'bad') {
                        throw new \RuntimeException('downstream hates bad');
                    }
                }

                return $event;
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 1));
            }

            /** @return iterable<object> */
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
        $relay = new OutboxRelay($outbox, $bus, static fn (): int => 2, maxAttempts: 2, backoffBaseMs: 1, backoffCapMs: 1);
        self::assertSame(2, $relay->relay(10), 'a failing entry must not stop the relay run');
        self::assertSame(1, $outbox->countPending(), 'only the failed entry stays pending');
        $attempted = $received;
        sort($attempted);
        self::assertSame(['bad', 'good1', 'good2'], $attempted, 'all due entries were attempted (same-tick FIFO ties break by id)');
    }

    public function testEventStoreAppendRollsBackPartialInserts(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $store->createSchema();
        // Kill switch: the second insert of any append explodes.
        $conn->execute(SqlQuery::raw(
            "CREATE TRIGGER zef_kill BEFORE INSERT ON zef_events WHEN NEW.event_type = 'boom'
             BEGIN SELECT RAISE(ABORT, 'boom insert'); END",
        ));

        try {
            $store->appendToStream('t', 'a', 0, new PendingEvent('ok'), new PendingEvent('boom'));
            self::fail('the trigger must abort the append');
        } catch (QueryException $e) {
            self::assertStringContainsString('boom insert', $e->getMessage());
        }
        self::assertSame([], $store->loadStream('t', 'a'), 'a partial append must roll back completely');
        self::assertSame(0, $conn->transactionLevel());
    }

    public function testEventStoreLoadValidatesGrammar(): void
    {
        $store = new PdoEventStore($this->sqliteConn(), 'zef_events');
        $store->createSchema();

        try {
            $store->loadStream('bad type', 'a');
            self::fail('bad aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            $store->loadStream('t', "bad\nid");
            self::fail('bad aggregate id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    public function testEventStoreAppendValidatesAggregateIdGrammar(): void
    {
        $store = new PdoEventStore($this->sqliteConn(), 'zef_events');
        $store->createSchema();

        try {
            $store->appendToStream('t', "bad\nid", 0, new PendingEvent('e'));
            self::fail('bad aggregate id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    public function testSnapshotStoreSaveRollsBackDeleteOnFailedInsert(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoSnapshotStore($conn, 'zef_snapshots');
        $store->createSchema();
        $store->save(new Snapshot('t', 'a', 5, ['v' => 5], 1));
        // Kill switch: inserting version 999 explodes (after the DELETE).
        $conn->execute(SqlQuery::raw(
            'CREATE TRIGGER zef_kill BEFORE INSERT ON zef_snapshots WHEN NEW.version = 999
             BEGIN SELECT RAISE(ABORT, \'boom insert\'); END',
        ));

        try {
            $store->save(new Snapshot('t', 'a', 999, [], 2));
            self::fail('the trigger must abort the save');
        } catch (QueryException $e) {
            self::assertStringContainsString('boom insert', $e->getMessage());
        }
        $loaded = $store->load('t', 'a');
        self::assertNotNull($loaded, 'the failed save must not destroy the previous snapshot');
        self::assertSame(5, $loaded->version, 'the DELETE inside the aborted upsert must roll back');
        self::assertSame(0, $conn->transactionLevel());
    }

    public function testSnapshotStoreConstructorValidatesTable(): void
    {
        try {
            new PdoSnapshotStore($this->sqliteConn(), 'bad-table!');
            self::fail('bad table name must fail eagerly');
        } catch (QueryException $e) {
            self::assertStringContainsString('Invalid table', $e->getMessage());
        }
    }

    public function testSnapshotStoreLoadAndDeleteValidateGrammar(): void
    {
        $store = new PdoSnapshotStore($this->sqliteConn(), 'zef_snapshots');
        $store->createSchema();

        try {
            $store->load('t', "bad\nid");
            self::fail('bad aggregate id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }

        try {
            $store->delete('bad type', 'a');
            self::fail('bad aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }
    }

    // -------------------------------------------------- PdoOutbox limit + state edges

    public function testPdoOutboxDueAndFailedLimitZeroThrow(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $outbox->createSchema();

        try {
            $outbox->due(0);
            self::fail('due limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('due() limit must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            $outbox->failed(0);
            self::fail('failed limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('failed() limit must be >= 1 (got 0).', $e->getMessage());
        }
    }

    public function testPdoOutboxDueAndFailedWithLimitOne(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $outbox->createSchema();
        $outbox->enqueue('a', [], []);
        $outbox->enqueue('b', [], []);
        self::assertCount(1, $outbox->due(1, self::NANO));
        $a = $outbox->due(1, self::NANO)[0];
        $outbox->markDead($a->id, 'x');
        $outbox->enqueue('c', [], []);
        $c = $outbox->enqueue('d', [], []);
        $outbox->markDead($c->id, 'y');
        $outbox->markDead($outbox->due(10, self::NANO)[0]->id, 'z');
        self::assertCount(1, $outbox->failed(1), 'failed limit 1 must work');
    }

    public function testPdoOutboxMarkFailedReactivatesProcessedEntry(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $outbox->createSchema();
        $entry = $outbox->enqueue('m', [], []);
        $outbox->markProcessed($entry->id);
        self::assertSame(0, $outbox->countPending());
        $outbox->markFailed($entry->id, 'requeue', self::NANO + 5);
        self::assertSame(1, $outbox->countPending(), 'markFailed must force status back to pending');
        $due = $outbox->due(10, self::NANO + 5);
        self::assertCount(1, $due);
        self::assertSame(1, $due[0]->attempts);
    }

    // -------------------------------------------------- DDL shape (kills concat order/operand mutants)

    public function testEventTableDdlShape(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoEventStore($conn, 'zef_events', static fn (): int => self::NANO);
        $store->createSchema();
        $columns = $conn->fetchAll(SqlQuery::raw('PRAGMA table_info("zef_events")'));
        $shape = array_map(static fn (array $c): string => RowCast::string($c['name']) . ':' . RowCast::string($c['type']), $columns);
        self::assertSame([
            'global_sequence:BIGINT',
            'event_id:VARCHAR(64)',
            'aggregate_type:VARCHAR(128)',
            'aggregate_id:VARCHAR(128)',
            'version:BIGINT',
            'event_type:VARCHAR(191)',
            'payload:TEXT',
            'metadata:TEXT',
            'recorded_at:BIGINT',
        ], $shape, 'column order and types are contractual');
        $indexes = $conn->fetchAll(SqlQuery::raw('PRAGMA index_list("zef_events")'));
        self::assertNotEmpty($indexes, 'the UNIQUE stream constraint must produce an index');
        $uniqueCount = count(array_filter($indexes, static fn (array $i): bool => RowCast::int($i['unique']) === 1));
        self::assertSame(3, $uniqueCount, 'exactly three unique indexes: stream identity + event id + global sequence (v2.23.0 backstops)');
    }

    public function testOutboxAndSnapshotTableDdlShape(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $outbox->createSchema();
        $columns = $conn->fetchAll(SqlQuery::raw('PRAGMA table_info("zef_outbox")'));
        $shape = array_map(static fn (array $c): string => RowCast::string($c['name']) . ':' . RowCast::string($c['type']), $columns);
        self::assertSame([
            'id:VARCHAR(64)',
            'message_type:VARCHAR(191)',
            'payload:TEXT',
            'metadata:TEXT',
            'status:VARCHAR(16)',
            'attempts:INT',
            'next_attempt_at:BIGINT',
            'last_error:TEXT',
            'created_at:BIGINT',
            // v2.31.0 lease claiming (nullable, both-or-neither):
            'lease_owner:VARCHAR(64)',
            'lease_until:BIGINT',
        ], $shape);
        $indexes = $conn->fetchAll(SqlQuery::raw('PRAGMA index_list("zef_outbox")'));
        $uniqueCount = count(array_filter($indexes, static fn (array $i): bool => RowCast::int($i['unique']) === 1));
        self::assertSame(1, $uniqueCount, 'exactly one unique index (the entry id)');

        $snapshots = new PdoSnapshotStore($conn, 'zef_snapshots');
        $snapshots->createSchema();
        $columns = $conn->fetchAll(SqlQuery::raw('PRAGMA table_info("zef_snapshots")'));
        $shape = array_map(static fn (array $c): string => RowCast::string($c['name']) . ':' . RowCast::string($c['type']), $columns);
        self::assertSame([
            'aggregate_type:VARCHAR(128)',
            'aggregate_id:VARCHAR(128)',
            'version:BIGINT',
            'state:TEXT',
            'created_at:BIGINT',
        ], $shape);
        $indexes = $conn->fetchAll(SqlQuery::raw('PRAGMA index_list("zef_snapshots")'));
        $uniqueCount = count(array_filter($indexes, static fn (array $i): bool => RowCast::int($i['unique']) === 1));
        self::assertSame(1, $uniqueCount, 'exactly one unique index (the aggregate identity)');
    }

    public function testPdoOutboxDueLimitOne(): void
    {
        $conn = $this->sqliteConn();
        $outbox = new PdoOutbox($conn, 'zef_outbox', static fn (): int => self::NANO);
        $outbox->createSchema();
        $outbox->enqueue('a', [], []);
        $outbox->enqueue('b', [], []);
        $page = $outbox->due(1, self::NANO);
        self::assertCount(1, $page);
        self::assertSame(2, $outbox->countPending());
    }

    /** @param list<string> $handles */
    private function projection(string $id, array $handles): ProjectionInterface
    {
        return new readonly class($id, $handles) implements ProjectionInterface {
            /** @param list<string> $handles */
            public function __construct(
                private string $id,
                private array $handles,
            ) {}

            public function projectionId(): string
            {
                return $this->id;
            }

            /** @return list<string> */
            public function handles(): array
            {
                return $this->handles;
            }

            public function handle(StoredEvent $event): void {}
        };
    }

    private function alwaysFailingBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($event instanceof OutboxMessage) {
                    throw new \RuntimeException('downstream unavailable');
                }

                return $event;
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 1));
            }

            /** @return iterable<object> */
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
    }

    /**
     * @param list<string> $received
     */
    private function passingBus(array &$received): EventBusInterface
    {
        return new class($received) implements EventBusInterface {
            /** @var list<string> */
            private array $received; // @phpstan-ignore-line written through the by-ref binding, read by the test // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /**
             * @param list<string> $received
             */
            public function __construct(array &$received)
            {
                $this->received = &$received;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($event instanceof OutboxMessage) {
                    $this->received[] = $event->entry->messageType;
                }

                return $event;
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 1));
            }

            /** @return iterable<object> */
            public function getListenersForEvent(object $event): iterable
            {
                return [];
            }
        };
    }

    // -------------------------------------------------- PDO adapters: transaction integrity via triggers

    private function sqliteConn(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }
}
