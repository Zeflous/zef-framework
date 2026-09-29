<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * v2.18.0 — Database Core: PdoConnection + ConnectionConfig edge matrix.
 * Integration runs on real SQLite in-memory; config/DSN matrices are pure.
 *
 * @internal
 */
final class DatabasePdoConnectionTest extends TestCase
{
    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)',
        ));
    }

    // ------------------------------------------------------------------
    // Config & DSN (pure)
    // ------------------------------------------------------------------

    public function testConfigUnknownDriverRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Unknown database driver 'oracle' (allowed: mysql, pgsql, sqlite).");
        ConnectionConfig::fromArray(['driver' => 'oracle', 'dbname' => 'x']);
    }

    public function testConfigNonStringDriverRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Unknown database driver '5' (allowed: mysql, pgsql, sqlite).");
        ConnectionConfig::fromArray(['driver' => 5, 'dbname' => 'x']);
    }

    public function testConfigEmptyDbnameRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Database name (dbname) must be a non-empty string for driver 'mysql'.");
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => '']);
    }

    public function testConfigSqliteNonPrintablePathRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("SQLite database path must be ':memory:' or printable ASCII (got non-printable input).");
        ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => "db\x00null"]);
    }

    public function testConfigHostGuards(): void
    {
        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => '', 'dbname' => 'd']);
            self::fail('empty host must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame("Host must be a non-empty string without whitespace for driver 'mysql'.", $e->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Host must be a non-empty string without whitespace for driver 'mysql'.");
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'two hosts', 'dbname' => 'd']);
    }

    public function testConfigPortNormalisationAndGuards(): void
    {
        self::assertSame(3306, ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd'])->port);
        self::assertSame(5432, ConnectionConfig::fromArray(['driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd'])->port);
        self::assertSame(13306, ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => '13306'])->port);

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => 0]);
            self::fail('port 0 must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Port 0 is out of range (1-65535).', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => 65536]);
            self::fail('port 65536 must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Port 65536 is out of range (1-65535).', $e->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Port must be an int or a numeric string (1-65535).');
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => '8o']);
    }

    public function testConfigUserPasswordCharsetOptionGuards(): void
    {
        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'user' => 5]);
            self::fail('non-string user must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('User must be a string or null.', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'password' => []]);
            self::fail('non-string password must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Password must be a string or null.', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'charset' => 5]);
            self::fail('non-string charset must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Charset must be a string or null.', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'options' => 'x']);
            self::fail('non-array options must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Options must be an array.', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'options' => ['nope' => 1]]);
            self::fail('unknown option must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame("Unknown connection option 'nope' (allowed: persistent, timeout).", $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'options' => ['persistent' => 'yes']]);
            self::fail('non-bool persistent must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Option persistent must be a bool.', $e->getMessage());
        }

        try {
            ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'options' => ['timeout' => 'soon']]);
            self::fail('non-numeric timeout must be rejected');
        } catch (ConnectionException $e) {
            self::assertSame('Option timeout must be an int or float.', $e->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Option timeout must be greater than zero.');
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'options' => ['timeout' => 0]]);
    }

    public function testDsnExactness(): void
    {
        $mysql = ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'db.local', 'dbname' => 'app']);
        self::assertSame('mysql:host=db.local;port=3306;dbname=app;charset=utf8mb4', $mysql->dsn());

        $mysqlCustom = ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'charset' => 'ascii']);
        self::assertSame('mysql:host=h;port=3306;dbname=d;charset=ascii', $mysqlCustom->dsn());

        $pgsql = ConnectionConfig::fromArray(['driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd']);
        self::assertSame('pgsql:host=h;port=5432;dbname=d', $pgsql->dsn());

        $sqlite = ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']);
        self::assertSame('sqlite::memory:', $sqlite->dsn());

        $file = ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => '/tmp/zef.db']);
        self::assertSame('sqlite:/tmp/zef.db', $file->dsn());
    }

    // ------------------------------------------------------------------
    // Execution (real SQLite)
    // ------------------------------------------------------------------

    public function testInsertFetchRoundtrip(): void
    {
        $this->conn->execute(QueryBuilder::table('t')->insert(['name' => 'Ari', 'age' => 30])->build());
        $this->conn->execute(QueryBuilder::table('t')->insert(['name' => 'Budi', 'age' => 17])->build());

        $rows = $this->conn->fetchAll(QueryBuilder::table('t')->select('name')->where('age', '>=', 18)->orderBy('name')->build());
        self::assertSame([['name' => 'Ari']], $rows);

        $one = $this->conn->fetchOne(QueryBuilder::table('t')->select('name', 'age')->where('name', '=', 'Budi')->build());
        self::assertSame(['name' => 'Budi', 'age' => 17], $one);

        self::assertNull(
            $this->conn->fetchOne(QueryBuilder::table('t')->select('name')->where('name', '=', 'missing')->build()),
        );
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $this->conn->execute(QueryBuilder::table('t')->insertRows([
            ['name' => 'a', 'age' => 1],
            ['name' => 'b', 'age' => 2],
            ['name' => 'c', 'age' => 3],
        ])->build());

        self::assertSame(3, $this->conn->execute(QueryBuilder::table('t')->insertRows([
            ['name' => 'd', 'age' => 4],
            ['name' => 'e', 'age' => 5],
            ['name' => 'f', 'age' => 6],
        ])->build()));

        self::assertSame(2, $this->conn->execute(QueryBuilder::table('t')->update(['age' => 0])->where('age', '<', 3)->build()));
        self::assertSame(1, $this->conn->execute(QueryBuilder::table('t')->where('name', '=', 'a')->delete()->build()));
    }

    public function testLastInsertId(): void
    {
        self::assertNull($this->conn->lastInsertId());
        $this->conn->execute(QueryBuilder::table('t')->insert(['name' => 'x', 'age' => 1])->build());
        self::assertSame('1', $this->conn->lastInsertId());
        $this->conn->execute(QueryBuilder::table('t')->insert(['name' => 'y', 'age' => 2])->build());
        self::assertSame('2', $this->conn->lastInsertId());
    }

    public function testBadSqlMappedToQueryExceptionWithSql(): void
    {
        try {
            $this->conn->execute(SqlQuery::raw('SELECT * FROM missing_table'));
            self::fail('bad SQL must surface as QueryException');
        } catch (QueryException $e) {
            self::assertStringContainsString('Preparation failed:', $e->getMessage());
            // ZEF-DX-09 (#249): the SQL rides in structured context, never in
            // the message (debug mode renders messages into HTTP bodies).
            self::assertSame('SELECT * FROM missing_table', $e->getSql());
            self::assertStringNotContainsString('sql:', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        // Prepares fine, fails at execution time (duplicate primary key).
        $this->conn->execute(QueryBuilder::table('t')->insert(['id' => 1, 'name' => 'dup', 'age' => 0])->build());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Execution failed:');
        $this->conn->execute(QueryBuilder::table('t')->insert(['id' => 1, 'name' => 'dup', 'age' => 0])->build());
    }

    public function testEmptySqlRejectedAtSqlQueryLevel(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQL statement must not be empty.');
        new SqlQuery('', []);
    }

    // ------------------------------------------------------------------
    // Transactions & savepoints (real SQLite)
    // ------------------------------------------------------------------

    public function testNestedTransactionSavepointRollback(): void
    {
        $this->conn->execute(QueryBuilder::table('t')->insert(['name' => 'keep', 'age' => 1])->build());

        try {
            $this->conn->transaction(function (ConnectionInterface $c): void {
                $c->execute(QueryBuilder::table('t')->insert(['name' => 'outer', 'age' => 2])->build());

                try {
                    $c->transaction(function (ConnectionInterface $inner): never {
                        $inner->execute(QueryBuilder::table('t')->insert(['name' => 'inner', 'age' => 3])->build());

                        throw new \RuntimeException('boom');
                    });
                } catch (\RuntimeException) {
                    // inner rolled back; outer continues
                }
                $c->execute(QueryBuilder::table('t')->insert(['name' => 'outer2', 'age' => 4])->build());

                throw new \LogicException('outer boom');
            });
            // @phpstan-ignore-next-line (transaction() never returns on this path)
            self::fail('outer transaction must rethrow');
        } catch (\LogicException) {
        }

        $names = array_column($this->conn->fetchAll(QueryBuilder::table('t')->select('name')->build()), 'name');
        self::assertSame(['keep'], $names);
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testTransactionReturnsCallableResult(): void
    {
        $result = $this->conn->transaction(static fn (ConnectionInterface $c): int => 42);
        self::assertSame(42, $result);
    }

    public function testCommitRollbackOutsideTransactionRejected(): void
    {
        try {
            $this->conn->commit();
            self::fail('commit outside transaction must be rejected');
        } catch (TransactionException $e) {
            self::assertSame('commit() called outside a transaction.', $e->getMessage());
        }

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('rollBack() called outside a transaction.');
        $this->conn->rollBack();
    }

    public function testIsolationRejectedOnSqlite(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('SQLite does not support isolation levels; pass null instead of SERIALIZABLE.');
        $this->conn->beginTransaction(IsolationLevel::Serializable);
    }

    public function testIsolationOnlyOnOutermost(): void
    {
        $this->conn->beginTransaction();
        self::assertSame(1, $this->conn->transactionLevel());

        try {
            $this->conn->beginTransaction(IsolationLevel::Serializable);
            self::fail('nested isolation request must be rejected');
        } catch (TransactionException $e) {
            self::assertSame(
                'Isolation level may only be requested on the outermost transaction (current level: 1).',
                $e->getMessage(),
            );
        }

        $this->conn->commit();
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testIsolationStatementOrderingIsDriverAware(): void
    {
        // Regresi P-6 (issue #172): PostgreSQL scopes `SET TRANSACTION
        // ISOLATION LEVEL` to the CURRENT transaction block and silently
        // ignores it outside one, so the SET must follow BEGIN; MySQL
        // scopes it to the session's NEXT transaction, so it must precede
        // BEGIN. The mock records the statement order without a server.
        $order = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'BEGIN';

            return true;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $statement) use (&$order): int {
            $order[] = $statement;

            return 0;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'COMMIT';

            return true;
        });

        $pgsql = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd',
        ]), $pdo);
        $pgsql->beginTransaction(IsolationLevel::Serializable);
        self::assertSame(['BEGIN', 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'], $order);
        $pgsql->commit();
        self::assertSame(
            ['BEGIN', 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'COMMIT'],
            $order,
        );

        $mysql = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'mysql', 'host' => 'h', 'dbname' => 'd',
        ]), $pdo);
        $mysql->beginTransaction(IsolationLevel::RepeatableRead);
        self::assertSame(
            [
                'BEGIN',
                'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE',
                'COMMIT',
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'BEGIN',
            ],
            $order,
        );
        $mysql->commit();
    }

    public function testPostgresIsolationFailureAfterBeginRollsBackOpenBlock(): void
    {
        // Regresi P-6 (issue #172): when the post-BEGIN SET fails on
        // PostgreSQL, the just-opened block must not leak — the adapter
        // stays at level 0 and the handle is rolled back.
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('exec')
            ->with('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')
            ->willThrowException(new \PDOException('bad isolation'))
        ;
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd',
        ]), $pdo);

        try {
            $conn->beginTransaction(IsolationLevel::Serializable);
            self::fail('failing SET TRANSACTION must surface as QueryException');
        } catch (QueryException $e) { // @phpstan-ignore catch.neverThrown
            // Runtime-real: the mocked PDO::exec throws PDOException, which
            // runStatement wraps into QueryException before beginTransaction
            // rethrows it — a throw path static analysis cannot see through
            // the mock's dynamic behaviour.
            self::assertStringContainsString('bad isolation', $e->getMessage());
            self::assertSame(0, $conn->transactionLevel(), 'the opened block must not leak');
        }
    }

    public function testConnectFailureMessageShape(): void
    {
        $mysql = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'dbname' => 'x', 'port' => 1,
        ]));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection failed (mysql):');
        $mysql->execute(SqlQuery::raw('SELECT 1'));
    }

    public function testLazyConnectNoSocketUntilFirstUse(): void
    {
        $config = ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'no-such-host.invalid', 'dbname' => 'd']);
        $conn = new PdoConnection($config);
        // Construction must not throw; the first statement does.
        self::assertSame(0, $conn->transactionLevel());

        try {
            $conn->execute(SqlQuery::raw('SELECT 1'));
            self::fail('first statement must attempt to connect and fail');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('Connection failed (mysql):', $e->getMessage());
        }
    }
}
