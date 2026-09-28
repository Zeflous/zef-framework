<?php

declare(strict_types=1);

/*
 * ZEF Framework — ZEF-DEEP-16 (issue #170) regression tests: snapshot
 * upsert interleaving (P-2), append-time unique-race mapping (P-3), job
 * queue column widths (P-4) and the seq-collision retry (P-5).
 *
 * Everything runs against in-memory SQLite with a fixed clock, and every
 * race is simulated deterministically through connection doubles (the
 * RaceLosingConnection precedent): the snapshot and append races via a
 * decorator committing the concurrent winner's row between the guard and
 * the write, and the seq collision via a double whose first INSERT fails
 * with the driver's own unique-violation error after the winner took the
 * slot.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\RowCast;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\PdoJobQueue;

/**
 * @internal
 */
final class Deep16EventSourcingJobQueueTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    // -------------------------------------------------- P-2: snapshot upsert race

    /**
     * Regresi P-2 (issue #170): a concurrent writer commits a NEWER
     * snapshot between the older writer's version guard and its
     * DELETE+INSERT. The older writer must lose that race silently and the
     * newer snapshot must survive — the version-bounded DELETE never
     * touches it and the INSERT's unique violation is recognised as the
     * lost race.
     */
    public function testSnapshotSaveLosingTheWriteRaceKeepsTheNewerSnapshot(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoSnapshotStore($conn, 'zef_snapshots_170');
        $store->createSchema();
        $store->save(new Snapshot('test.account', 'a-1', 3, ['state' => 'v3'], self::NANO));

        // The concurrent writer lands between the guard and the DELETE of
        // the older writer's save (v5): it replaces v3 with v7.
        $racing = new PdoSnapshotStore(new InterleavingConnection(
            $conn,
            'DELETE FROM "zef_snapshots_170"',
            static function () use ($conn): void {
                $conn->execute(SqlQuery::raw('DELETE FROM "zef_snapshots_170"'));
                $conn->execute(SqlQuery::raw(
                    'INSERT INTO "zef_snapshots_170" (aggregate_type, aggregate_id, version, state, created_at)'
                    . " VALUES ('test.account', 'a-1', 7, '{\"state\":\"v7\"}', 77)",
                ));
            },
        ), 'zef_snapshots_170');

        $racing->save(new Snapshot('test.account', 'a-1', 5, ['state' => 'v5'], self::NANO + 1));

        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(7, $loaded->version, 'the newer snapshot survives the older writer upsert');
        self::assertSame(['state' => 'v7'], $loaded->state);
        self::assertSame(77, $loaded->createdAtUnixNano);

        // The store stays healthy: a strictly newer save still lands.
        $racing->save(new Snapshot('test.account', 'a-1', 9, ['state' => 'v9'], self::NANO + 2));
        $loaded = $store->load('test.account', 'a-1');
        self::assertNotNull($loaded);
        self::assertSame(9, $loaded->version);
    }

    /**
     * The lost-race catch is narrow: an INSERT failure that is not a
     * unique violation must keep propagating as the connection's own
     * QueryException.
     */
    public function testSnapshotInsertFailureOutsideTheUniqueRacePropagates(): void
    {
        $conn = $this->sqliteConn();
        $store = new PdoSnapshotStore($conn, 'zef_snapshots_170boom');
        $store->createSchema();
        $conn->execute(SqlQuery::raw(
            "CREATE TRIGGER zef_170_boom BEFORE INSERT ON \"zef_snapshots_170boom\" WHEN NEW.version = 8
             BEGIN SELECT RAISE(ABORT, 'boom insert'); END",
        ));

        try {
            $store->save(new Snapshot('test.account', 'a-1', 8, ['state' => 'v8'], self::NANO));
            self::fail('a non-unique insert failure must propagate untouched');
        } catch (QueryException $e) {
            self::assertStringContainsString('boom insert', $e->getMessage());
        }
        self::assertNull($store->load('test.account', 'a-1'));
        self::assertSame(0, $conn->transactionLevel());
    }

    // -------------------------------------------------- P-3: append unique race

    /**
     * Regresi P-3 (issue #170): the version guard passes, but a concurrent
     * append has already committed the same stream version — the loser's
     * INSERT hits the unique index and must surface the port's
     * ConcurrencyException (with the re-selected actual version and the
     * driver error chained as the cause), never a raw QueryException.
     */
    public function testAppendLosingTheUniqueRaceSurfacesConcurrencyException(): void
    {
        $conn = $this->sqliteConn();
        // The concurrent winner: its row lands on the same connection between
        // the version guard and the INSERT (the decorator commits it just
        // before delegating the insert) — the guard passed against an empty
        // stream, the unique index still rejects the loser.
        $racing = new InterleavingConnection(
            $conn,
            'INSERT INTO "zef_events_170"',
            static function () use ($conn): void {
                $conn->execute(SqlQuery::raw(
                    'INSERT INTO "zef_events_170" (global_sequence, event_id, aggregate_type, aggregate_id, version, event_type, payload, metadata, recorded_at)'
                    . " VALUES (99, '" . str_repeat('c', 32) . "', 't', 'a', 1, 'race.won', '{}', '{}', 7)",
                ));
            },
        );
        $store = new PdoEventStore($racing, 'zef_events_170', static fn (): int => self::NANO);
        $store->createSchema();

        try {
            $store->appendToStream('t', 'a', 0, new PendingEvent('e'));
            self::fail('the lost insert race must surface the port ConcurrencyException');
        } catch (ConcurrencyException $e) {
            self::assertSame(0, $e->expectedVersion());
            self::assertSame(1, $e->actualVersion(), 'the re-selected stream version explains the conflict');
            self::assertInstanceOf(QueryException::class, $e->getPrevious(), 'the driver error stays chained as the cause');
        }
        self::assertSame([], $store->loadStream('t', 'a'), 'the losing append rolls back completely');
        self::assertSame(0, $conn->transactionLevel());

        // Catch-based retry logic keeps working after the conflict: the
        // loser wrote nothing, so a reload + append from version 0 lands.
        $created = $store->appendToStream('t', 'a', 0, new PendingEvent('e2'));
        self::assertSame(1, $created[0]->version);
        self::assertCount(1, $store->loadStream('t', 'a'));
    }

    // -------------------------------------------------- P-4: job queue widths

    /**
     * Regresi P-4 (issue #170): the column widths follow the domain
     * envelope contract — job_id/correlation_id carry
     * Identifier::OPAQUE_ID_PATTERN ids (8..128 bytes), trace_parent carries
     * Identifier::TRACEPARENT_PATTERN values (55 base chars + '-' + up to
     * 512 suffix bytes = 568).
     */
    public function testJobQueueSchemaWidthsFollowTheEnvelopeContract(): void
    {
        $conn = $this->sqliteConn();
        $queue = new PdoJobQueue($conn, 'zef_jobs_170ddl');
        $queue->createSchema();

        $ddl = RowCast::string($conn->fetchOne(SqlQuery::raw(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'zef_jobs_170ddl'",
        ))['sql'] ?? null);
        self::assertStringContainsString('"job_id" VARCHAR(128)', $ddl, 'job_id fits the opaque-id bound');
        self::assertStringContainsString('"correlation_id" VARCHAR(128)', $ddl, 'correlation_id fits the opaque-id bound');
        self::assertStringContainsString('"trace_parent" VARCHAR(568)', $ddl, 'trace_parent fits the traceparent bound');
        self::assertStringContainsString('CONSTRAINT "uq_zef_jobs_170ddl_job" UNIQUE ("job_id")', $ddl);
        self::assertStringContainsString('CONSTRAINT "uq_zef_jobs_170ddl_seq" UNIQUE ("seq")', $ddl);

        // The legacy-upgrade pass is part of createSchema(): re-running it
        // (IF NOT EXISTS / already-exists swallow) must stay a no-op.
        $queue->createSchema();
        $row = $conn->fetchOne(SqlQuery::raw(
            "SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'index' AND name = 'uq_zef_jobs_170ddl_seq'",
        ));
        self::assertSame(1, RowCast::int($row['aggregate'] ?? null), 'the seq backstop index exists exactly once');
    }

    /**
     * Regresi P-5 (issue #170): a table created by the pre-fix DDL (narrow
     * columns, no UNIQUE(seq)) is upgraded by createSchema() — the
     * PdoOutbox::createRelayIndex() best-effort pattern adds the unique
     * index, so the race backstop protects legacy deployments too, and a
     * duplicate seq insert now fails with the exact message shape
     * doEnqueue()'s retry matcher expects.
     */
    public function testJobQueueLegacyTableIsUpgradedWithTheSeqBackstop(): void
    {
        $conn = $this->sqliteConn();
        $queue = new PdoJobQueue($conn, 'zef_jobs_170legacy');
        // The pre-fix DDL: VARCHAR(64) columns, UNIQUE(job_id) only.
        $conn->execute(SqlQuery::raw(
            'CREATE TABLE "zef_jobs_170legacy" ('
            . '"seq" BIGINT NOT NULL, '
            . '"job_id" VARCHAR(64) NOT NULL, '
            . '"job_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"available_at" BIGINT NOT NULL, '
            . '"priority" INT NOT NULL, '
            . '"attempt" INT NOT NULL, '
            . '"correlation_id" VARCHAR(64) NULL, '
            . '"trace_parent" VARCHAR(64) NULL, '
            . '"headers" TEXT NOT NULL, '
            . 'CONSTRAINT "uq_zef_jobs_170legacy_job" UNIQUE ("job_id"))',
        ));
        $conn->execute(SqlQuery::raw(
            'INSERT INTO "zef_jobs_170legacy" (seq, job_id, job_type, payload, available_at, priority, attempt, correlation_id, trace_parent, headers) '
            . "VALUES (1, 'legacy-170job', 'mail.send', '{}', 0, 0, 1, NULL, NULL, '{}')",
        ));
        // Pre-upgrade the corruption is silent: a duplicate seq lands.
        $conn->execute(SqlQuery::raw(
            'INSERT INTO "zef_jobs_170legacy" (seq, job_id, job_type, payload, available_at, priority, attempt, correlation_id, trace_parent, headers) '
            . "VALUES (1, 'legacy-170dup', 'mail.send', '{}', 0, 0, 1, NULL, NULL, '{}')",
        ));
        $conn->execute(SqlQuery::raw('DELETE FROM "zef_jobs_170legacy" WHERE "job_id" = \'legacy-170dup\''));

        $queue->createSchema();

        $row = $conn->fetchOne(SqlQuery::raw(
            "SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'index' AND name = 'uq_zef_jobs_170legacy_seq'",
        ));
        self::assertSame(1, RowCast::int($row['aggregate'] ?? null), 'the upgrade added the seq backstop');

        try {
            $conn->execute(SqlQuery::raw(
                'INSERT INTO "zef_jobs_170legacy" (seq, job_id, job_type, payload, available_at, priority, attempt, correlation_id, trace_parent, headers) '
                . "VALUES (1, 'legacy-170race', 'mail.send', '{}', 0, 0, 1, NULL, NULL, '{}')",
            ));
            self::fail('the upgraded backstop must reject a double-assigned seq');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE constraint failed: zef_jobs_170legacy.seq', $e->getMessage());
        }

        // Enqueue keeps working past the legacy row: MAX+1 recomputes.
        $queue->enqueue($this->job('legacy-170next'));
        self::assertSame(2, $queue->size());
    }

    /**
     * The upgrade is honest about corruption: a legacy table that already
     * holds double-assigned seq values (the exact failure the backstop
     * exists to prevent) fails createSchema() loudly instead of being
     * silently accepted without the backstop.
     */
    public function testJobQueueCorruptedLegacySeqFailsTheUpgradeLoudly(): void
    {
        $conn = $this->sqliteConn();
        $queue = new PdoJobQueue($conn, 'zef_jobs_170corrupt');
        $conn->execute(SqlQuery::raw(
            'CREATE TABLE "zef_jobs_170corrupt" ("seq" BIGINT NOT NULL, "job_id" VARCHAR(64) NOT NULL)',
        ));
        $conn->execute(SqlQuery::raw(
            'INSERT INTO "zef_jobs_170corrupt" VALUES (1, \'corrupt-170a\'), (1, \'corrupt-170b\')',
        ));

        try {
            $queue->createSchema();
            self::fail('double-assigned legacy seq values must fail the backstop upgrade');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE constraint failed: zef_jobs_170corrupt.seq', $e->getMessage());
        }
    }

    /** Maximum-width envelope values enqueue and dequeue without loss. */
    public function testJobQueueAcceptsMaximumWidthEnvelopeValues(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_170wide');
        $queue->createSchema();

        $traceParent = '00-' . str_repeat('ab', 16) . '-' . str_repeat('cd', 8) . '-01-' . str_repeat('e', 512);
        self::assertSame(568, strlen($traceParent), 'the longest TRACEPARENT_PATTERN value');
        $queue->enqueue(new JobEnvelope(
            str_repeat('j', 128),
            'mail.send',
            ['n' => 1],
            self::NANO,
            0,
            1,
            str_repeat('c', 128),
            $traceParent,
        ));

        $dequeued = $queue->dequeue();
        self::assertNotNull($dequeued);
        self::assertSame(str_repeat('j', 128), $dequeued->jobId, 'a 128-byte job id round-trips');
        self::assertSame(str_repeat('c', 128), $dequeued->correlationId);
        self::assertSame($traceParent, $dequeued->traceParent);
        self::assertNull($queue->dequeue());
    }

    /**
     * A job id beyond the 128-byte bound is rejected by the domain's own
     * validation (Identifier::assertOpaqueId via JobEnvelope) before any
     * storage write — never truncated silently.
     */
    public function testJobQueueRejectsJobIdsBeyondTheColumnBound(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_170bound');
        $queue->createSchema();

        try {
            $queue->enqueue(new JobEnvelope(str_repeat('j', 129), 'mail.send', [], self::NANO));
            self::fail('a job id beyond the 128-byte bound must fail before storage');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid job ID.', $e->getMessage());
        }
        self::assertSame(0, $queue->size());
    }

    // -------------------------------------------------- P-5: seq collision retry

    /**
     * Regresi P-5 (issue #170): the enqueue computes MAX(seq)+1 that a
     * concurrent enqueue already took — UNIQUE(seq) rejects the loser,
     * whose bounded retry recomputes MAX+1 and lands on the next free slot.
     * The double commits the winner's row (the contested slot) through the
     * real connection and then fails the first INSERT exactly as the driver
     * would, so the retry's recomputation is observable in the table.
     */
    public function testJobQueueEnqueueRetriesASeqCollision(): void
    {
        $conn = $this->sqliteConn();
        $racing = new SeqCollisionConnection($conn, 'zef_jobs_170seq');
        $queue = new PdoJobQueue($racing, 'zef_jobs_170seq');
        $queue->createSchema();

        $queue->enqueue($this->job('raced-170job'));

        self::assertSame(2, $racing->insertAttempts, 'exactly one collision plus one successful retry');
        $row = $conn->fetchOne(SqlQuery::raw('SELECT "seq" FROM "zef_jobs_170seq" WHERE "job_id" = \'raced-170job\''));
        self::assertSame(2, RowCast::int($row['seq'] ?? null), 'the retry recomputed MAX(seq) + 1 past the winner');
        self::assertSame(2, $queue->size());
        $first = $queue->dequeue();
        self::assertNotNull($first);
        self::assertSame('winner-170job', $first->jobId, 'ordering stays deterministic: priority first');
        $second = $queue->dequeue();
        self::assertNotNull($second);
        self::assertSame('raced-170job', $second->jobId);
        self::assertNull($queue->dequeue());
    }

    /**
     * The retry is bounded: when every attempt collides (a pathological
     * racer — or a legacy table whose missing UNIQUE(seq) backstop still
     * bounces the insert), the connection's own QueryException escapes
     * after exactly five attempts and the enqueue transaction rolls back.
     */
    public function testJobQueueSeqRetryBudgetIsBounded(): void
    {
        $conn = $this->sqliteConn();
        $racing = new SeqCollisionConnection($conn, 'zef_jobs_170stuck', collideForever: true);
        $queue = new PdoJobQueue($racing, 'zef_jobs_170stuck');
        $queue->createSchema();

        try {
            $queue->enqueue($this->job('stuck-170job'));
            self::fail('a permanently contested seq must exhaust the retry budget');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE constraint failed', $e->getMessage(), 'the connection exception escapes unchanged');
        }
        self::assertSame(5, $racing->insertAttempts, 'the bounded budget is exactly five attempts');
        self::assertSame(0, $queue->size(), 'nothing lands when every attempt loses');
        self::assertSame(0, $conn->transactionLevel());
    }

    /**
     * The retry is narrow: a UNIQUE(job_id) collision is a caller bug and
     * must keep failing loudly after a single attempt.
     */
    public function testJobQueueDoesNotRetryADuplicateJobId(): void
    {
        $conn = $this->sqliteConn();
        $counted = new InterleavingConnection($conn, 'INSERT INTO "zef_jobs_170dup"');
        $queue = new PdoJobQueue($counted, 'zef_jobs_170dup');
        $queue->createSchema();
        $queue->enqueue($this->job('dup-170job'));

        $counted->matchingStatements = 0;

        try {
            $queue->enqueue($this->job('dup-170job'));
            self::fail('a duplicate job id must keep failing loudly');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
        self::assertSame(1, $counted->matchingStatements, 'a job_id collision is not a seq race: no retry');
        self::assertSame(1, $queue->size());
    }

    // -------------------------------------------------- helpers

    private function sqliteConn(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }

    private function job(string $id): JobEnvelope
    {
        return new JobEnvelope($id, 'mail.send', ['to' => 'user@example.com', 'n' => strlen($id)], self::NANO);
    }
}

/**
 * Connection decorator for the issue #170 race simulations: delegates
 * everything, counts execute() statements whose SQL contains the needle
 * and, on the first matching statement, runs the injected callback before
 * delegating — the "concurrent writer" landing between a guard and a
 * write. The callback executes on the inner connection directly, so it
 * never re-enters the decorator.
 *
 * @internal
 */
final class InterleavingConnection implements ConnectionInterface
{
    public int $matchingStatements = 0;

    /** @var null|(\Closure(): void) */
    private ?\Closure $interleave;

    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly string $needle,
        ?\Closure $interleave = null,
    ) {
        $this->interleave = $interleave;
    }

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        if (str_contains($query->sql, $this->needle)) {
            ++$this->matchingStatements;
            if ($this->interleave instanceof \Closure) {
                ($this->interleave)();
                $this->interleave = null;
            }
        }

        return $this->inner->execute($query);
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return $this->inner->fetchAll($query);
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->inner->fetchOne($query);
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->inner->beginTransaction($isolation);
    }

    #[\Override]
    public function commit(): void
    {
        $this->inner->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $this->inner->transaction($fn, $isolation);
    }
}

/**
 * Connection double for the P-5 race simulation: the first enqueue INSERT
 * against the queue table loses the seq race — the concurrent winner's row
 * (the contested slot) is committed through the real connection first, then
 * the driver's unique-violation error is thrown exactly as PdoConnection
 * would wrap it. Every later statement delegates untouched, so the bounded
 * retry in doEnqueue() runs against the real table. With collideForever the
 * double never yields: every INSERT loses, pinning the attempt budget.
 *
 * @internal
 */
final class SeqCollisionConnection implements ConnectionInterface
{
    public int $insertAttempts = 0;

    private bool $raced = false;

    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly string $queueTable,
        private readonly bool $collideForever = false,
    ) {}

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        if (!str_contains($query->sql, 'INSERT INTO "' . $this->queueTable . '"')) {
            return $this->inner->execute($query);
        }
        ++$this->insertAttempts;
        if ($this->raced) {
            return $this->inner->execute($query);
        }

        // The concurrent winner: it takes the slot this enqueue computed
        // (COALESCE(MAX("seq"), 0) + 1 on an empty queue = 1).
        if (!$this->collideForever) {
            $this->raced = true;
            $this->inner->execute(SqlQuery::raw(
                'INSERT INTO "' . $this->queueTable . '" (seq, job_id, job_type, payload, available_at, priority, attempt, correlation_id, trace_parent, headers) '
                . "VALUES (1, 'winner-170job', 'mail.send', '{}', 0, 5, 1, NULL, NULL, '{}')",
            ));
        }

        throw new QueryException(
            'Execution failed: UNIQUE constraint failed: ' . $this->queueTable . '.seq (sql: ' . $query->sql . ')',
            0,
            new \PDOException('UNIQUE constraint failed: ' . $this->queueTable . '.seq'),
        );
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return $this->inner->fetchAll($query);
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->inner->fetchOne($query);
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->inner->beginTransaction($isolation);
    }

    #[\Override]
    public function commit(): void
    {
        $this->inner->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $this->inner->transaction($fn, $isolation);
    }
}
