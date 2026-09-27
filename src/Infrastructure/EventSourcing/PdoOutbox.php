<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlExpression;
use Zef\Framework\Database\SqlQuery;

/**
 * PDO-backed {@see OutboxStoreInterface} built on the Database Core port.
 *
 * - `due()` selects pending entries whose retry timestamp has passed,
 *   FIFO by (created_at, id) — deterministic delivery order;
 * - `markFailed()` bumps attempts with `attempts + 1` expressed as a raw
 *   {@see SqlExpression} (read-then-write would race under concurrent relays);
 * - shares the ambient-transaction contract with PdoEventStore: enqueue
 *   joins an open transaction, so repository.persist commits events and
 * outbox entries atomically over one connection.
 *
 * Lease claiming (v2.31.0): implements {@see OutboxClaimInterface} for
 * concurrent relays — `claimBatch()` runs inside one transaction, takes
 * row locks with `FOR UPDATE SKIP LOCKED` when the platform supports it
 * (auto-detected once per connection, with graceful degradation to plain
 * `FOR UPDATE` and finally to lock-free selects on SQLite), and stamps
 * lease metadata on the claimed rows. Expired leases are reclaimable, so
 * a crashed relay's backlog self-heals. `createSchema()` also maintains a
 * `(status, next_attempt_at, created_at)` relay index and upgrades legacy
 * tables that predate the lease columns.
 */
final readonly class PdoOutbox implements OutboxStoreInterface, OutboxClaimInterface
{
    private const array COLUMNS = ['id', 'message_type', 'payload', 'metadata', 'attempts', 'status', 'next_attempt_at', 'last_error', 'created_at', 'lease_owner', 'lease_until'];

    /** Row-lock suffixes tried in order by {@see lockSuffix()} (strongest first). */
    private const string LOCK_SKIP = ' FOR UPDATE SKIP LOCKED';
    private const string LOCK_UPDATE = ' FOR UPDATE';
    private const string LOCK_NONE = '';

    private string $table;

    private string $quotedTable;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port (shared connection enables atomic persist)
     * @param string                 $table      outbox table name
     * @param null|(\Closure(): int) $clock      createdAt/now source (default: realtime nanoseconds)
     */
    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_outbox',
        ?\Closure $clock = null,
    ) {
        new QueryBuilder()->quoteIdentifier($table, 'table');
        $this->table = $table;
        $this->quotedTable = new QueryBuilder()->quoteIdentifier($table, 'table');
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Create the outbox table (portable DDL, safe to run repeatedly).
     *
     * Also (v2.31.0) maintains the relay index and upgrades legacy tables:
     * a table created before lease claiming gets its lease columns via a
     * best-effort ALTER (duplicate-column errors are swallowed), so calling
     * createSchema() once after upgrading is the supported migration path.
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS ' . $this->quotedTable . ' ('
            . '"id" VARCHAR(64) NOT NULL, '
            . '"message_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"metadata" TEXT NOT NULL, '
            . '"status" VARCHAR(16) NOT NULL, '
            . '"attempts" INT NOT NULL, '
            . '"next_attempt_at" BIGINT NOT NULL, '
            . '"last_error" TEXT NULL, '
            . '"created_at" BIGINT NOT NULL, '
            . '"lease_owner" VARCHAR(64) NULL, '
            . '"lease_until" BIGINT NULL, '
            . 'CONSTRAINT "uq_' . $this->table . '_id" UNIQUE ("id"))',
        ));
        $this->ensureLeaseColumns();
        $this->createRelayIndex();
    }

    #[\Override]
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
    {
        $now = ($this->clock)();
        $entry = new OutboxEntry(
            id: bin2hex(random_bytes(16)),
            messageType: $messageType,
            payload: $payload,
            metadata: $metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $now,
            lastError: null,
            createdAtUnixNano: $now,
        );
        $this->connection->execute(
            QueryBuilder::table($this->table)->insert([
                'id' => $entry->id,
                'message_type' => $entry->messageType,
                'payload' => EventJson::encode($entry->payload, 'Outbox entry payload'),
                'metadata' => EventJson::encode($entry->metadata, 'Outbox entry metadata'),
                'status' => $entry->status,
                'attempts' => $entry->attempts,
                'next_attempt_at' => $entry->nextAttemptAtUnixNano,
                'last_error' => $entry->lastError,
                'created_at' => $entry->createdAtUnixNano,
            ])->build(),
        );

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();

        return array_map(
            $this->hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->where('next_attempt_at', '<=', $now)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->updateById($id, ['status' => OutboxEntry::STATUS_PROCESSED, 'lease_owner' => null, 'lease_until' => null]);
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PENDING,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'next_attempt_at' => $retryAtUnixNano,
            'last_error' => $error,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_FAILED,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'last_error' => $error,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
    }

    #[\Override]
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }

        return array_map(
            $this->hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_FAILED)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    #[\Override]
    public function countPending(): int
    {
        $row = $this->connection->fetchOne(
            QueryBuilder::table($this->table)
                ->aggregate('COUNT', '*')
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->build(),
        );
        $value = $row['aggregate'] ?? null;
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new EventSourcingException('countPending() returned no aggregate value.');
        }

        return RowCast::int($value);
    }

    #[\Override]
    public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
    {
        $now = $nextAttemptAtUnixNano ?? ($this->clock)();
        EventGrammar::assertUnixNano($now, 'nextAttemptAtUnixNano');
        $row = $this->connection->fetchOne(
            $this->selectQb()->where('id', '=', $id)->build(),
        );
        if ($row === null) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }
        $status = RowCast::string($row['status'] ?? null);
        if ($status !== OutboxEntry::STATUS_FAILED) {
            throw new EventSourcingException(
                "Only failed entries can be requeued (entry '{$id}' is '{$status}').",
            );
        }
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
        $updated = $this->connection->fetchOne(
            $this->selectQb()->where('id', '=', $id)->build(),
        );
        if ($updated === null) {
            throw new EventSourcingException("Outbox entry '{$id}' vanished during requeue.");
        }

        return $this->hydrate($updated);
    }

    // -------------------------------------------------- lease claiming (v2.31.0)

    #[\Override]
    public function claimBatch(string $owner, int $limit, int $leaseSeconds, ?int $nowUnixNano = null): array
    {
        if ($owner === '' || \strlen($owner) > 64) {
            throw new EventSourcingException('claimBatch() owner must be 1..64 chars.');
        }
        if ($limit < 1) {
            throw new EventSourcingException("claimBatch() limit must be >= 1 (got {$limit}).");
        }
        if ($leaseSeconds < 1) {
            throw new EventSourcingException("claimBatch() leaseSeconds must be >= 1 (got {$leaseSeconds}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();
        $leaseUntil = $now + $leaseSeconds * 1_000_000_000;
        EventGrammar::assertUnixNano($leaseUntil, 'lease deadline');

        // One transaction: lock the candidates (FOR UPDATE family), stamp the
        // whole batch with ONE bulk UPDATE, and hydrate the entries straight
        // from the locked rows — no per-row UPDATEs, no post-commit re-fetch
        // loop, and therefore no read-after-commit window either (Kilo
        // review, PR #178: 2N+1 round-trips for a batch of N became 2).
        $entries = $this->connection->transaction(function () use ($owner, $limit, $now, $leaseUntil): array {
            $rows = $this->connection->fetchAll($this->candidatesQuery($now, $limit));
            $ids = [];
            $candidates = [];
            foreach ($rows as $row) {
                $id = RowCast::string($row['id'] ?? null);
                if ($id === '') {
                    continue;
                }
                $ids[] = $id;
                $candidates[] = $row;
            }
            if ($ids === []) {
                return [];
            }
            $this->connection->execute(
                QueryBuilder::table($this->table)
                    ->update(['lease_owner' => $owner, 'lease_until' => $leaseUntil])
                    ->whereIn('id', $ids)
                    ->build(),
            );

            return array_map(
                fn (array $row): OutboxEntry => $this->hydrate($row, $owner, $leaseUntil),
                $candidates,
            );
        });
        usort($entries, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        return $entries;
    }

    #[\Override]
    public function releaseLease(string $owner): int
    {
        if ($owner === '') {
            throw new EventSourcingException('releaseLease() owner must be non-empty.');
        }

        return $this->connection->execute(
            QueryBuilder::table($this->table)
                ->update(['lease_owner' => null, 'lease_until' => null])
                ->where('lease_owner', '=', $owner)
                ->build(),
        );
    }

    /**
     * Best-effort lease-column upgrade for tables created before v2.31.0.
     * Duplicate-column errors from every supported driver (sqlite "duplicate
     * column", mysql "Duplicate column name", pgsql "... already exists")
     * are treated as "upgrade already applied".
     */
    private function ensureLeaseColumns(): void
    {
        foreach (['"lease_owner" VARCHAR(64) NULL', '"lease_until" BIGINT NULL'] as $definition) {
            try {
                $this->connection->execute(SqlQuery::raw(
                    'ALTER TABLE ' . $this->quotedTable . ' ADD COLUMN ' . $definition,
                ));
            } catch (QueryException $e) {
                $message = strtolower($e->getMessage());
                if (!str_contains($message, 'duplicate column') && !str_contains($message, 'already exists')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Relay index for due()/claimBatch(): (status, next_attempt_at, created_at).
     * Tried with IF NOT EXISTS first (sqlite/pgsql); MySQL rejects that
     * syntax, so on a syntax error it falls back to a plain CREATE INDEX
     * and swallows the duplicate-key error on re-runs.
     */
    private function createRelayIndex(): void
    {
        $index = new QueryBuilder()->quoteIdentifier('idx_' . $this->table . '_relay', 'index');
        $columns = '("status", "next_attempt_at", "created_at")';

        try {
            $this->connection->execute(SqlQuery::raw(
                'CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $this->quotedTable . $columns,
            ));

            return;
        } catch (QueryException) {
            // Unsupported syntax (e.g. MySQL) or already exists — retry plain.
        }

        try {
            $this->connection->execute(SqlQuery::raw(
                'CREATE INDEX ' . $index . ' ON ' . $this->quotedTable . $columns,
            ));
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate') && !str_contains($message, 'already exists')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $pairs
     */
    private function updateById(string $id, array $pairs): void
    {
        $affected = $this->connection->execute(
            QueryBuilder::table($this->table)
                ->update($pairs)
                ->where('id', '=', $id)
                ->build(),
        );
        if ($affected === 0) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }
    }

    private function selectQb(): QueryBuilder
    {
        return QueryBuilder::table($this->table)->select(...self::COLUMNS);
    }

    /**
     * @param array<string, mixed> $row
     * @param null|string          $claimedOwner         lease owner stamped on the row by claimBatch()'s bulk update
     * @param null|int             $claimedUntilUnixNano lease deadline stamped on the row by claimBatch()'s bulk update
     */
    private function hydrate(array $row, ?string $claimedOwner = null, ?int $claimedUntilUnixNano = null): OutboxEntry
    {
        $lastError = $row['last_error'] ?? null;
        $leaseOwner = $claimedOwner ?? ($row['lease_owner'] ?? null);
        $leaseUntil = $claimedUntilUnixNano ?? ($row['lease_until'] ?? null);

        return new OutboxEntry(
            id: RowCast::string($row['id'] ?? null),
            messageType: RowCast::string($row['message_type'] ?? null),
            payload: EventJson::decode(RowCast::string($row['payload'] ?? null), 'outbox entry payload'),
            metadata: EventJson::decode(RowCast::string($row['metadata'] ?? null), 'outbox entry metadata'),
            attempts: RowCast::int($row['attempts'] ?? null),
            status: RowCast::string($row['status'] ?? null),
            nextAttemptAtUnixNano: RowCast::int($row['next_attempt_at'] ?? null),
            lastError: $lastError === null ? null : RowCast::string($lastError),
            createdAtUnixNano: RowCast::int($row['created_at'] ?? null),
            leaseOwner: $leaseOwner === null ? null : RowCast::string($leaseOwner),
            leaseUntilUnixNano: $leaseUntil === null ? null : RowCast::int($leaseUntil),
        );
    }

    /**
     * Candidate select for claimBatch(): claimable = pending + due + not
     * actively leased. Selects EVERY outbox column so claimBatch() hydrates
     * the claimed entries directly from the row-locked candidates — no
     * re-fetch pass is needed. Integers are pre-validated; the table name is
     * quoted at construction. Appends the strongest row-lock suffix the
     * platform accepts (auto-detected once per connection).
     */
    private function candidatesQuery(int $now, int $limit): SqlQuery
    {
        $columns = implode(', ', array_map(
            static fn (string $column): string => '"' . $column . '"',
            self::COLUMNS,
        ));
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->quotedTable
            . " WHERE \"status\" = '" . OutboxEntry::STATUS_PENDING . "'"
            . ' AND "next_attempt_at" <= ' . $now
            . ' AND ("lease_until" IS NULL OR "lease_until" <= ' . $now . ')'
            . ' ORDER BY "created_at" ASC, "id" ASC'
            . ' LIMIT ' . $limit
            . $this->lockSuffix();

        return SqlQuery::raw($sql);
    }

    /**
     * Strongest supported row-lock suffix, probed once per connection and
     * cached in a function-local WeakMap keyed by connection (PdoOutbox is
     * readonly, so instance/static mutable memoization is not available;
     * a function-static lives exactly once per process — the semantics we
     * want for detection caching). Probes run inside throwaway transactions
     * so a failed probe never leaves an aborted transaction behind.
     */
    private function lockSuffix(): string
    {
        static $modes = null;
        if (!$modes instanceof \WeakMap) {
            $modes = new \WeakMap();
        }
        $cached = $modes[$this->connection] ?? null;
        if (is_string($cached)) {
            return $cached;
        }
        foreach ([self::LOCK_SKIP, self::LOCK_UPDATE, self::LOCK_NONE] as $candidate) {
            if ($this->lockingSupported($candidate)) {
                $modes[$this->connection] = $candidate;

                return $candidate;
            }
        }

        return self::LOCK_NONE;
    }

    private function lockingSupported(string $suffix): bool
    {
        try {
            $this->connection->transaction(function () use ($suffix): void {
                // Rowless probe statement carrying the candidate lock suffix;
                // a platform that rejects the syntax throws QueryException,
                // which transaction() converts into a rollback.
                $this->connection->fetchAll(SqlQuery::raw(
                    'SELECT "id" FROM ' . $this->quotedTable . ' WHERE 1 = 0' . $suffix,
                ));
            });

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}
