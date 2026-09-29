<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Infrastructure layer: outbound adapters)
 * Extracted from PdoConnection so the connection class stays under its
 * size budget: transaction/SAVEPOINT machinery lives here.
 */

namespace Zef\Framework\Database;

/**
 * Transaction machinery of {@see PdoConnection}.
 *
 * - nested transactions use explicit SAVEPOINTs (`zef_sp2`, `zef_sp3`, …)
 *   tracked with an internal depth counter — PDO::inTransaction() cannot
 *   distinguish nesting and is only consulted during failure cleanup;
 * - transaction() cleans up callback and commit failures, preserving the
 *   original exception; uncertain cleanup invalidates the owning adapter;
 * - isolation levels are applied via `SET TRANSACTION ISOLATION LEVEL`
 *   with driver-aware ordering: MySQL scopes the statement to the
 *   session's NEXT transaction, so it runs before the outermost BEGIN;
 *   PostgreSQL honours it only inside the transaction block (outside one
 *   it is a silent no-op), so it runs right after BEGIN and before the
 *   transaction's first statement; SQLite rejects the concept outright;
 * - isolation is a TRANSACTION-SCOPE property, not a SAVEPOINT one: a
 *   nested beginTransaction()/transaction() call cannot change it (a
 *   nested transaction() call silently drops the isolation argument —
 *   see ConnectionInterface::transaction()).
 *
 * The owning connection supplies a lazy {@see \PDO} provider, itself as
 * the transaction() callback target, and an invalidate callback for the
 * uncertain-cleanup path.
 */
final class PdoTransactions
{
    private const int MAX_NESTING = 16;

    private int $level = 0;

    /**
     * @param ConnectionInterface $connection  transaction() callback target
     * @param \Closure(): \PDO     $pdo        lazy handle provider of the owning connection
     * @param \Closure(): void     $invalidate marks the owning connection unusable
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly \Closure $pdo,
        private readonly ConnectionConfig $config,
        private readonly \Closure $invalidate,
    ) {}

    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $pdo = ($this->pdo)();
        $setIsolation = $this->isolationStatement($isolation);
        if ($this->level === 0) {
            $this->beginOutermost($pdo, $setIsolation);
        } else {
            if ($this->level >= self::MAX_NESTING) {
                throw new TransactionException(
                    'Transaction nesting limit of ' . self::MAX_NESTING . ' exceeded.',
                );
            }
            $this->runStatement('SAVEPOINT zef_sp' . ($this->level + 1));
        }
        ++$this->level;
    }

    public function commit(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('commit() called outside a transaction.');
        }
        if ($this->level === 1) {
            try {
                ($this->pdo)()->commit();
            } catch (\PDOException $e) {
                throw new TransactionException('Failed to commit transaction: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $this->runStatement('RELEASE SAVEPOINT zef_sp' . $this->level);
        }
        --$this->level;
    }

    public function rollBack(): void
    {
        if ($this->level === 0) {
            throw new TransactionException('rollBack() called outside a transaction.');
        }
        if ($this->level === 1) {
            try {
                if (!($this->pdo)()->rollBack()) {
                    throw new TransactionException('Failed to roll back transaction.');
                }
            } catch (\PDOException $e) {
                throw new TransactionException('Failed to roll back transaction: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $this->runStatement('ROLLBACK TO SAVEPOINT zef_sp' . $this->level);
        }
        --$this->level;
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }

    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        $outermost = $this->level === 0;
        $this->beginTransaction($outermost ? $isolation : null);

        try {
            $result = $fn($this->connection);
            $this->commit();
        } catch (\Throwable $e) {
            $this->cleanUpFailedTransaction();

            throw $e;
        }

        return $result;
    }

    private function cleanUpFailedTransaction(): void
    {
        try {
            $pdo = ($this->pdo)();
            if ($pdo->inTransaction()) {
                $this->rollBack();

                return;
            }
        } catch (\Throwable) {
            // Cleanup must never replace the original callback/commit failure.
        }

        // The transaction disappeared or rollback could not be confirmed.
        // Its outcome is uncertain: require a new adapter, never reconnect here.
        $this->level = 0;
        ($this->invalidate)();
    }

    /**
     * Validates an isolation request and renders the driver statement.
     * A null request passes through unchanged; misuse fails fast —
     * isolation belongs to the outermost transaction only, and SQLite
     * has no isolation dialect at all.
     */
    private function isolationStatement(?IsolationLevel $isolation): ?string
    {
        if (!$isolation instanceof IsolationLevel) {
            return null;
        }
        if ($this->level > 0) {
            throw new TransactionException(
                'Isolation level may only be requested on the outermost transaction (current level: '
                    . $this->level
                    . ').',
            );
        }
        if ($this->config->driver === 'sqlite') {
            throw new ConnectionException(
                'SQLite does not support isolation levels; pass null instead of ' . $isolation->value . '.',
            );
        }

        return 'SET TRANSACTION ISOLATION LEVEL ' . $isolation->value;
    }

    /**
     * Opens the outermost transaction block, placing the isolation
     * statement on the driver-correct side of BEGIN.
     */
    private function beginOutermost(\PDO $pdo, ?string $setIsolation): void
    {
        // P-6 (issue #172): a bare `SET TRANSACTION ISOLATION LEVEL` is
        // scoped differently per driver — MySQL applies it to the NEXT
        // transaction of the session (correct before BEGIN), while
        // PostgreSQL applies it to the CURRENT transaction block and
        // silently ignores it outside one (a no-op before BEGIN). The
        // pgsql placement below stays valid until the transaction's
        // first statement.
        if ($setIsolation !== null && $this->config->driver === 'mysql') {
            $this->runStatement($setIsolation);
        }

        try {
            $pdo->beginTransaction();
        } catch (\PDOException $e) {
            throw new TransactionException('Failed to begin transaction: ' . $e->getMessage(), 0, $e);
        }
        if ($setIsolation !== null && $this->config->driver !== 'mysql') {
            $this->applyPostBeginIsolation($pdo, $setIsolation);
        }
    }

    /**
     * Applies the isolation statement after BEGIN (the PostgreSQL
     * dialect scope) and guarantees the freshly opened block never
     * leaks: when the SET fails the level counter was never
     * incremented, so a direct PDO rollback restores a consistent
     * handle before the original failure escapes.
     */
    private function applyPostBeginIsolation(\PDO $pdo, string $setIsolation): void
    {
        try {
            $this->runStatement($setIsolation);
        } catch (QueryException $e) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
                // Keep the original failure; cleanup is best-effort.
            }

            throw $e;
        }
    }

    private function runStatement(string $sql): void
    {
        try {
            ($this->pdo)()->exec($sql);
        } catch (\PDOException $e) {
            throw new QueryException('Execution failed: ' . $e->getMessage() . ' (sql: ' . $sql . ')', 0, $e);
        }
    }
}
