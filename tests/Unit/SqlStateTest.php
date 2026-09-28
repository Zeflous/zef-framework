<?php

declare(strict_types=1);

/*
 * ZEF Framework — ZEF-DEEP-16 (issue #170) regression tests: the SQLSTATE
 * classifier behind the unique-constraint backstops of the PDO adapters
 * (P-2 snapshot upsert, P-3 append race, P-5 seq collision).
 *
 * The end-to-end races run on SQLite (Deep16EventSourcingJobQueueTest),
 * which only exercises the SQLite dialect of the classifier; this file
 * pins the MySQL and PostgreSQL dialects directly — the test matrix has
 * no servers for them, so the mapping is verified against handcrafted
 * driver errors shaped exactly like the ones PdoConnection chains.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlState;

/**
 * @internal
 */
final class SqlStateTest extends TestCase
{
    /**
     * Regresi P-3 (issue #170): every supported driver's unique violation
     * must classify — by message text, and (for rewritten driver messages)
     * by the SQLSTATE / driver code the exception carries.
     */
    public function testDriverDialectsAreClassifiedAsUniqueViolations(): void
    {
        // SQLite: a flat SQLSTATE 23000 for EVERY integrity error — only the
        // message text separates UNIQUE from NOT NULL/CHECK/FK.
        self::assertTrue(SqlState::isUniqueViolation($this->queryError('UNIQUE constraint failed: zef_events.stream', 23000, ['23000', 19, 'UNIQUE constraint failed: zef_events.stream'])));
        // MySQL/MariaDB: SQLSTATE 23000 + driver code 1062 (ER_DUP_ENTRY).
        self::assertTrue(SqlState::isUniqueViolation($this->queryError("Duplicate entry 'job-42' for key 'uq_zef_job_queue_seq'", 23000, ['23000', 1062, "Duplicate entry 'job-42' for key 'uq_zef_job_queue_seq'"])));
        // PostgreSQL: the SQL-standard unique_violation SQLSTATE 23505.
        self::assertTrue(SqlState::isUniqueViolation($this->queryError('duplicate key value violates unique constraint "uq_zef_job_queue_seq"', 23505, ['23505', 7, 'duplicate key value violates unique constraint "uq_zef_job_queue_seq"'])));
        // Rewritten driver messages must still classify by code: PostgreSQL
        // reports the SQLSTATE in getCode(), MySQL the driver code in
        // errorInfo[1] (PdoConnection chains the PDOException with both).
        self::assertTrue(SqlState::isUniqueViolation($this->queryError('driver message rewritten by the proxy middleware', 23505, ['23505', 0, 'driver message rewritten by the proxy middleware'])));
        self::assertTrue(SqlState::isUniqueViolation($this->queryError('driver message rewritten by the proxy middleware', 23000, ['23000', 1062, 'driver message rewritten by the proxy middleware'])));
    }

    /**
     * The mapping is narrow by construction: same-SQLSTATE-family errors
     * (NOT NULL/CHECK/FK, lock timeouts) never classify, and an exception
     * without a chained driver error cannot claim uniqueness either.
     */
    public function testNonUniqueErrorsStayQueryExceptions(): void
    {
        // SQLite folds NOT NULL (and CHECK/FK) violations into the same flat
        // 23000 as unique violations — the message text is the only witness.
        self::assertFalse(SqlState::isUniqueViolation($this->queryError('NOT NULL constraint failed: zef_jobs.job_id', 23000, ['23000', 19, 'NOT NULL constraint failed: zef_jobs.job_id'])));
        // MySQL lock-wait timeout: SQLSTATE 40001 / driver 1205 — transient,
        // owned by UnitOfWorkRetryPolicy, never a concurrency backstop.
        self::assertFalse(SqlState::isUniqueViolation($this->queryError('Lock wait timeout exceeded; try restarting transaction', 40001, ['40001', 1205, 'Lock wait timeout exceeded; try restarting transaction'])));
        // No chained PDO driver error (e.g. a builder grammar violation).
        self::assertFalse(SqlState::isUniqueViolation(new QueryException('Invalid identifier.')));
    }

    /**
     * Shape the QueryException exactly as PdoConnection wraps a driver
     * failure: driver message, SQLSTATE as the code, PDOException chained.
     *
     * @param list<int|string> $errorInfo
     */
    private function queryError(string $driverMessage, int $sqlState, array $errorInfo): QueryException
    {
        $driver = new \PDOException($driverMessage, $sqlState);
        $driver->errorInfo = $errorInfo;

        return new QueryException('Execution failed: ' . $driverMessage . ' (sql: INSERT)', $sqlState, $driver);
    }
}
