<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Infrastructure layer: outbound adapters)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MeterInterface;

/**
 * PDO-backed {@see ConnectionInterface} adapter.
 *
 * - connects lazily on first use (no socket touched for config errors);
 * - maps every \PDOException onto ConnectionException (connect phase) or
 *   QueryException (prepare/execute phase), chaining the original;
 * - nested transactions use explicit SAVEPOINTs (`zef_sp2`, `zef_sp3`, …)
 *   tracked with an internal depth counter — PDO::inTransaction() cannot
 *   distinguish nesting and is never consulted;
 * - isolation levels are applied via `SET TRANSACTION ISOLATION LEVEL`
 *   before the outermost BEGIN; SQLite rejects the concept outright;
 * - isolation is a TRANSACTION-SCOPE property, not a SAVEPOINT one: a
 *   nested beginTransaction()/transaction() call cannot change it (a
 *   nested transaction() call silently drops the isolation argument —
 *   see ConnectionInterface::transaction());
 * - optionally audits `allowUnbounded()` executions: when an unbounded
 *   UPDATE/DELETE (SqlQuery::$unbounded) runs, an optional
 *   {@see MeterInterface} counter and/or an optional
 *   {@see LogExporterInterface} WARN record is emitted for security
 *   audit trails (both ports default to null — no observability, no cost).
 */
final class PdoConnection implements ConnectionInterface
{
    private const int MAX_NESTING = 16;

    private ?\PDO $handle = null;
    private int $level = 0;

    public function __construct(
        private readonly ConnectionConfig $config,
        ?\PDO $handle = null,
        private readonly ?MeterInterface $meter = null,
        private readonly ?LogExporterInterface $auditLogs = null,
    ) {
        if ($handle instanceof \PDO) {
            $this->handle = $handle;
        }
    }

    public function execute(SqlQuery $query): int
    {
        $statement = $this->prepare($query);

        try {
            $statement->execute($query->params);
        } catch (\PDOException $e) {
            throw new QueryException(
                'Execution failed: ' . $e->getMessage() . ' (sql: ' . $query->sql . ')',
                (int) $e->getCode(),
                $e,
            );
        }

        $affected = $statement->rowCount();
        if ($query->unbounded) {
            $this->auditUnbounded($query, $affected);
        }

        return $affected;
    }

    public function fetchAll(SqlQuery $query): array
    {
        $statement = $this->prepare($query);

        try {
            $statement->execute($query->params);
        } catch (\PDOException $e) {
            throw new QueryException(
                'Execution failed: ' . $e->getMessage() . ' (sql: ' . $query->sql . ')',
                (int) $e->getCode(),
                $e,
            );
        }

        // @phpstan-ignore-next-line PDO::FETCH_ASSOC yields string-keyed rows at runtime for SQL drivers.
        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->fetchAll($query)[0] ?? null;
    }

    public function lastInsertId(): ?string
    {
        $id = $this->pdo()->lastInsertId();

        // '' and false = no support; '0' = no insert yet (MySQL/SQLite).
        return (in_array($id, ['', false, '0'], true)) ? null : $id;
    }

    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $pdo = $this->pdo();
        if ($isolation instanceof IsolationLevel) {
            if ($this->level > 0) {
                throw new TransactionException(
                    'Isolation level may only be requested on the outermost transaction (current level: ' . $this->level . ').',
                );
            }
            if ($this->config->driver === 'sqlite') {
                throw new ConnectionException(
                    'SQLite does not support isolation levels; pass null instead of ' . $isolation->value . '.',
                );
            }
            $this->runStatement('SET TRANSACTION ISOLATION LEVEL ' . $isolation->value);
        }
        if ($this->level === 0) {
            try {
                $pdo->beginTransaction();
            } catch (\PDOException $e) {
                throw new TransactionException('Failed to begin transaction: ' . $e->getMessage(), 0, $e);
            }
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
                $this->pdo()->commit();
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
                $this->pdo()->rollBack();
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
            $result = $fn($this);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        return $result;
    }

    /**
     * Security audit hook for allowUnbounded() executions — telemetry
     * counter + optional WARN log record carrying the full SQL so the
     * event stays greppable in observability backends.
     */
    private function auditUnbounded(SqlQuery $query, int $affected): void
    {
        $op = str_starts_with(strtoupper(ltrim($query->sql)), 'UPDATE') ? 'update' : 'delete';
        $this->meter?->increment('zef.db.unbounded_statement', 1, ['op' => $op]);
        $this->auditLogs?->exportLogs([
            new LogRecord(
                'WARN',
                'Unbounded ' . $op . ' executed (allowUnbounded) — ' . $affected . ' row(s) affected',
                (int) (microtime(true) * 1_000_000_000),
                ['sql' => $query->sql, 'rows' => $affected, 'op' => $op],
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function prepare(SqlQuery $query): \PDOStatement
    {
        try {
            return $this->pdo()->prepare($query->sql);
        } catch (\PDOException $e) {
            throw new QueryException(
                'Preparation failed: ' . $e->getMessage() . ' (sql: ' . $query->sql . ')',
                (int) $e->getCode(),
                $e,
            );
        }
    }

    private function runStatement(string $sql): void
    {
        try {
            $this->pdo()->exec($sql);
        } catch (\PDOException $e) {
            throw new QueryException('Execution failed: ' . $e->getMessage() . ' (sql: ' . $sql . ')', 0, $e);
        }
    }

    private function pdo(): \PDO
    {
        $this->handle ??= $this->connect();

        return $this->handle;
    }

    private function connect(): \PDO
    {
        $attributes = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (($this->config->options['persistent'] ?? false) === true) {
            $attributes[\PDO::ATTR_PERSISTENT] = true;
        }
        $timeout = $this->config->options['timeout'] ?? null;
        if (is_int($timeout) || is_float($timeout)) {
            $attributes[\PDO::ATTR_TIMEOUT] = (int) ceil((float) $timeout);
        }

        try {
            return new \PDO(
                $this->config->dsn(),
                $this->config->user,
                $this->config->password,
                $attributes,
            );
        } catch (\PDOException $e) {
            throw new ConnectionException(
                'Connection failed (' . $this->config->driver . '): ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
