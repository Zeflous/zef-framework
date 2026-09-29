<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\MigrationLock;
use Zef\Framework\Database\Migrator;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * v2.18.0 — Database Core: Migrator edge matrix (real SQLite + injected
 * wall-clock): ordering, bookkeeping, rollback, lock acquire/steal/release,
 * failure atomicity, strict registration grammar.
 *
 * @internal
 */
final class DatabaseMigratorTest extends TestCase
{
    private const int T0 = 1_700_000_000;

    private PdoConnection $conn;

    private int $now = self::T0;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->now = self::T0;
    }

    public function testRegistrationGrammar(): void
    {
        $m = $this->migrator();

        try {
            $m->register($this->migration('20260101', ['SELECT 1']));
            self::fail('short version must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                "Migration version '20260101' must be a 14-digit timestamp (YYYYmmddHHMMSS).",
                $e->getMessage(),
            );
        }

        try {
            $m->register($this->migration('2026010100000a', ['SELECT 1']));
            self::fail('non-digit version must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                "Migration version '2026010100000a' must be a 14-digit timestamp (YYYYmmddHHMMSS).",
                $e->getMessage(),
            );
        }

        try {
            $m->register($this->migration('20260101000001', ['SELECT 1'], name: str_repeat('x', 129)));
            self::fail('129-byte name must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Migration name must be a string of 1..128 bytes.', $e->getMessage());
        }

        try {
            $m->register($this->migration('20260101000001', ['SELECT 1'], name: ''));
            self::fail('empty name must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Migration name must be a string of 1..128 bytes.', $e->getMessage());
        }

        $m->register($this->migration('20260101000001', ['SELECT 1']));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Migration version '20260101000001' is already registered.");
        $m->register($this->migration('20260101000001', ['SELECT 2']));
    }

    public function testLockTtlMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration lock TTL must be greater than zero.');
        new Migrator($this->conn, null, 0.0);
    }

    public function testMigrateAppliesPendingInOrderAndRecords(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000002', ['CREATE TABLE b (x)'], name: 'b'));
        $m->register($this->migration('20260101000001', ['CREATE TABLE a (x)'], name: 'a'));

        self::assertSame(['20260101000001', '20260101000002'], $m->plan());
        self::assertSame(['20260101000001', '20260101000002'], $m->migrate());
        self::assertSame([], $m->plan());
        self::assertSame(['20260101000001', '20260101000002'], $m->applied());
        // Table existence proves up() really ran.
        $this->conn->execute(SqlQuery::raw('INSERT INTO a (x) VALUES (1)'));
        $this->conn->execute(SqlQuery::raw('INSERT INTO b (x) VALUES (2)'));
    }

    public function testRollbackDescendingAndBookkeeping(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', ['CREATE TABLE a (x)'], ['DROP TABLE a']));
        $m->register($this->migration('20260101000002', ['CREATE TABLE b (x)'], ['DROP TABLE b']));
        $m->migrate();

        self::assertSame(['20260101000002'], $m->rollback());
        self::assertSame(['20260101000001'], $m->applied());
        self::assertSame(['20260101000002'], $m->plan());

        self::assertSame(['20260101000001'], $m->rollback());
        self::assertSame([], $m->applied());
        self::assertSame(['20260101000001', '20260101000002'], $m->plan());
    }

    public function testRollbackGuards(): void
    {
        $m = $this->migrator();

        try {
            $m->rollback(0);
            self::fail('rollback(0) must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Rollback steps must be >= 1.', $e->getMessage());
        }

        $m->register($this->migration('20260101000001', ['CREATE TABLE a (x)']));
        $m->migrate();

        try {
            $m->rollback(2);
            self::fail('rollback beyond applied must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Requested 2 rollback step(s) but only 1 applied migration(s) exist.', $e->getMessage());
        }

        // Unregistered-but-applied version cannot roll back.
        $this->conn->execute(
            QueryBuilder::table(Migrator::MIGRATIONS_TABLE)
                ->insert(['version' => '20250101000000', 'name' => 'ghost', 'applied_at' => 1])->build(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Applied migration '20250101000000' is not registered; cannot roll back.");
        $m->rollback(2);
    }

    public function testFailedUpRollsBackAndRecordsNothing(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', [
            'CREATE TABLE ok (x)',
            'CREATE TABLE boom (x',
        ]));
        $this->now = self::T0;

        try {
            $m->migrate();
            self::fail('broken migration must fail');
        } catch (QueryException) {
        }

        self::assertSame([], $m->applied());
        self::assertSame([], $this->conn->fetchAll(
            QueryBuilder::table(Migrator::MIGRATIONS_TABLE)->select('version')->build(),
        ));
        // Lock must be released even after failure.
        self::assertSame([], $this->conn->fetchAll(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
        ));
    }

    public function testLockBlocksSecondRunnerUntilTtlExpiry(): void
    {
        $first = $this->migrator(300.0);
        // Acquire the lock manually through a migrate of an empty registry.
        $first->migrate();

        // The lock row was released, so a second runner may acquire.
        $second = $this->migrator(300.0);
        $second->migrate();

        // Simulate a crashed runner: insert a stale lock row directly.
        $this->conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)->insert(['id' => 1, 'locked_at' => $this->now - 100, 'ttl' => 300.0])->build(),
        );

        $third = $this->migrator(300.0);

        try {
            $third->migrate();
            self::fail('fresh lock must block');
        } catch (TransactionException $e) {
            self::assertSame('Migration lock is already held (age 100s, ttl 300s).', $e->getMessage());
        }

        // After the ROW's declared ttl expires the lock is stolen — the
        // row ttl (300s, set by the crashed holder) is authoritative,
        // not the recovering runner's default (50s). Push past it.
        $this->now += 200; // age 300s >= row ttl 300s
        $stale = $this->migrator(50.0);
        $stale->migrate();
        self::assertSame([], $this->conn->fetchAll(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('id')->build(),
        ));
    }

    public function testLockReentrancyRejected(): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, "locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));

        $m = $this->migrator();
        $lock = (new \ReflectionProperty(Migrator::class, 'lock'))->getValue($m);
        (new \ReflectionProperty(MigrationLock::class, 'depth'))->setValue($lock, 1);

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Migration lock is already held by this Migrator instance.');
        $m->migrate();
    }

    public function testPlanDoesNotRequireAppliedData(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', ['SELECT 1']));
        // Fresh database: plan() must still work (schema auto-ensured).
        self::assertSame(['20260101000001'], $m->plan());
        self::assertSame([], $m->applied());
    }

    public function testAppliedAtUsesInjectedClock(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', ['SELECT 1']));
        $this->now = 1_234_567_890;
        $m->migrate();

        $row = $this->conn->fetchOne(
            QueryBuilder::table(Migrator::MIGRATIONS_TABLE)->select('applied_at', 'name')->build(),
        );
        self::assertNotNull($row);
        $appliedAt = $row['applied_at'];
        self::assertTrue(is_int($appliedAt) || is_string($appliedAt) || is_float($appliedAt));
        self::assertSame(1_234_567_890, (int) $appliedAt);
        self::assertSame('m', $row['name']);
    }

    private function migrator(float $ttl = 300.0): Migrator
    {
        return new Migrator($this->conn, fn (): int => $this->now, $ttl);
    }

    /**
     * @param list<string> $up
     * @param list<string> $down
     */
    private function migration(string $version, array $up, array $down = ['SELECT 1'], string $name = 'm'): MigrationInterface
    {
        return new readonly class($version, $name, $up, $down) implements MigrationInterface {
            public function __construct(
                private string $v,
                private string $n,
                /** @var list<string> */
                private array $up,
                /** @var list<string> */
                private array $down,
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

            public function up(ConnectionInterface $connection): void
            {
                foreach ($this->up as $sql) {
                    $connection->execute(SqlQuery::raw($sql));
                }
            }

            public function down(ConnectionInterface $connection): void
            {
                foreach ($this->down as $sql) {
                    $connection->execute(SqlQuery::raw($sql));
                }
            }
        };
    }
}
