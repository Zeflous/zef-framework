<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\Migrator;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * v2.18.0 — Database Core: mutation kill round 2. Targeted at the escape
 * clusters from the first Infection run: assertSelect removals, case
 * normalisation, identifier/regex anchors, scalar guards, message shapes,
 * lock visibility, and nesting-depth boundaries.
 *
 * @internal
 */
final class DatabaseKillRound2Test extends TestCase
{
    // ------------------------------------------------------------------
    // PdoConnection — nesting depth + message shapes
    // ------------------------------------------------------------------

    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY)'));
    }
    // ------------------------------------------------------------------
    // QueryBuilder — select-only method guards on a delete query
    // ------------------------------------------------------------------

    public function testSelectOnlyMethodsRejectedAfterDelete(): void
    {
        $cases = [
            'select()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->select('a'),
            'distinct()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->distinct(),
            'join()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->join('x', 't.a', '=', 'x.a'),
            'groupBy()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->groupBy('a'),
            'having()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->having('a', '=', 1),
            'orderBy()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->orderBy('a'),
            'limit()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->limit(1),
            'offset()' => static fn (QueryBuilder $qb): QueryBuilder => $qb->offset(0),
        ];
        foreach ($cases as $method => $call) {
            try {
                $call(QueryBuilder::table('t')->delete());
                self::fail("{$method} on a delete query must be rejected.");
            } catch (QueryException $e) {
                self::assertSame("{$method} is not available on a delete query.", $e->getMessage());
            }
        }
    }

    public function testAggregateFunctionNameCaseNormalised(): void
    {
        $q = QueryBuilder::table('t')->aggregate('count');
        self::assertSame('SELECT COUNT(*) AS aggregate FROM "t"', $q->toSql());

        $q2 = QueryBuilder::table('t')->aggregate('Avg', 'n');
        self::assertSame('SELECT AVG("n") AS aggregate FROM "t"', $q2->toSql());
    }

    public function testJoinTypeCaseNormalised(): void
    {
        $q = QueryBuilder::table('a')->join('b', 'a.id', '=', 'b.a_id', 'LEFT');
        self::assertSame('SELECT * FROM "a" LEFT JOIN "b" ON "a"."id" = "b"."a_id"', $q->toSql());
    }

    public function testOrderByDirectionLowercaseNormalised(): void
    {
        $q = QueryBuilder::table('t')->orderBy('a', 'dEsC');
        self::assertSame('SELECT * FROM "t" ORDER BY "a" DESC', $q->toSql());
    }

    public function testIdentifierWhitespaceTolerated(): void
    {
        self::assertSame('SELECT * FROM "users"', QueryBuilder::table(' users ')->toSql());
        self::assertSame('SELECT * FROM "t" WHERE "a" = ?', QueryBuilder::table('t')->where(' a ', '=', 1)->toSql());
    }

    public function testIdentifierAnchorsAreEnforced(): void
    {
        // Digit start: with ^ removed, the inner 'abc' would match anywhere.
        try {
            QueryBuilder::table('t')->quoteIdentifier('1abc');
            self::fail('digit-start identifier must be rejected');
        } catch (QueryException $e) {
            self::assertSame("Invalid identifier '1abc'.", $e->getMessage());
        }

        // Trailing junk: with $ removed, the leading 'abc' would match.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid identifier 'abc!'.");
        QueryBuilder::table('t')->quoteIdentifier('abc!');
    }

    public function testSelectAliasRegexIsAnchored(): void
    {
        // Space inside the path: must NOT be parsed as `path AS alias`.
        try {
            QueryBuilder::table('t')->select('x y AS z');
            self::fail('multi-word path must be rejected');
        } catch (QueryException $e) {
            self::assertStringStartsWith("Invalid column 'x y AS z'.", $e->getMessage());
            // v2.18.0 audit: raw-SQL-looking mistakes point at the escape hatch.
            self::assertStringContainsString('SqlExpression', $e->getMessage());
        }

        // Trailing junk after the alias must NOT be silently accepted.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'a AS z drop'.");
        QueryBuilder::table('t')->select('a AS z drop');
    }

    public function testWhereBetweenRejectsNonScalars(): void
    {
        try {
            QueryBuilder::table('t')->whereBetween('a', [1], 2);
            self::fail('array low bound must be rejected');
        } catch (QueryException $e) {
            self::assertSame(
                'whereBetween() low must be null, bool, int, float, string or SqlExpression (got array).',
                $e->getMessage(),
            );
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'whereBetween() high must be null, bool, int, float, string or SqlExpression (got stdClass).',
        );
        QueryBuilder::table('t')->whereBetween('a', 1, new \stdClass());
    }

    public function testWhereWithNullOnNonEqualityOperatorsBinds(): void
    {
        $q = QueryBuilder::table('t')->where('a', '>', null);
        self::assertSame('SELECT * FROM "t" WHERE "a" > ?', $q->toSql());
        self::assertSame([null], $q->getBindings());
    }

    public function testInsertRowsRejectsNonScalarValues(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            "insertRows() row 0 value for 'a' must be null, bool, int, float, string or SqlExpression (got array).",
        );
        QueryBuilder::table('t')->insertRows([['a' => [1]]]);
    }

    public function testUpdateRejectsNonScalarValues(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            "update() value for 'a' must be null, bool, int, float, string or SqlExpression (got array).",
        );
        QueryBuilder::table('t')->update(['a' => [1]]);
    }

    public function testSubqueryParamsMergeAfterOuterParams(): void
    {
        $sub = QueryBuilder::table('other')->select('id')->where('x', '=', 9);
        $q = QueryBuilder::table('t')
            ->where('a', '=', 1)
            ->whereIn('b', $sub)
        ;

        self::assertSame(
            'SELECT * FROM "t" WHERE "a" = ? AND "b" IN (SELECT "id" FROM "other" WHERE "x" = ?)',
            $q->toSql(),
        );
        self::assertSame([1, 9], $q->getBindings());
    }

    public function testLimitOffsetBoundaries(): void
    {
        self::assertSame('SELECT * FROM "t" LIMIT 5 OFFSET 3', QueryBuilder::table('t')->limit(5)->offset(3)->toSql());

        try {
            QueryBuilder::table('t')->limit(-2);
            self::fail('limit(-2) must be rejected');
        } catch (QueryException $e) {
            self::assertSame('limit() must be >= 0.', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('offset() must be >= 0.');
        QueryBuilder::table('t')->limit(5)->offset(-2);
    }

    public function testNestingDepthBoundary(): void
    {
        for ($i = 1; $i <= 16; ++$i) {
            $this->conn->beginTransaction();
            self::assertSame($i, $this->conn->transactionLevel());
        }

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Transaction nesting limit of 16 exceeded.');
        $this->conn->beginTransaction();
    }

    public function testErrorMessageShapesAreStructured(): void
    {
        try {
            $this->conn->execute(SqlQuery::raw('SELECT * FROM missing'));
            self::fail('bad SQL must throw');
        } catch (QueryException $e) {
            self::assertMatchesRegularExpression(
                '/^Preparation failed: .+$/',
                $e->getMessage(),
            );
            self::assertSame('SELECT * FROM missing', $e->getSql());
            self::assertStringNotContainsString('sql:', $e->getMessage());
        }

        try {
            $this->conn->execute(SqlQuery::raw('INSERT INTO t (id) VALUES (1)'));
            $this->conn->execute(SqlQuery::raw('INSERT INTO t (id) VALUES (1)'));
            self::fail('duplicate insert must throw');
        } catch (QueryException $e) {
            self::assertMatchesRegularExpression(
                '/^Execution failed: .+$/',
                $e->getMessage(),
            );
            self::assertSame('INSERT INTO t (id) VALUES (1)', $e->getSql());
        }
    }

    public function testConnectFailureMessageIsStructured(): void
    {
        $conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'dbname' => 'x', 'port' => 1,
        ]));

        try {
            $conn->execute(SqlQuery::raw('SELECT 1'));
            self::fail('connect must fail');
        } catch (ConnectionException $e) {
            self::assertMatchesRegularExpression('/^Connection failed \(mysql\): .+$/', $e->getMessage());
        }
    }

    public function testTransactionRollbackFailureWrapped(): void
    {
        // Commit outside a transaction keeps its own exact message; the
        // begin-failure path wraps the PDO message with its prefix.
        try {
            $this->conn->commit();
            self::fail('commit outside transaction must throw');
        } catch (TransactionException $e) {
            self::assertSame('commit() called outside a transaction.', $e->getMessage());
        }
        self::assertSame(0, $this->conn->transactionLevel());
    }

    // ------------------------------------------------------------------
    // Migrator — lock visibility, multi-step rollback, boundaries
    // ------------------------------------------------------------------

    public function testLockRowIsVisibleWhileMigrationRuns(): void
    {
        $seenLock = new \stdClass();
        $seenLock->rows = null;
        $migration = new readonly class($seenLock) implements MigrationInterface {
            public function __construct(
                private \stdClass $seen,
            ) {}

            public function version(): string
            {
                return '20260101000001';
            }

            public function getLockTtl(): ?float
            {
                return null;
            }

            public function name(): string
            {
                return 'probe';
            }

            public function up(ConnectionInterface $connection): void
            {
                $this->seen->rows = $connection->fetchAll(
                    QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
                );
            }

            public function down(ConnectionInterface $connection): void {}
        };

        $migrator = new Migrator($this->conn, static fn (): int => 100, 300.0);
        $migrator->register($migration);
        $migrator->migrate();

        // up() saw exactly one lock row held by the migrator itself.
        self::assertSame([['id' => 1]], $seenLock->rows);

        // After migrate() the lock is gone again.
        self::assertSame([], $this->conn->fetchAll(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
        ));
    }

    public function testRollbackTwoStepsRollsBothInDescendingOrder(): void
    {
        $orderBox = new \stdClass();
        $orderBox->log = [];
        $mk = static fn (string $v, string $t): MigrationInterface => new readonly class($v, $t, $orderBox) implements MigrationInterface {
            public function __construct(
                private string $v,
                private string $t,
                private \stdClass $box,
            ) {}

            public function version(): string
            {
                return $this->v;
            }

            public function getLockTtl(): ?float
            {
                return null;
            }

            public function name(): string
            {
                return $this->t;
            }

            public function up(ConnectionInterface $connection): void
            {
                // @phpstan-ignore-next-line dynamic log box (test-only holder)
                $this->box->log[] = 'up:' . $this->t;
            }

            public function down(ConnectionInterface $connection): void
            {
                // @phpstan-ignore-next-line dynamic log box (test-only holder)
                $this->box->log[] = 'down:' . $this->t;
            }
        };

        $m = new Migrator($this->conn, static fn (): int => 100);
        $m->register($mk('20260101000001', 'a'));
        $m->register($mk('20260101000002', 'b'));
        $m->migrate();

        self::assertSame(['20260101000002', '20260101000001'], $m->rollback(2));
        self::assertSame(['up:a', 'up:b', 'down:b', 'down:a'], $orderBox->log);
        self::assertSame([], $m->applied());
    }

    public function testMigrationNameBoundary128Accepted(): void
    {
        $m = new Migrator($this->conn, static fn (): int => 100);
        $m->register($this->migration('20260101000001', str_repeat('x', 128)));
        self::assertSame(['20260101000001'], $m->plan());
    }

    public function testLockStealsExactlyAtTtlBoundary(): void
    {
        $conn = $this->conn;
        $conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, "locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));
        // age = 300 == ttl 300 → steal (not blocked).
        $conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)
                ->insert(['id' => 1, 'locked_at' => 100 - 300, 'ttl' => 300.0])->build(),
        );
        $m = new Migrator($conn, static fn (): int => 100, 300.0);
        self::assertSame([], $m->migrate());
    }

    public function testLockBlocksBelowTtlBoundary(): void
    {
        $conn = $this->conn;
        $conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, "locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));
        // age = 299 < ttl 300 → blocked.
        $conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)
                ->insert(['id' => 1, 'locked_at' => 100 - 299, 'ttl' => 300.0])->build(),
        );
        $blocked = new Migrator($conn, static fn (): int => 100, 300.0);

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Migration lock is already held (age 299s, ttl 300s).');
        $blocked->migrate();
    }

    // ------------------------------------------------------------------
    // ConnectionConfig — remaining DSN/guard branches
    // ------------------------------------------------------------------

    public function testPgsqlDsnWithClientEncoding(): void
    {
        $c = ConnectionConfig::fromArray(['driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd', 'charset' => 'UTF8']);
        self::assertSame("pgsql:host=h;port=5432;dbname=d;options='--client_encoding=UTF8'", $c->dsn());
    }

    public function testHostMaxLengthEnforced(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Host must be a non-empty string without whitespace for driver 'mysql'.");
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => str_repeat('a', 256), 'dbname' => 'd']);
    }

    public function testPortNumericStringBoundaries(): void
    {
        // Leading zeros are still 5 digits and valid.
        self::assertSame(1, ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => '00001'])->port);

        // 6 digits must be rejected.
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Port must be an int or a numeric string (1-65535).');
        ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => '012345']);
    }

    private function migration(string $version, string $name): MigrationInterface
    {
        return new readonly class($version, $name) implements MigrationInterface {
            public function __construct(
                private string $v,
                private string $n,
            ) {}

            public function version(): string
            {
                return $this->v;
            }

            public function getLockTtl(): ?float
            {
                return null;
            }

            public function name(): string
            {
                return $this->n;
            }

            public function up(ConnectionInterface $connection): void {}

            public function down(ConnectionInterface $connection): void {}
        };
    }
}
