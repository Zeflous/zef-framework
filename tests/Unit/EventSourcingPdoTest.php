<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxRecorder;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoOutbox;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\Projector;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\SnapshotStoreInterface;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.19.0 — Event Sourcing PDO adapters against real SQLite in-memory:
 * schema, append/load/stream, concurrency rollback, snapshot upsert,
 * outbox lifecycle, atomic persist (events + outbox on one connection).
 *
 * @internal
 */
final class EventSourcingPdoTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    private PdoConnection $conn;

    private PdoEventStore $store;

    private PdoSnapshotStore $snapshots;

    private PdoOutbox $outbox;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
        $this->store = new PdoEventStore($this->conn, 'zef_events', static fn (): int => self::NANO);
        $this->snapshots = new PdoSnapshotStore($this->conn, 'zef_snapshots');
        $this->outbox = new PdoOutbox($this->conn, 'zef_outbox', static fn (): int => self::NANO);
        $this->store->createSchema();
        $this->snapshots->createSchema();
        $this->outbox->createSchema();
    }

    // -------------------------------------------------- schema

    public function testCreateSchemaIsIdempotent(): void
    {
        $this->store->createSchema();
        $this->snapshots->createSchema();
        $this->outbox->createSchema();
        self::addToAssertionCount(1); // repeated createSchema must not throw
    }

    public function testConstructorRejectsBadTableName(): void
    {
        try {
            new PdoEventStore($this->conn, 'bad-table-name!');
            self::fail('bad table name must fail');
        } catch (QueryException $e) {
            self::assertStringContainsString('Invalid table', $e->getMessage());
        }

        try {
            new PdoOutbox($this->conn, str_repeat('t', 65));
            self::fail('oversized table name must fail');
        } catch (QueryException $e) {
            self::assertStringContainsString('64-byte limit', $e->getMessage());
        }
    }

    // -------------------------------------------------- append / load / stream

    public function testAppendLoadRoundTripWithJsonPayloads(): void
    {
        $created = $this->store->appendToStream(
            'bank.account',
            'acc-1',
            0,
            $this->pending('account.opened', ['initial' => 100, 'meta' => ['nested' => [1, 2.5, 'ünïcode', null]]]),
            $this->pending('account.deposited', ['amount' => 25]),
        );
        self::assertSame(self::NANO, $created[0]->recordedAtUnixNano);
        self::assertSame(1, $created[0]->globalSequence);
        self::assertSame(2, $created[1]->globalSequence);

        $loaded = $this->store->loadStream('bank.account', 'acc-1');
        self::assertCount(2, $loaded);
        self::assertInstanceOf(StoredEvent::class, $loaded[0]);
        self::assertSame(['initial' => 100, 'meta' => ['nested' => [1, 2.5, 'ünïcode', null]]], $loaded[0]->payload);
        self::assertSame(['amount' => 25], $loaded[1]->payload);
        self::assertSame([], $loaded[0]->metadata);
        self::assertSame('bank.account', $loaded[0]->aggregateType);
        self::assertSame('acc-1', $loaded[0]->aggregateId);

        // Second stream, then store-wide timeline in global-sequence order.
        $this->store->appendToStream('bank.account', 'acc-2', 0, $this->pending('account.opened', []));
        $all = $this->store->streamAll();
        self::assertCount(3, $all);
        self::assertSame(1, $all[0]->globalSequence);
        self::assertSame(3, $all[2]->globalSequence);
        self::assertSame('acc-2', $all[2]->aggregateId);
        $page = $this->store->streamAll(2, 1);
        self::assertCount(1, $page);
        self::assertSame(2, $page[0]->globalSequence);
    }

    public function testAppendValidationAndConcurrency(): void
    {
        try {
            $this->store->appendToStream('bad type', 'a', 0, $this->pending('e'));
            self::fail('bad aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            $this->store->appendToStream('t', 'a', -1, $this->pending('e'));
            self::fail('negative expected version must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Expected version must be >= 0 (got -1).', $e->getMessage());
        }

        try {
            $this->store->appendToStream('t', 'a', 0);
            self::fail('empty append must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('appendToStream() requires at least one event.', $e->getMessage());
        }

        $this->store->appendToStream('t', 'a', 0, $this->pending('e1'));

        try {
            $this->store->appendToStream('t', 'a', 5, $this->pending('e2'));
            self::fail('future expected version must fail');
        } catch (ConcurrencyException $e) {
            self::assertSame(5, $e->expectedVersion());
            self::assertSame(1, $e->actualVersion());
        }
        // Nothing persisted by the failed append.
        self::assertSame([], $this->store->streamAll(2));
    }

    public function testAppendTransactionIsRolledBackOnError(): void
    {
        // Stream-level uniqueness backstop: two events claiming version 1
        // for the same aggregate → second append violates the constraint
        // inside the transaction and the first row must be rolled back.
        $this->store->appendToStream('t', 'a', 0, $this->pending('e1'));
        $this->conn->beginTransaction();

        try {
            $this->conn->execute(SqlQuery::raw(
                "INSERT INTO zef_events (global_sequence, event_id, aggregate_type, aggregate_id, version, event_type, payload, metadata, recorded_at)
                 VALUES (99, 'deadbeefdeadbeefdeadbeefdeadbeef', 't', 'a', 1, 'dup', '{}', '{}', 0)",
            ));
            self::fail('duplicate (type,id,version) must violate the unique index');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }
        $this->conn->rollBack();
        self::assertCount(1, $this->store->loadStream('t', 'a'));
    }

    public function testStreamAllAndLoadValidation(): void
    {
        try {
            $this->store->streamAll(0);
            self::fail('from 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('fromGlobalSequence must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            $this->store->streamAll(1, 0);
            self::fail('limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('limit must be >= 1 when provided (got 0).', $e->getMessage());
        }
        self::assertSame([], $this->store->loadStream('missing-type', 'no-id'));
    }

    public function testCorruptJsonRowIsRejected(): void
    {
        $this->store->appendToStream('t', 'a', 0, $this->pending('e1'));
        $this->conn->execute(SqlQuery::raw(
            "UPDATE zef_events SET payload = '{broken' WHERE aggregate_id = 'a'",
        ));

        try {
            $this->store->loadStream('t', 'a');
            self::fail('corrupt payload must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt stored event payload', $e->getMessage());
        }
        $this->conn->execute(SqlQuery::raw(
            "UPDATE zef_events SET payload = '[]', metadata = 'nope' WHERE aggregate_id = 'a'",
        ));

        try {
            $this->store->streamAll();
            self::fail('corrupt metadata must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt stored event metadata', $e->getMessage());
        }
    }

    // -------------------------------------------------- snapshots

    public function testSnapshotUpsertLoadDelete(): void
    {
        self::assertNull($this->snapshots->load('t', 'a'));
        self::assertFalse($this->snapshots->delete('t', 'a'));

        $this->snapshots->save(new Snapshot('t', 'a', 5, ['v' => 5, 'deep' => ['x' => 1]], self::NANO));
        $this->snapshots->save(new Snapshot('t', 'a', 9, ['v' => 9], self::NANO + 1));
        $loaded = $this->snapshots->load('t', 'a');
        self::assertNotNull($loaded);
        self::assertSame(9, $loaded->version, 'latest snapshot wins');
        self::assertSame(['v' => 9], $loaded->state);
        self::assertSame(self::NANO + 1, $loaded->createdAtUnixNano);
        self::assertTrue($this->snapshots->delete('t', 'a'));
        self::assertNull($this->snapshots->load('t', 'a'));

        try {
            $this->snapshots->load('bad type', 'a');
            self::fail('bad type grammar must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            $this->snapshots->delete('t', "bad\nid");
            self::fail('bad id grammar must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    // -------------------------------------------------- outbox

    public function testOutboxLifecycleOnPdo(): void
    {
        $entry = $this->outbox->enqueue('order.placed', ['sku' => 'X'], ['meta' => 1]);
        self::assertSame(1, $this->outbox->countPending());
        self::assertSame(0, $this->outbox->due(10, PHP_INT_MAX)[0]->attempts);

        try {
            $this->outbox->markProcessed('missing-id');
            self::fail('unknown entry must fail');
        } catch (EventSourcingException $e) {
            self::assertSame("Unknown outbox entry 'missing-id'.", $e->getMessage());
        }

        try {
            $this->outbox->markFailed($entry->id, 'boom', -1);
            self::fail('negative retry timestamp must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('retryAtUnixNano must be >= 0', $e->getMessage());
        }

        $this->outbox->markFailed($entry->id, 'boom', self::NANO + 100);
        self::assertSame([], $this->outbox->due(10, self::NANO), 'not due before retry timestamp');
        $due = $this->outbox->due(10, self::NANO + 100);
        self::assertCount(1, $due);
        self::assertSame(1, $due[0]->attempts);
        self::assertSame('boom', $due[0]->lastError);
        self::assertSame(self::NANO + 100, $due[0]->nextAttemptAtUnixNano);
        self::assertSame(1, $this->outbox->countPending(), 'failed entries stay pending');

        $this->outbox->markDead($entry->id, 'final boom');
        self::assertSame(0, $this->outbox->countPending());
        self::assertSame([], $this->outbox->due(10, PHP_INT_MAX));
        $dead = $this->outbox->failed(10);
        self::assertCount(1, $dead);
        self::assertSame('final boom', $dead[0]->lastError);
        self::assertSame(2, $dead[0]->attempts, 'markDead also bumps attempts');
    }

    public function testOutboxDueTieBreakOrderingIsDeterministic(): void
    {
        // Same createdAt (fixed clock): (createdAt, id) ordering → id order.
        $this->outbox->enqueue('m1', [], []);
        $this->outbox->enqueue('m2', [], []);
        $this->outbox->enqueue('m3', [], []);
        $ids = [];
        foreach ($this->outbox->due(10, self::NANO) as $entry) {
            $ids[] = $entry->id;
        }
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
    }

    public function testOutboxCountPendingAggregateGuard(): void
    {
        self::assertSame(0, $this->outbox->countPending());
        $this->outbox->enqueue('m', [], []);
        $this->outbox->enqueue('m', [], []);
        self::assertSame(2, $this->outbox->countPending());
    }

    public function testOutboxEnqueuePayloadRoundTrip(): void
    {
        $entry = $this->outbox->enqueue('m', ['deep' => ['list' => [1, 'two', 3.5, null, false]]], ['k' => 'v']);
        $loaded = $this->outbox->due(10, PHP_INT_MAX)[0];
        self::assertSame($entry->id, $loaded->id);
        self::assertSame(['deep' => ['list' => [1, 'two', 3.5, null, false]]], $loaded->payload);
        self::assertSame(['k' => 'v'], $loaded->metadata);
        self::assertSame(OutboxEntry::STATUS_PENDING, $loaded->status);
        self::assertNull($loaded->lastError);
    }

    // -------------------------------------------------- atomic persist

    public function testRepositoryPersistIsAtomicOnSharedConnection(): void
    {
        $repo = new AggregateRepository(
            store: $this->store,
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );

        $aggregate = EventSourcingTestAccount::open('acc-1', 10);
        $stored = $repo->persist($aggregate);
        self::assertCount(1, $stored);
        self::assertSame(1, $this->outbox->countPending(), 'outbox entries committed with events');
        self::assertSame(0, $this->conn->transactionLevel(), 'no transaction left open');

        $loaded = $repo->find(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $loaded);
        self::assertSame(10, $loaded->balance());
    }

    public function testRepositoryFailureRollsBackEventsAndOutbox(): void
    {
        $repo = new AggregateRepository(
            store: $this->store,
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );
        $first = EventSourcingTestAccount::open('acc-1', 1);
        $repo->persist($first);

        // Stale aggregate → ConcurrencyException inside the transaction.
        $stale = EventSourcingTestAccount::open('acc-1', 1);

        try {
            $repo->persist($stale);
            self::fail('stale persist must fail');
        } catch (ConcurrencyException $e) {
            self::assertSame(1, $e->actualVersion());
        }
        self::assertSame(1, $this->outbox->countPending(), 'no outbox rows from the rolled-back append');
        self::assertCount(1, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(0, $this->conn->transactionLevel(), 'transaction closed after rollback');
    }

    public function testSnapshotFailurePreservesPendingEventsForRetry(): void
    {
        $snapshots = $this->createMock(SnapshotStoreInterface::class);
        $snapshots->expects(self::exactly(2))->method('save')->willReturnCallback(function (Snapshot $snapshot): void {
            $this->snapshots->save($snapshot);
            if ($this->snapshots->load('test.account', 'acc-1')?->version === 2) {
                throw new \RuntimeException('synthetic snapshot failure');
            }
        });
        $repo = new AggregateRepository(
            store: $this->store,
            snapshots: $snapshots,
            policy: SnapshotPolicy::every(1),
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );
        $aggregate = EventSourcingTestAccount::open('acc-1', 10);
        $repo->persist($aggregate);
        $aggregate->deposit(5);
        $pending = $aggregate->pendingEvents();

        try {
            $repo->persist($aggregate);
            self::fail('snapshot failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic snapshot failure', $e->getMessage());
        }
        self::assertSame($pending, $aggregate->pendingEvents());
        self::assertSame(1, $aggregate->pendingVersion());
        self::assertSame(2, $aggregate->version());
        self::assertSame(15, $aggregate->balance());
        self::assertCount(1, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(1, $this->outbox->countPending());
        self::assertSame(1, $this->snapshots->load('test.account', 'acc-1')?->version);
        self::assertSame(0, $this->conn->transactionLevel());

        $healthy = new AggregateRepository(
            store: $this->store,
            snapshots: $this->snapshots,
            policy: SnapshotPolicy::every(1),
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );
        self::assertCount(1, $healthy->persist($aggregate));
        self::assertSame([], $aggregate->pendingEvents());
        self::assertSame(2, $aggregate->pendingVersion());
        self::assertCount(2, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(2, $this->outbox->countPending());
        self::assertSame(2, $this->snapshots->load('test.account', 'acc-1')?->version);
        self::assertSame([], $healthy->persist($aggregate));
    }

    public function testOutboxFailurePreservesPendingEventsForRetry(): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TRIGGER fail_outbox BEFORE INSERT ON zef_outbox '
            . "WHEN (SELECT COUNT(*) FROM zef_outbox) = 1 BEGIN SELECT RAISE(ABORT, 'synthetic outbox failure'); END",
        ));
        $repo = new AggregateRepository(
            store: $this->store,
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );
        $aggregate = EventSourcingTestAccount::open('acc-1', 10);
        $aggregate->deposit(5);
        $pending = $aggregate->pendingEvents();

        try {
            $repo->persist($aggregate);
            self::fail('outbox failure must propagate');
        } catch (QueryException $e) {
            self::assertStringContainsString('synthetic outbox failure', $e->getMessage());
        }
        self::assertSame($pending, $aggregate->pendingEvents());
        self::assertSame(0, $aggregate->pendingVersion());
        self::assertSame([], $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(0, $this->outbox->countPending());
        self::assertSame(0, $this->conn->transactionLevel());

        $this->conn->execute(SqlQuery::raw('DROP TRIGGER fail_outbox'));
        self::assertCount(2, $repo->persist($aggregate));
        self::assertSame([], $aggregate->pendingEvents());
        self::assertCount(2, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(2, $this->outbox->countPending());
    }

    public function testCommitFailurePreservesPendingEventsAndRollsBackForRetry(): void
    {
        // Deferred constraints fail at the real PDO commit, after all stores succeeded.
        $this->conn->execute(SqlQuery::raw('PRAGMA foreign_keys = ON'));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE commit_parent (id INTEGER PRIMARY KEY)'));
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE commit_child (parent_id INTEGER REFERENCES commit_parent(id) DEFERRABLE INITIALLY DEFERRED)',
        ));
        $this->conn->execute(SqlQuery::raw(
            'CREATE TRIGGER fail_commit AFTER INSERT ON zef_snapshots BEGIN INSERT INTO commit_child VALUES (1); END',
        ));
        $repo = new AggregateRepository(
            store: $this->store,
            snapshots: $this->snapshots,
            policy: SnapshotPolicy::every(1),
            outbox: new OutboxRecorder($this->outbox, $this->conn),
            connection: $this->conn,
        );
        $aggregate = EventSourcingTestAccount::open('acc-1', 10);
        $pending = $aggregate->pendingEvents();

        try {
            $repo->persist($aggregate);
            self::fail('commit failure must propagate');
        } catch (TransactionException $e) {
            self::assertStringContainsString('Failed to commit transaction', $e->getMessage());
        }
        self::assertSame($pending, $aggregate->pendingEvents());
        self::assertSame(0, $aggregate->pendingVersion());
        self::assertSame(0, $this->conn->transactionLevel());
        self::assertSame([], $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(0, $this->outbox->countPending());
        self::assertNull($this->snapshots->load('test.account', 'acc-1'));
        self::assertSame([], $this->conn->fetchAll(SqlQuery::raw('SELECT * FROM commit_child')));

        $this->conn->execute(SqlQuery::raw('DROP TRIGGER fail_commit'));
        self::assertCount(1, $repo->persist($aggregate));
        self::assertSame([], $aggregate->pendingEvents());
        self::assertSame(1, $aggregate->pendingVersion());
        self::assertCount(1, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame(1, $this->outbox->countPending());
        self::assertSame(1, $this->snapshots->load('test.account', 'acc-1')?->version);
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testRepositoryRejectsAmbientTransactionBeforeWriting(): void
    {
        $repo = new AggregateRepository(store: $this->store, connection: $this->conn);
        $aggregate = EventSourcingTestAccount::open('acc-1', 10);
        $pending = $aggregate->pendingEvents();
        $this->conn->beginTransaction();

        try {
            $repo->persist($aggregate);
            self::fail('ambient transactions must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('outermost transaction', $e->getMessage());
        } finally {
            self::assertSame(1, $this->conn->transactionLevel());
            self::assertSame([], $this->store->loadStream('test.account', 'acc-1'));
            $this->conn->rollBack();
        }
        self::assertSame($pending, $aggregate->pendingEvents());
        self::assertCount(1, $repo->persist($aggregate));
        self::assertSame([], $aggregate->pendingEvents());
    }

    public function testPersistWithoutPendingEventsDoesNotOpenATransaction(): void
    {
        $repo = new AggregateRepository(store: $this->store, connection: $this->conn);
        $aggregate = EventSourcingTestAccount::createEmpty('acc-1');
        $this->conn->beginTransaction();

        try {
            self::assertSame([], $repo->persist($aggregate));
            self::assertSame(1, $this->conn->transactionLevel());
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testWithoutSharedTransactionAppendRemainsCommittedAfterSnapshotFailure(): void
    {
        $snapshots = $this->createMock(SnapshotStoreInterface::class);
        $snapshots->method('save')->willThrowException(new \RuntimeException('synthetic snapshot failure'));
        $repo = new AggregateRepository($this->store, $snapshots, SnapshotPolicy::every(1));
        $aggregate = EventSourcingTestAccount::open('acc-1', 10);

        try {
            $repo->persist($aggregate);
            self::fail('snapshot failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic snapshot failure', $e->getMessage());
        }
        self::assertSame([], $aggregate->pendingEvents());
        self::assertCount(1, $this->store->loadStream('test.account', 'acc-1'));
        self::assertSame([], $repo->persist($aggregate));
    }

    public function testSnapshotPolicyWritesThroughRepository(): void
    {
        $repo = new AggregateRepository(
            store: $this->store,
            snapshots: $this->snapshots,
            policy: SnapshotPolicy::every(2),
            connection: $this->conn,
            clock: static fn (): int => self::NANO,
        );
        $aggregate = EventSourcingTestAccount::open('acc-1', 1);
        $aggregate->deposit(1); // version 2 → snapshot
        $repo->persist($aggregate);
        $loaded = $this->snapshots->load('test.account', 'acc-1');
        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->version);

        // Reload flows through the snapshot + replay path on the same store.
        $again = $repo->findOrFail(EventSourcingTestAccount::class, 'acc-1');
        self::assertInstanceOf(EventSourcingTestAccount::class, $again);
        self::assertSame(2, $again->balance());
    }

    // -------------------------------------------------- projector on pdo store

    public function testProjectorConsumesPdoTimeline(): void
    {
        $this->store->appendToStream('t', 'a', 0, $this->pending('e1'), $this->pending('e2'), $this->pending('e1'));
        $seen = [];
        $projection = new class($seen) implements ProjectionInterface {
            /** @var list<int> */
            private array $seen; // @phpstan-ignore-line written through the by-ref binding, read by the test // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- by-ref binding requires an untyped property

            /**
             * @param list<int> $seen
             */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function projectionId(): string
            {
                return 'pdo-proj';
            }

            public function handles(): array
            {
                return ['e1'];
            }

            public function handle(StoredEvent $event): void
            {
                $this->seen[] = $event->globalSequence;
            }
        };
        $projector = new Projector($this->store, new InMemoryCheckpointStore(), [$projection]);
        self::assertSame(2, $projector->run());
        self::assertSame([1, 3], $seen);
        self::assertSame(0, $projector->run());
    }

    /**
     * @param array<mixed> $payload
     */
    private function pending(string $type, array $payload = []): PendingEvent
    {
        return new PendingEvent($type, $payload);
    }
}
