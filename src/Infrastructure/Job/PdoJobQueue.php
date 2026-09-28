<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: durable JobQueueInterface adapter over the Database Core port.
 */

namespace Zef\Framework\Job;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\SqlState;
use Zef\Framework\Validation\Identifier;

/**
 * PDO-backed {@see JobQueueInterface} built on the Database Core port —
 * the durable counterpart of Application/Job InMemoryJobQueue.
 *
 * Ordering: (priority DESC, available_at ASC, seq ASC). `seq` is
 * `MAX(seq) + 1` computed inside the enqueue INSERT (the same portable
 * pattern PdoEventStore uses for its global sequence). UNIQUE(job_id) is
 * the duplicate backstop — re-enqueueing the same job id fails loudly.
 * UNIQUE(seq) (Regresi P-5, issue #170) is the race backstop for the
 * MAX+1 computation: concurrent enqueues can no longer double-assign seq
 * and silently corrupt the ordering — the loser retries with a freshly
 * recomputed MAX+1 (bounded, see doEnqueue()).
 *
 * Claiming (dequeue): SELECT the head candidate, then DELETE it by
 * job_id inside the same transaction — rows===1 claims, rows===0 means a
 * concurrent worker won the race and the scan continues with the next
 * candidate (bounded, so a queue of continuously-racing rows still
 * terminates). DELETE-claiming is portable across SQLite/MySQL/PostgreSQL
 * (SELECT ... FOR UPDATE is not: SQLite rejects it).
 *
 * The two retry triggers are deliberately distinguished: an empty SELECT
 * proves the queue has nothing to claim at all, so the scan stops
 * immediately (one transaction, not MAX_CLAIM_ATTEMPTS); only a lost
 * DELETE race — a candidate that existed a statement ago — justifies
 * re-scanning.
 *
 * Payloads travel as JSON documents (mixed round-trip: scalars, lists,
 * string-keyed maps) — object payloads must be serialised by the caller,
 * mirroring the outbox contract. Ambient transactions are joined, so a
 * domain transaction can enqueue jobs atomically with its writes.
 */
final readonly class PdoJobQueue implements JobQueueInterface
{
    private const int MAX_CLAIM_ATTEMPTS = 8;

    /** Bounded seq-collision retries for {@see doEnqueue()} (Regresi P-5, issue #170). */
    private const int MAX_SEQ_ATTEMPTS = 5;

    private string $table;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port
     * @param string                 $table      queue table name
     * @param null|(\Closure(): int) $clock      availableAt/now source (default: realtime nanoseconds)
     * @param null|int               $maxSize    optional capacity guard (COUNT check per enqueue)
     */
    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_job_queue',
        ?\Closure $clock = null,
        private ?int $maxSize = null,
    ) {
        new QueryBuilder()->quoteIdentifier($table, 'table');
        if ($maxSize !== null && $maxSize < 1) {
            throw new \InvalidArgumentException('Job queue capacity must be positive.');
        }
        $this->table = $table;
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Create the queue table (portable DDL, safe to run repeatedly).
     *
     * Column widths follow the domain envelope contract (Regresi P-4,
     * issue #170): job_id and correlation_id carry
     * Identifier::OPAQUE_ID_PATTERN ids (8..128 bytes) and trace_parent
     * carries Identifier::TRACEPARENT_PATTERN values (55 base chars + '-'
     * + up to 512 suffix bytes = 568). The previous 64-byte widths
     * silently truncated — or rejected, under strict MySQL — every
     * long-but-legal value the envelope had already accepted. SQLite is
     * type-lenient and cannot ALTER column widths at all, and widening on
     * MySQL/PostgreSQL needs driver-specific MODIFY/TYPE syntax, so legacy
     * tables must be recreated to gain the wider columns — best documented
     * here instead of half-upgraded per driver.
     *
     * UNIQUE(seq) (Regresi P-5, issue #170) is the concurrency backstop
     * that turns a double-assigned MAX(seq)+1 into a retryable unique
     * violation (see doEnqueue()). CREATE TABLE IF NOT EXISTS does not
     * alter existing tables, so {@see ensureSeqUniqueIndex()} upgrades
     * legacy tables with the PdoOutbox::createRelayIndex() pattern (best
     * effort CREATE UNIQUE INDEX with an already-exists swallow). A
     * legacy table that already contains double-assigned seq values —
     * the exact corruption the backstop exists to prevent — fails that
     * upgrade loudly instead of being silently accepted.
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ('
            . '"seq" BIGINT NOT NULL, '
            . '"job_id" VARCHAR(128) NOT NULL, '
            . '"job_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"available_at" BIGINT NOT NULL, '
            . '"priority" INT NOT NULL, '
            . '"attempt" INT NOT NULL, '
            . '"correlation_id" VARCHAR(128) NULL, '
            . '"trace_parent" VARCHAR(568) NULL, '
            . '"headers" TEXT NOT NULL, '
            . 'CONSTRAINT "uq_' . $this->table . '_job" UNIQUE ("job_id"), '
            . 'CONSTRAINT "uq_' . $this->table . '_seq" UNIQUE ("seq"))',
        ));
        $this->ensureSeqUniqueIndex();
    }

    #[\Override]
    public function enqueue(JobEnvelope $job): void
    {
        // Regresi P-4 (issue #170): re-assert the domain's job-id grammar at
        // the storage boundary — Identifier::assertOpaqueId caps ids at 128
        // bytes, exactly the job_id column width, so a value that ever slips
        // past the envelope's own validation fails loudly here instead of
        // being silently truncated by a narrow column.
        Identifier::assertOpaqueId($job->jobId, 'job ID');
        $payload = $this->encodePayload($job->payload);
        $headers = $this->encodePayload($job->headers);
        if ($this->maxSize !== null && $this->size() >= $this->maxSize) {
            throw new \OverflowException('Job queue capacity exceeded.');
        }

        $own = $this->connection->transactionLevel() === 0;
        if (!$own) {
            // Ambient transaction (e.g. aggregate persist + outbox-style enqueue).
            $this->doEnqueue($job, $payload, $headers);

            return;
        }
        $this->connection->beginTransaction();

        try {
            $this->doEnqueue($job, $payload, $headers);
        } catch (\Throwable $error) {
            $this->connection->rollBack();

            throw $error;
        }
        $this->connection->commit();
    }

    #[\Override]
    public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
    {
        $now = $nowUnixNano ?? ($this->clock)();
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; ++$attempt) {
            // false = the SELECT proved there is no candidate (stop retrying);
            // null = a candidate existed but the DELETE lost the race (rescan);
            // JobEnvelope = claimed.
            $claimed = $this->connection->transaction(function () use ($now): false|JobEnvelope|null {
                $rows = $this->connection->fetchAll(
                    QueryBuilder::table($this->table)
                        ->select('seq', 'job_id', 'job_type', 'payload', 'available_at', 'priority', 'attempt', 'correlation_id', 'trace_parent', 'headers')
                        ->where('available_at', '<=', $now)
                        ->orderBy('priority', 'DESC')
                        ->orderBy('available_at', 'ASC')
                        ->orderBy('seq', 'ASC')
                        ->limit(1)
                        ->build(),
                );
                $row = $rows[0] ?? null;
                if ($row === null) {
                    return false;
                }
                $deleted = $this->connection->execute(
                    QueryBuilder::table($this->table)
                        ->delete()
                        ->where('job_id', '=', $this->str($row['job_id'] ?? null))
                        ->build(),
                );

                return $deleted === 1 ? $this->hydrate($row) : null;
            });
            if ($claimed instanceof JobEnvelope) {
                return $claimed;
            }
            if ($claimed === false) {
                return null;
            }
        }

        return null;
    }

    #[\Override]
    public function size(): int
    {
        $row = $this->connection->fetchOne(new SqlQuery(
            'SELECT COUNT(*) AS "aggregate" FROM "' . $this->table . '"',
        ));
        if ($row === null || !isset($row['aggregate']) || !is_numeric($row['aggregate'])) {
            throw new QueryException('Job queue size query returned an unexpected result.');
        }

        return (int) $row['aggregate'];
    }

    private function doEnqueue(JobEnvelope $job, string $payload, string $headers): void
    {
        $insert = new SqlQuery(
            'INSERT INTO "' . $this->table . '" ('
            . '"seq", "job_id", "job_type", "payload", "available_at", "priority", "attempt", "correlation_id", "trace_parent", "headers"'
            . ') SELECT COALESCE(MAX("seq"), 0) + 1, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM "' . $this->table . '"',
            [
                $job->jobId,
                $job->jobType,
                $payload,
                $job->availableAtUnixNano,
                $job->priority,
                $job->attempt,
                $job->correlationId,
                $job->traceParent,
                $headers,
            ],
        );

        // Regresi P-5 (issue #170): two concurrent enqueues can compute the
        // same COALESCE(MAX("seq"), 0) + 1 — the UNIQUE(seq) backstop in
        // createSchema() rejects the loser, whose INSERT recomputes MAX+1
        // by construction, so a bounded retry lands on the next free slot.
        // Only seq collisions are retried: a UNIQUE(job_id) hit is a caller
        // bug that must keep failing loudly, and after the attempt budget
        // the connection's QueryException escapes unchanged.
        for ($attempt = 1; $attempt <= self::MAX_SEQ_ATTEMPTS; ++$attempt) {
            try {
                $this->connection->execute($insert);

                return;
            } catch (QueryException $error) {
                if ($attempt === self::MAX_SEQ_ATTEMPTS || !$this->isSeqCollision($error)) {
                    throw $error;
                }
            }
        }
    }

    /**
     * Unique violation on the seq backstop only: SQLite reports
     * "UNIQUE constraint failed: <table>.seq" while MySQL/PostgreSQL name
     * the constraint ("... for key 'uq_<table>_seq'" / "duplicate key value
     * violates unique constraint \"uq_<table>_seq\"") — a job_id collision
     * never matches, so it stays a loud failure.
     */
    private function isSeqCollision(QueryException $error): bool
    {
        if (!SqlState::isUniqueViolation($error)) {
            return false;
        }
        $message = $error->getMessage();

        return str_contains($message, $this->table . '.seq')
            || str_contains($message, 'uq_' . $this->table . '_seq');
    }

    /**
     * Best-effort UNIQUE(seq) upgrade for tables created before the
     * constraint joined the DDL — the PdoOutbox::createRelayIndex()
     * pattern. Tried with IF NOT EXISTS first (sqlite/pgsql); MySQL
     * rejects that syntax, so on a syntax error it falls back to a plain
     * CREATE UNIQUE INDEX and swallows the duplicate-name error on
     * re-runs. Only name-level duplicates (sqlite/pgsql "already exists",
     * MySQL "Duplicate key name") are swallowed: a MySQL "Duplicate
     * entry" (or any other failure) means the legacy table already holds
     * double-assigned seq values — the corruption P-5 exists to prevent —
     * and must keep failing loudly. On fresh tables the DDL constraint
     * already carries the backstop, so this at most adds a redundant
     * second index (sqlite names table-constraint indexes
     * sqlite_autoindex_*, so the name probe cannot see them).
     */
    private function ensureSeqUniqueIndex(): void
    {
        $index = new QueryBuilder()->quoteIdentifier('uq_' . $this->table . '_seq', 'index');
        $columns = ' ON "' . $this->table . '" ("seq")';

        try {
            $this->connection->execute(SqlQuery::raw(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . $index . $columns,
            ));

            return;
        } catch (QueryException) {
            // Unsupported syntax (e.g. MySQL) — retry plain.
        }

        try {
            $this->connection->execute(SqlQuery::raw(
                'CREATE UNIQUE INDEX ' . $index . $columns,
            ));
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'already exists') && !str_contains($message, 'duplicate key name')) {
                throw $e;
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): JobEnvelope
    {
        return new JobEnvelope(
            $this->str($row['job_id'] ?? null),
            $this->str($row['job_type'] ?? null),
            $this->decodePayload($this->str($row['payload'] ?? null)),
            $this->intVal($row['available_at'] ?? null),
            $this->intVal($row['priority'] ?? null),
            $this->intVal($row['attempt'] ?? null),
            $row['correlation_id'] === null ? null : $this->str($row['correlation_id']),
            $row['trace_parent'] === null ? null : $this->str($row['trace_parent']),
            $this->decodeHeaders($this->str($row['headers'] ?? null)),
        );
    }

    /** Row narrowing: PDO rows are array<string, mixed>; ids are strings. */
    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /** Row narrowing: numeric columns (int on SQLite, string on MySQL PDO). */
    private function intVal(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function encodePayload(mixed $payload): string
    {
        try {
            return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Job payload must be JSON-serializable.', 0, $error);
        }
    }

    private function decodePayload(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw JobExecutionException::corruptPayload($error);
        }
    }

    /**
     * Header round-trip narrowing: entries that lost their string type in
     * storage cannot satisfy the envelope contract and are dropped.
     *
     * @return array<string, string>
     */
    private function decodeHeaders(string $payload): array
    {
        $decoded = $this->decodePayload($payload);
        $headers = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
