<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-07 (issue #161):
 * the DEFAULT UnitOfWorkRetryPolicy must retry failures surfacing through
 * the framework's own database adapter.
 *
 * Pre-fix, PdoConnection rethrew driver PDOExceptions as QueryException (a
 * RuntimeException, NOT a PDOException) while the default
 * $retryableClassNames was ['PDOException'] and isRetryable() never looked
 * at $e->getPrevious() — so flushRetrying() with the default policy retried
 * NOTHING (0 retries) for adapter failures. The suite hid the bug by throwing
 * raw PDOExceptions from closures.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * @internal
 */
final class DatabaseRetryPolicyAdapterExceptionsTest extends TestCase
{
    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    public function testDefaultRetryableClassNamesCoverTheAdapterException(): void
    {
        $policy = new UnitOfWorkRetryPolicy();

        self::assertContains('PDOException', $policy->retryableClassNames);
        self::assertContains(QueryException::class, $policy->retryableClassNames, 'the shipped adapter throws QueryException, not PDOException');
    }

    /** The exact adapter shape: QueryException wrapping a deadlock PDOException. */
    public function testAdapterShapedQueryExceptionIsRetryable(): void
    {
        $policy = new UnitOfWorkRetryPolicy();
        $adapterError = $this->adapterFailure('deadlock', '40P01');

        self::assertTrue($policy->isRetryable($adapterError), 'QueryException(deadlock, previous: PDOException 40P01) must retry under the default policy');
    }

    /** The SQLSTATE can ride on the wrapped PDOException while the wrapper carries nothing. */
    public function testSqlStateIsUnwrappedFromThePreviousChain(): void
    {
        $policy = new UnitOfWorkRetryPolicy();
        $wrapped = $this->adapterFailure('deadlock', '40001', wrapperCode: 0);

        self::assertTrue($policy->isRetryable($wrapped), 'the wrapped PDOException supplies the SQLSTATE');
    }

    public function testAdapterShapedQueryExceptionWithNonRetryableCauseStaysNotRetryable(): void
    {
        $policy = new UnitOfWorkRetryPolicy();
        $syntaxError = $this->adapterFailure('syntax error', '42601');

        self::assertFalse($policy->isRetryable($syntaxError));
    }

    public function testRawPdoExceptionStillRetries(): void
    {
        $policy = new UnitOfWorkRetryPolicy();

        self::assertTrue($policy->isRetryable($this->makePdoException('deadlock', '40P01')));
    }

    /** A hostile/corrupted cyclic previous chain cannot hang the policy (guard is defensive). */
    public function testDeepPreviousChainsAreUnwrappedCompletely(): void
    {
        $policy = new UnitOfWorkRetryPolicy();
        // Two wrapping levels — exactly what layered infrastructure produces
        // (adapter rethrow inside a transaction manager rethrow).
        $driver = $this->makePdoException('deadlock', '40001');
        $adapterLevel = new QueryException('Execution failed: deadlock (sql: …)', 0, $driver);
        $transactionLevel = new \RuntimeException('flush failed', 0, $adapterLevel);

        self::assertTrue($policy->isRetryable($transactionLevel), 'the retryable PDOException is two previous() hops away');

        $hostileDriver = $this->makePdoException('syntax error', '42601');
        $hostile = new \RuntimeException('flush failed', 0, new QueryException('Execution failed: syntax (sql: …)', 0, $hostileDriver));
        self::assertFalse($policy->isRetryable($hostile), 'no level of the chain carries a retryable classification');
    }

    /**
     * The audit PoC, end to end: flushRetrying() with the DEFAULT policy
     * must actually retry an operation whose failure surfaces as the
     * adapter's QueryException — pre-fix this retried 0 times and propagated
     * immediately.
     */
    public function testFlushRetryingRetriesAdapterQueryExceptions(): void
    {
        $calls = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$calls, &$attempt): void {
            $calls[] = 'op-1';
            ++$attempt;
            if ($attempt < 2) {
                throw $this->adapterFailure('deadlock', '40P01');
            }
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed, 'the retried attempt succeeded');
        self::assertSame(['op-1', 'op-1'], $calls, 'pre-fix: 0 retries — the adapter failure propagated immediately');
        self::assertSame(0, $uow->pending());
        $row = $this->conn->fetchOne(new SqlQuery('SELECT COUNT(*) AS n FROM t'));
        self::assertSame(1, $row['n'] ?? null, 'the retried insert landed exactly once');
    }

    /**
     * Builds exactly what PdoConnection::execute() produces: a QueryException
     * whose message embeds the driver text, whose code is the (int)-cast
     * driver code, and whose $previous is the original PDOException.
     */
    private function adapterFailure(string $message, string $sqlState, ?int $wrapperCode = null): QueryException
    {
        $driver = $this->makePdoException($message, $sqlState);
        $code = $wrapperCode ?? (int) $driver->getCode();

        return new QueryException('Execution failed: ' . $driver->getMessage() . ' (sql: INSERT …)', $code, $driver);
    }

    /** Build a testable PDOException with a SQLSTATE string (house technique). */
    private function makePdoException(string $message, string $sqlState): \PDOException
    {
        return new class($message, $sqlState) extends \PDOException {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message, 0);
                $this->errorInfo = [$sqlState, 0, $message];
            }
        };
    }
}
