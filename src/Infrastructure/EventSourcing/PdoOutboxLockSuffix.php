<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.31.0: row-lock suffix detection extracted from PdoOutbox so
 * the store facade stays under the maintainability size budgets (Sonar
 * php:S2042). Pure move: probing behavior identical.
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;

/**
 * Strongest row-lock suffix the connection's platform accepts.
 *
 * Probed once per connection and cached in a function-local WeakMap keyed
 * by connection (PdoOutbox and this prober are readonly, so instance or
 * class-level mutable memoization is not available; a function-static
 * lives exactly once per process — the semantics we want for detection
 * caching). Probes run inside throwaway transactions so a failed probe
 * never leaves an aborted transaction behind.
 */
final readonly class PdoOutboxLockSuffix
{
    /** Row-lock suffixes tried in order by {@see suffix()} (strongest first). */
    private const string LOCK_SKIP = ' FOR UPDATE SKIP LOCKED';
    private const string LOCK_UPDATE = ' FOR UPDATE';
    private const string LOCK_NONE = '';

    public function __construct(
        private ConnectionInterface $connection,
        private string $quotedTable,
    ) {}

    public function suffix(): string
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
