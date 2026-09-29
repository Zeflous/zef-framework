<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.31.0: DDL/migration collaborator extracted from PdoOutbox so
 * the store facade stays under the maintainability size budgets (Sonar
 * php:S2042). Pure move: SQL statements and exception behavior identical.
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;

/**
 * DDL owner for the PDO outbox table.
 *
 * - portable CREATE TABLE, safe to run repeatedly;
 * - maintains the `(status, next_attempt_at, created_at)` relay index;
 * - upgrades legacy tables that predate the lease columns (best-effort
 *   ALTER: duplicate-column errors are swallowed).
 */
final readonly class PdoOutboxSchema
{
    private const array COLUMN_DDL = [
        '"id" VARCHAR(64) NOT NULL',
        '"message_type" VARCHAR(191) NOT NULL',
        '"payload" TEXT NOT NULL',
        '"metadata" TEXT NOT NULL',
        '"status" VARCHAR(16) NOT NULL',
        '"attempts" INT NOT NULL',
        '"next_attempt_at" BIGINT NOT NULL',
        '"last_error" TEXT NULL',
        '"created_at" BIGINT NOT NULL',
        '"lease_owner" VARCHAR(64) NULL',
        '"lease_until" BIGINT NULL',
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
        private string $quotedTable,
    ) {}

    /**
     * Create the outbox table (portable DDL, safe to run repeatedly).
     *
     * Also (v2.31.0) maintains the relay index and upgrades legacy tables:
     * a table created before lease claiming gets its lease columns via a
     * best-effort ALTER (duplicate-column errors are swallowed), so calling
     * createSchema() once after upgrading is the supported migration path.
     */
    public function create(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS ' . $this->quotedTable . ' (' . implode(', ', self::COLUMN_DDL)
            . ', CONSTRAINT "uq_' . $this->table . '_id" UNIQUE ("id"))',
        ));
        $this->ensureLeaseColumns();
        $this->createRelayIndex();
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
}
