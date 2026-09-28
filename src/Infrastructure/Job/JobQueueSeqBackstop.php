<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: UNIQUE(seq) backstop for the PdoJobQueue adapter.
 */

namespace Zef\Framework\Job;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\SqlState;

/**
 * The UNIQUE(seq) race backstop of the PDO job queue (Regresi P-5,
 * issue #170), extracted from {@see PdoJobQueue} as a focused
 * collaborator: it owns the bounded seq-collision retry on enqueue,
 * the seq-collision classification of driver errors, and the
 * best-effort UNIQUE(seq) index upgrade for tables created before
 * the constraint joined the CREATE TABLE DDL.
 */
final readonly class JobQueueSeqBackstop
{
    /** Bounded seq-collision retries for {@see insertWithSeqRetry()}. */
    private const int MAX_SEQ_ATTEMPTS = 5;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
    ) {}

    /**
     * Executes the enqueue INSERT with bounded seq-collision retries.
     *
     * Two concurrent enqueues can compute the same
     * COALESCE(MAX("seq"), 0) + 1 — the UNIQUE(seq) backstop in
     * createSchema() rejects the loser, whose INSERT recomputes MAX+1
     * by construction, so a bounded retry lands on the next free slot.
     * Only seq collisions are retried: a UNIQUE(job_id) hit is a caller
     * bug that must keep failing loudly, and after the attempt budget
     * the connection's QueryException escapes unchanged.
     */
    public function insertWithSeqRetry(SqlQuery $insert): void
    {
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
    public function ensureUniqueIndex(): void
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
}
