<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/** @internal */
final class DatabasePdoTransactionFailureTest extends TestCase
{
    public function testDeferredConstraintCommitFailureRollsBackAndConnectionCanBeReused(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child (parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static function (ConnectionInterface $c): void {
                $c->execute(SqlQuery::raw('INSERT INTO child VALUES (1)'));
            });
            self::fail('The deferred foreign key must reject commit.');
        } catch (TransactionException $e) {
            self::assertStringStartsWith('Failed to commit transaction:', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));

        $result = $conn->transaction(static function (ConnectionInterface $c): string {
            $c->execute(SqlQuery::raw('INSERT INTO parent VALUES (1)'));
            $c->execute(SqlQuery::raw('INSERT INTO child VALUES (1)'));

            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['parent_id' => 1]], $conn->fetchAll(SqlQuery::raw('SELECT * FROM child')));
    }

    public function testFailedSavepointReleaseRollsBackOnlyInnerWrites(): void
    {
        $pdo = new class('sqlite::memory:') extends \PDO {
            public function exec(string $statement): false|int
            {
                if ($statement === 'RELEASE SAVEPOINT zef_sp2') {
                    throw new \PDOException('release failed');
                }

                return parent::exec($statement);
            }
        };
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn = $this->wrap($pdo);
        $conn->transaction(static function (ConnectionInterface $outer): void {
            $outer->execute(SqlQuery::raw('INSERT INTO t VALUES (1)'));

            try {
                $outer->transaction(static function (ConnectionInterface $inner): void {
                    $inner->execute(SqlQuery::raw('INSERT INTO t VALUES (2)'));
                });
                self::fail('Savepoint release must fail.');
            } catch (QueryException $e) {
                self::assertStringContainsString('release failed', $e->getMessage());
            }

            self::assertSame(1, $outer->transactionLevel());
            self::assertSame([['id' => 1]], $outer->fetchAll(SqlQuery::raw('SELECT * FROM t')));
            $outer->execute(SqlQuery::raw('INSERT INTO t VALUES (3)'));
        });

        self::assertSame(0, $conn->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['id' => 1], ['id' => 3]], $conn->fetchAll(SqlQuery::raw('SELECT * FROM t ORDER BY id')));
    }

    #[DataProvider('uncertainCleanupCases')]
    public function testUncertainCleanupPreservesOriginalFailureAndRejectsReuse(string $state, bool $callbackFails): void
    {
        $primary = $callbackFails ? new \LogicException('callback failed') : new \PDOException('commit failed');
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        if ($callbackFails) {
            $pdo->expects(self::never())->method('commit');
        } else {
            $pdo->expects(self::once())->method('commit')->willThrowException($primary);
        }
        $status = $pdo->expects(self::once())->method('inTransaction');
        if ($state === 'status failure') {
            $status->willThrowException(new \PDOException('status unavailable'));
        } else {
            $status->willReturn(in_array($state, ['rollback failure', 'rollback returns false'], true));
        }
        if ($state === 'rollback failure') {
            $pdo->expects(self::once())->method('rollBack')->willThrowException(new \PDOException('rollback failed'));
        } elseif ($state === 'rollback returns false') {
            $pdo->expects(self::once())->method('rollBack')->willReturn(false);
        } else {
            $pdo->expects(self::never())->method('rollBack');
        }
        $conn = $this->wrap($pdo);

        try {
            $conn->transaction(static function () use ($callbackFails, $primary): void {
                if ($callbackFails) {
                    throw $primary;
                }
            });
            self::fail('The original failure must propagate.');
        } catch (\LogicException|TransactionException $e) {
            self::assertSame($primary, $callbackFails ? $e : $e->getPrevious());
        }

        self::assertSame(0, $conn->transactionLevel());

        try {
            $conn->fetchAll(SqlQuery::raw('SELECT 1'));
            self::fail('An uncertain connection must reject queries.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('unusable', $e->getMessage());
        }
        $this->expectException(ConnectionException::class);
        $conn->beginTransaction();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function uncertainCleanupCases(): iterable
    {
        foreach (['inactive', 'status failure', 'rollback failure', 'rollback returns false'] as $state) {
            yield $state . ' after commit' => [$state, false];

            yield $state . ' after callback' => [$state, true];
        }
    }

    private function wrap(\PDO $pdo): PdoConnection
    {
        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }
}
