<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * v2.22.1 — issue #65 item 2: UoW flushRetrying() preserves the queue
 * across retry attempts and re-runs the operations until either success
 * or policy exhaustion.
 *
 * Uses real SQLite in-memory + closures that throw transient failures on
 * the first N attempts (deterministic, no fake connection needed).
 *
 * @internal
 */
final class DatabaseUnitOfWorkFlushRetryingTest extends TestCase
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

    public function testFlushRetryingSucceedsOnFirstAttempt(): void
    {
        $calls = [];
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'op-1';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        });
        $uow->record(function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'op-2';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second']));
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(2, $executed);
        self::assertSame(['op-1', 'op-2'], $calls);
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    public function testFlushRetryingReplaysOperationsOnTransientFailure(): void
    {
        $calls = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$calls, &$attempt): void {
            $calls[] = 'op-1';
            ++$attempt;
            if ($attempt < 2) {
                throw $this->makePdoException('deadlock', '40P01');
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed, 'final attempt succeeded');
        self::assertSame(['op-1', 'op-1'], $calls, 'operation replayed on retry');
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    public function testFlushRetryingPropagatesNonRetryableThrowable(): void
    {
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c): never {
            throw new \LogicException('not retryable');
        });

        $policy = new UnitOfWorkRetryPolicy();
        $this->expectException(\LogicException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingExhaustsAttemptsAndPropagatesLast(): void
    {
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c): never {
            throw $this->makePdoException('deadlock', '40P01');
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0);
        $this->expectException(\PDOException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingRefusesReentry(): void
    {
        $uow = new UnitOfWork();
        $ref = new \ReflectionProperty($uow, 'flushing');
        $ref->setValue($uow, true);

        $policy = new UnitOfWorkRetryPolicy();
        $this->expectException(TransactionException::class);
        $uow->flushRetrying($this->conn, $policy);
    }

    public function testFlushRetryingEmptyQueueReturnsZero(): void
    {
        $uow = new UnitOfWork();
        $policy = new UnitOfWorkRetryPolicy();
        self::assertSame(0, $uow->flushRetrying($this->conn, $policy));
    }

    public function testFlushRetryingPreservesQueueAcrossRetries(): void
    {
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use (&$attempt): void {
            ++$attempt;
            if ($attempt < 3) {
                throw $this->makePdoException('deadlock', '40P01');
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 5, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed);
        self::assertSame(3, $attempt, 'tried 3 times before success');
        self::assertSame(0, $uow->pending(), 'queue cleared on success');
    }

    #[DataProvider('transactionLevels')]
    public function testRetryRollsBackAllWritesFromFailedAttempt(int $level): void
    {
        for ($i = 0; $i < $level; ++$i) {
            $this->conn->beginTransaction();
        }
        $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['before-flush']));

        $calls = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(static function (ConnectionInterface $c) use (&$calls): void {
            $calls[] = 'first';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        });
        $uow->record(function (ConnectionInterface $c) use (&$calls, &$attempt): void {
            $calls[] = 'second';
            $c->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['second']));
            if (++$attempt === 1) {
                throw $this->makePdoException('lock wait timeout', '1205');
            }
        });

        self::assertSame(2, $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy(initialDelayMs: 0)));
        self::assertSame(['first', 'second', 'first', 'second'], $calls);
        self::assertSame(0, $uow->pending());
        self::assertSame($level, $this->conn->transactionLevel());
        for ($i = 0; $i < $level; ++$i) {
            $this->conn->commit();
        }
        self::assertSame(
            [['name' => 'before-flush'], ['name' => 'first'], ['name' => 'second']],
            $this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t ORDER BY id')),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function transactionLevels(): iterable
    {
        yield 'standalone' => [0];

        yield 'transaction' => [1];

        yield 'nested transaction' => [2];
    }

    #[DataProvider('terminalFailures')]
    public function testTerminalFailureRollsBackWritesAndRetainsQueue(bool $retryable, int $level): void
    {
        if ($level > 0) {
            $this->conn->beginTransaction();
        }
        $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['before-flush']));
        $failure = $retryable ? $this->makePdoException('timeout', '1205') : new \LogicException('stop');
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['first']));
        $uow->record(static function (ConnectionInterface $c) use (&$attempt, $failure): never {
            ++$attempt;

            throw $failure;
        });

        try {
            $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0));
            self::fail('Expected the flush to fail');
        } catch (\Throwable $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame($retryable ? 2 : 1, $attempt);
        self::assertSame(2, $uow->pending());
        self::assertSame($level, $this->conn->transactionLevel());
        self::assertSame([['name' => 'before-flush']], $this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t')));
        self::assertSame(2, $uow->discard());
        $uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['later']));
        self::assertSame(1, $uow->flushRetrying($this->conn, new UnitOfWorkRetryPolicy(initialDelayMs: 0)));
        if ($level > 0) {
            $this->conn->rollBack();
            self::assertSame([], $this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t')));
        }
    }

    /** @return iterable<string, array{bool, int}> */
    public static function terminalFailures(): iterable
    {
        yield 'non-retryable standalone' => [false, 0];

        yield 'exhausted standalone' => [true, 0];

        yield 'non-retryable nested' => [false, 1];

        yield 'exhausted nested' => [true, 1];
    }

    public function testMissingSavepointAbortsWithoutReplayingWrites(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $connection = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
        $connection->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY)'));
        $connection->beginTransaction();
        $calls = 0;
        $uow = new UnitOfWork();
        $uow->recordQuery(SqlQuery::raw('INSERT INTO t DEFAULT VALUES'));
        $uow->record(function (ConnectionInterface $c) use ($pdo, &$calls): void {
            if (++$calls === 1) {
                // Simulate a driver aborting the entire transaction, losing its savepoints.
                $pdo->rollBack();

                throw $this->makePdoException('deadlock', '1213');
            }
        });

        try {
            $uow->flushRetrying($connection, new UnitOfWorkRetryPolicy(initialDelayMs: 0));
            self::fail('Cannot retry after losing the enclosing transaction');
        } catch (QueryException $e) {
            self::assertStringContainsString('SAVEPOINT', $e->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame(2, $uow->pending());
        self::assertSame([], $connection->fetchAll(SqlQuery::raw('SELECT id FROM t')));
    }

    #[DataProvider('boundaryFailures')]
    public function testTransactionBoundaryFailuresAreNotRetried(string $boundary): void
    {
        $failure = $this->makePdoException('boundary failure', '1205');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects($boundary === 'rollBack' ? self::once() : self::never())->method('rollBack');
        $connection->expects($boundary === 'commit' ? self::once() : self::never())->method('commit');
        $connection->method($boundary)->willThrowException($failure);
        $calls = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use ($boundary, &$calls): void {
            ++$calls;
            if ($boundary === 'rollBack') {
                throw $this->makePdoException('operation failure', '1205');
            }
        });

        try {
            $uow->flushRetrying($connection, new UnitOfWorkRetryPolicy(initialDelayMs: 0));
            self::fail('Expected transaction boundary failure');
        } catch (\PDOException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame($boundary === 'beginTransaction' ? 0 : 1, $calls);
        self::assertSame(1, $uow->pending());
        $uow->recordQuery(SqlQuery::raw('SELECT 1'));
        self::assertSame(2, $uow->pending(), 'flush guard reset after boundary failure');
    }

    /** @return iterable<string, array{string}> */
    public static function boundaryFailures(): iterable
    {
        yield 'begin' => ['beginTransaction'];

        yield 'rollback' => ['rollBack'];

        yield 'commit' => ['commit'];
    }

    /**
     * Build a testable PDOException with a SQLSTATE string. Real
     * PDOExceptions are populated by PHP internals at runtime; the public
     * constructor only accepts an int $code, so we extend the class and
     * populate errorInfo directly.
     */
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
