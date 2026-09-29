<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\Migrator;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;

/**
 * v2.18.0 — Database Core: mutation kill round 4 (final micro-round):
 * fetchAll execution-error messages, exact savepoint level transitions,
 * lock re-acquisition on the same Migrator, and NOT-IN subquery SQL.
 *
 * @internal
 */
final class DatabaseKillRound4Test extends TestCase
{
    private PdoConnection $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY)'));
    }

    public function testFetchAllExecutionErrorMessageIsStructured(): void
    {
        // Prepares fine, fails at execution (duplicate primary key).
        $this->conn->execute(SqlQuery::raw('INSERT INTO t (id) VALUES (1)'));

        try {
            $this->conn->fetchAll(SqlQuery::raw('INSERT INTO t (id) VALUES (1)'));
            self::fail('duplicate insert via fetchAll must throw');
        } catch (QueryException $e) {
            self::assertMatchesRegularExpression(
                '/^Execution failed: .+$/',
                $e->getMessage(),
            );
            self::assertSame('INSERT INTO t (id) VALUES (1)', $e->getSql());
        }
    }

    public function testSavepointLevelTransitionsAreExact(): void
    {
        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        self::assertSame(2, $this->conn->transactionLevel());

        $this->conn->commit();
        self::assertSame(1, $this->conn->transactionLevel());

        $this->conn->rollBack();
        self::assertSame(0, $this->conn->transactionLevel());

        // Re-enter and unwind through rollback at both depths.
        $this->conn->beginTransaction();
        $this->conn->beginTransaction();
        $this->conn->rollBack();
        self::assertSame(1, $this->conn->transactionLevel());
        $this->conn->rollBack();
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testMigratorLockIsReacquirableOnSameInstance(): void
    {
        $conn = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']));
        $m = new Migrator($conn, static fn (): int => 100);
        $m->register($this->noop('20260101000001'));

        self::assertSame(['20260101000001'], $m->migrate());
        self::assertSame([], $m->migrate());
        self::assertSame(['20260101000001'], $m->rollback());
        self::assertSame(['20260101000001'], $m->migrate());
        self::assertSame(['20260101000001'], $m->applied());
    }

    public function testWhereNotInSubquerySql(): void
    {
        $sub = QueryBuilder::table('other')->select('id');
        $q = QueryBuilder::table('t')->whereNotIn('a', $sub);

        self::assertSame(
            'SELECT * FROM "t" WHERE "a" NOT IN (SELECT "id" FROM "other")',
            $q->toSql(),
        );
        self::assertSame([], $q->getBindings());
    }

    public function testNestedOrGroupInsideWhereChain(): void
    {
        $q = QueryBuilder::table('t')
            ->where('a', '=', 1)
            ->whereNested(static function (QueryBuilder $n): void {
                $n->where('b', '=', 2)->orWhere('c', '=', 3);
            })
            ->where('d', '=', 4)
        ;

        self::assertSame(
            'SELECT * FROM "t" WHERE "a" = ? AND ("b" = ? OR "c" = ?) AND "d" = ?',
            $q->toSql(),
        );
        self::assertSame([1, 2, 3, 4], $q->getBindings());
    }

    private function noop(string $version): MigrationInterface
    {
        return new readonly class($version) implements MigrationInterface {
            public function __construct(
                private string $v,
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
                return 'noop';
            }

            public function up(ConnectionInterface $connection): void {}

            public function down(ConnectionInterface $connection): void {}
        };
    }
}
