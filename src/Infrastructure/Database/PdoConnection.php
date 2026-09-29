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
 * - transaction/SAVEPOINT nesting, isolation placement and failure
 *   cleanup live in the {@see PdoTransactions} collaborator (see its
 *   docblock for the driver-aware isolation semantics);
 * - optionally audits `allowUnbounded()` executions: when an unbounded
 *   UPDATE/DELETE (SqlQuery::$unbounded) runs, an optional
 *   {@see MeterInterface} counter and/or an optional
 *   {@see LogExporterInterface} WARN record is emitted for security
 *   audit trails (both ports default to null — no observability, no cost).
 */
final class PdoConnection implements ConnectionInterface
{
    private const string EXECUTION_FAILED_PREFIX = 'Execution failed: ';

    private const string SQL_CONTEXT_SUFFIX = ' (sql: ';

    private ?\PDO $handle = null;
    private bool $unusable = false;

    /** Lazily-created transaction machinery ({@see transactions()}). */
    private ?PdoTransactions $transactions = null;

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
                self::EXECUTION_FAILED_PREFIX . $e->getMessage() . self::SQL_CONTEXT_SUFFIX . $query->sql . ')',
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
                self::EXECUTION_FAILED_PREFIX . $e->getMessage() . self::SQL_CONTEXT_SUFFIX . $query->sql . ')',
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
        try {
            $id = $this->pdo()->lastInsertId();
        } catch (\PDOException $e) {
            // N-15 (issue #176): e.g. PostgreSQL lastval() is undefined
            // until the session's first INSERT — map it onto the
            // ConnectionException family like every other PDO call
            // instead of leaking a raw PDOException.
            throw new QueryException(
                'Failed to retrieve last insert ID: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        // '' and false = no support; '0' = no insert yet (MySQL/SQLite).
        return (in_array($id, ['', false, '0'], true)) ? null : $id;
    }

    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->transactions()->beginTransaction($isolation);
    }

    public function commit(): void
    {
        $this->transactions()->commit();
    }

    public function rollBack(): void
    {
        $this->transactions()->rollBack();
    }

    public function transactionLevel(): int
    {
        return $this->transactions()->transactionLevel();
    }

    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $this->transactions()->transaction($fn, $isolation);
    }

    /**
     * The transaction machinery collaborator: created on first use so the
     * constructor stays work-free (injected-handle wiring only).
     */
    private function transactions(): PdoTransactions
    {
        $this->transactions ??= new PdoTransactions(
            $this,
            $this->pdo(...),
            $this->config,
            $this->invalidateHandle(...),
        );

        return $this->transactions;
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
                'Preparation failed: ' . $e->getMessage() . self::SQL_CONTEXT_SUFFIX . $query->sql . ')',
                (int) $e->getCode(),
                $e,
            );
        }
    }

    private function pdo(): \PDO
    {
        if ($this->unusable) {
            throw new ConnectionException(
                'Connection is unusable after transaction cleanup failed; create a new connection.',
            );
        }
        $this->handle ??= $this->connect();

        return $this->handle;
    }

    /** Uncertain transaction outcome: the adapter must never be reused. */
    private function invalidateHandle(): void
    {
        $this->unusable = true;
        $this->handle = null;
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
