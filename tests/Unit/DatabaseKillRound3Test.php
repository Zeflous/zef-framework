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
use Zef\Framework\Database\TransactionException;

/**
 * v2.18.0 — Database Core: mutation kill round 3. Shared-PDO failure
 * wrappers, direct pending() visibility, rollback lock probe, offset(0)
 * boundary, and identifier validation inside data-bearing methods.
 *
 * @internal
 */
final class DatabaseKillRound3Test extends TestCase
{
    // ------------------------------------------------------------------
    // PdoConnection — transaction wrapper failure messages (shared PDO)
    // ------------------------------------------------------------------

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    }

    public function testBeginTransactionFailureWrapped(): void
    {
        $a = $this->wrap();
        $b = $this->wrap();
        $a->beginTransaction();

        try {
            $b->beginTransaction();
            self::fail('second begin on the same PDO must fail');
        } catch (TransactionException $e) {
            self::assertMatchesRegularExpression('/^Failed to begin transaction: .+$/', $e->getMessage());
            self::assertSame(0, $b->transactionLevel());
        }
    }

    public function testFetchMessageShapesAreStructured(): void
    {
        $conn = $this->wrap();

        try {
            $conn->fetchAll(SqlQuery::raw('SELECT * FROM missing'));
            self::fail('fetchAll on bad SQL must throw');
        } catch (QueryException $e) {
            self::assertMatchesRegularExpression('/^Preparation failed: .+$/', $e->getMessage());
            self::assertSame('SELECT * FROM missing', $e->getSql());
            self::assertStringNotContainsString('sql:', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Preparation failed:');
        $conn->fetchOne(SqlQuery::raw('SELECT * FROM missing'));
    }

    // SQLite implicitly opens a transaction for SAVEPOINT, so the
    // runStatement failure branch is driver-unreachable here — triaged.

    // ------------------------------------------------------------------
    // Migrator — pending() direct + rollback lock probe
    // ------------------------------------------------------------------

    public function testPendingIsPubliclyListed(): void
    {
        $conn = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']));
        $m = new Migrator($conn, static fn (): int => 100);
        $m->register($this->probe('20260101000001'));
        $m->register($this->probe('20260101000002'));

        self::assertSame(['20260101000001', '20260101000002'], array_map(
            static fn (MigrationInterface $m): string => $m->version(),
            $m->pending(),
        ));
    }

    public function testRollbackAcquiresTheLock(): void
    {
        $conn = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']));
        $conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, "locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));
        $conn->execute(SqlQuery::raw('CREATE TABLE x (id INTEGER)'));

        $seen = new \stdClass();
        $seen->lockRows = null;
        $migration = new readonly class($conn, $seen) implements MigrationInterface {
            public function __construct(
                private ConnectionInterface $c,
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

            public function up(ConnectionInterface $connection): void {}

            public function down(ConnectionInterface $connection): void
            {
                $this->seen->lockRows = $this->c->fetchAll(
                    QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
                );
            }
        };

        $m = new Migrator($conn, static fn (): int => 100, 300.0);
        $m->register($migration);
        $m->migrate();
        $m->rollback();

        // down() ran while the rollback held the lock…
        self::assertSame([['id' => 1]], $seen->lockRows);
        // …and the lock was released afterwards.
        self::assertSame([], $conn->fetchAll(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
        ));
    }

    // ------------------------------------------------------------------
    // QueryBuilder — remaining reachable guards
    // ------------------------------------------------------------------

    public function testOffsetZeroIsAllowedWithLimit(): void
    {
        $q = QueryBuilder::table('t')->limit(5)->offset(0);
        self::assertSame('SELECT * FROM "t" LIMIT 5 OFFSET 0', $q->toSql());
    }

    public function testInsertRowsValidatesColumnNames(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'bad name'.");
        QueryBuilder::table('t')->insertRows([['bad name' => 1]]);
    }

    public function testUpdateValidatesColumnNames(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Invalid column 'bad name'.");
        QueryBuilder::table('t')->update(['bad name' => 1]);
    }

    // ------------------------------------------------------------------
    // ConnectionConfig — port & host boundaries
    // ------------------------------------------------------------------

    public function testPortExtremeValuesAccepted(): void
    {
        self::assertSame(1, ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => 1])->port);
        self::assertSame(65535, ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => 'h', 'dbname' => 'd', 'port' => 65535])->port);
    }

    public function testHostExactly255BytesAccepted(): void
    {
        $c = ConnectionConfig::fromArray(['driver' => 'mysql', 'host' => str_repeat('a', 255), 'dbname' => 'd']);
        self::assertSame(255, strlen((string) $c->host));
    }

    public function testSqlitePathBoundaryLength(): void
    {
        // Exactly 4096 printable bytes is still accepted.
        $path = '/' . str_repeat('a', 4095);
        $c = ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => $path]);
        self::assertSame('sqlite:' . $path, $c->dsn());
    }

    private function wrap(): PdoConnection
    {
        return new PdoConnection(
            ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']),
            $this->pdo,
        );
    }

    private function probe(string $version): MigrationInterface
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
                return 'probe';
            }

            public function up(ConnectionInterface $connection): void {}

            public function down(ConnectionInterface $connection): void {}
        };
    }
}
