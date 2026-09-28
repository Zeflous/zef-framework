<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regresi I-12 (issue #175): the migration lock is a
 * holder-token compare-and-swap protocol.
 *
 * renewLock() used to blindly UPDATE the lock row with no ownership
 * check, and the stale-lock steal was check-then-act, so two runners
 * could migrate concurrently. Every lock row now carries a random
 * `holder` token (per Migrator instance): renewal is
 * `UPDATE ... WHERE holder = token` (0 rows = lost), the steal is ONE
 * conditional UPDATE on the row's own TTL (0 rows = race lost), and
 * release is a compare-and-delete. Deterministic via injected
 * wall-clock on real SQLite in-memory.
 *
 * @internal
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\Migrator;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;

/**
 * @internal
 */
final class DatabaseMigratorHolderCasTest extends TestCase
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

    // ------------------------------------------------------------- tests

    /**
     * Regresi I-12 (issue #175): renewal after the holder token was
     * replaced by another runner FAILS — the heartbeat aborts the run
     * instead of blindly refreshing somebody else's lease, the second
     * step is never applied, and the release's compare-and-delete never
     * removes the row the new holder owns.
     */
    public function testRenewAbortsAfterAnotherRunnerReplacedTheHolderToken(): void
    {
        $m = $this->migrator();
        $m->register($this->migration('20260101000001', null, function (ConnectionInterface $c): void {
            // Ownership loss mid-run: a second runner crossed the TTL
            // boundary and stole the row (or the row was hand-repaired).
            // Either way the holder token is no longer ours.
            $c->execute(
                QueryBuilder::table(Migrator::LOCK_TABLE)
                    ->update(['holder' => 'e5f11a2d1cd3f6d1e5f11a2d1cd3f6d1'])
                    ->where('id', '=', 1)
                    ->build(),
            );
        }));
        $m->register($this->migration('20260101000002'));

        try {
            $m->migrate();
            self::fail('the heartbeat must abort once the holder token is replaced');
        } catch (TransactionException $e) {
            self::assertSame(
                'Migration lock renewal failed: this runner no longer holds the lock (lost or stolen).',
                $e->getMessage(),
            );
        }

        self::assertSame(
            ['20260101000001'],
            $m->applied(),
            'the step AFTER the ownership loss must never run',
        );
        $row = $this->lockRow();
        self::assertNotNull($row, 'the release must not delete the row its thief now owns');
        self::assertSame('e5f11a2d1cd3f6d1e5f11a2d1cd3f6d1', $row['holder']);
    }

    /**
     * Regresi I-12 (issue #175): a stale row IS stolen — atomically, and
     * the winner stamps its own holder token on the row for every later
     * renew/release to act on.
     */
    public function testStaleRowIsStolenAndStampedWithOurHolderToken(): void
    {
        /** @var list<array<string, mixed>> $captured */
        $captured = [];
        $this->seedLockRow(self::T0 - 400, 100.0, 'deadbeefdeadbeefdeadbeefdeadbeef');
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001', null, static function (ConnectionInterface $c) use (&$captured): void {
            $captured = $c->fetchAll(
                QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl', 'holder')->build(),
            );
        }));

        self::assertSame(['20260101000001'], $m->migrate(), 'an expired row TTL must be stealable');
        self::assertCount(1, $captured, 'up() must observe the stolen lock row');
        $stolenHolder = $captured[0]['holder'] ?? null;
        self::assertIsString($stolenHolder);
        self::assertNotSame('deadbeefdeadbeefdeadbeefdeadbeef', $stolenHolder, 'the steal must stamp the new holder token');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stolenHolder, 'the holder token is the per-instance random token');
        self::assertSame(self::T0, $captured[0]['locked_at'], 'the steal renews locked_at');
        self::assertSame(300.0, $captured[0]['ttl'], 'the steal records the stealer TTL');
        self::assertNull($this->lockRow(), 'the stealer releases the lock when done');
    }

    /**
     * Regresi I-12 (issue #175): a fresh row CANNOT be stolen — the
     * pre-check reports it as held, and the failed attempt leaves the
     * live holder's token untouched.
     */
    public function testFreshRowCannotBeStolenAndKeepsItsHolder(): void
    {
        $this->seedLockRow(self::T0, 900.0, 'liveholderliveholderliveholder1');
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001'));

        try {
            $m->migrate();
            self::fail('a fresh row must not be stealable');
        } catch (TransactionException $e) {
            self::assertStringContainsString('already held (age 0s, ttl 900s)', $e->getMessage());
        }

        $row = $this->lockRow();
        self::assertNotNull($row, 'the live holder must keep its lock');
        self::assertSame('liveholderliveholderliveholder1', $row['holder'], 'a failed steal must not overwrite the holder token');
        self::assertSame([], $m->applied(), 'nothing migrated');
    }

    /**
     * Regresi I-12 (issue #175): the steal is now ONE conditional UPDATE,
     * so two runners racing on the same stale row can never both win —
     * the loser's UPDATE matches 0 rows and the run aborts. The winning
     * runner's renewal is left intact.
     */
    public function testStealLosesTheRaceAgainstAFasterRunner(): void
    {
        $this->seedLockRow(self::T0 - 400, 100.0, 'deadbeefdeadbeefdeadbeefdeadbeef');
        // The racing double: at the exact moment THIS runner fires its
        // conditional steal UPDATE, a faster runner's steal commits first
        // (renewing the row with its own token and TTL).
        $racing = new StealRacingConnection($this->conn, Migrator::LOCK_TABLE, fn (): int => $this->now, 300.0);
        $m = new Migrator($racing, fn (): int => $this->now, 300.0);
        $m->register($this->migration('20260101000001'));

        try {
            $m->migrate();
            self::fail('the slower steal must lose the race');
        } catch (TransactionException $e) {
            self::assertSame(
                'Migration lock steal lost the race: the row was renewed or stolen concurrently.',
                $e->getMessage(),
            );
        }

        $row = $this->lockRow();
        self::assertNotNull($row, 'the winner keeps the lock');
        self::assertNotSame('deadbeefdeadbeefdeadbeefdeadbeef', $row['holder'], 'the racing winner took ownership');
        self::assertSame(self::T0, $row['locked_at'], 'the winner renewed the row');
        self::assertSame(300.0, $row['ttl']);
        self::assertSame([], $m->applied(), 'the loser migrated nothing');
    }

    /**
     * Regresi I-12 (issue #175): lock tables created before v2.31.0 have
     * no holder column — the table-ensure path upgrades them in place
     * (best-effort ALTER TABLE, duplicate-column swallowed), and the CAS
     * protocol then works on the upgraded table (legacy rows carry the
     * column default '').
     */
    public function testLegacyLockTableIsUpgradedWithTheHolderColumn(): void
    {
        // Legacy pre-v2.31.0 DDL: no holder column, fresh (unstealable) row.
        $this->seedLegacyLockRowWithoutHolder(self::T0, 900.0);
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001'));

        try {
            $m->migrate();
            self::fail('a fresh legacy row must still block');
        } catch (TransactionException $e) {
            self::assertStringContainsString('already held (age 0s, ttl 900s)', $e->getMessage());
        }

        // The upgrade has been applied by the failed attempt already: the
        // holder column now exists (default '' on the legacy row).
        $row = $this->lockRow();
        self::assertNotNull($row);
        self::assertSame('', $row['holder'], 'the legacy row carries the column default until someone owns it');

        // Once the legacy row goes stale it is stolen through the same
        // conditional UPDATE — now stamped with the stealer's token.
        $this->now = self::T0 + 1000;

        /** @var list<array<string, mixed>> $captured */
        $captured = [];
        $stealer = $this->migrator(300.0);
        $stealer->register($this->migration('20260101000001', null, static function (ConnectionInterface $c) use (&$captured): void {
            $captured = $c->fetchAll(
                QueryBuilder::table(Migrator::LOCK_TABLE)->select('holder')->build(),
            );
        }));

        self::assertSame(['20260101000001'], $stealer->migrate(), 'a stale legacy row must be stealable after the upgrade');
        self::assertCount(1, $captured);
        $upgradedHolder = $captured[0]['holder'] ?? null;
        self::assertIsString($upgradedHolder);
        self::assertNotSame('', $upgradedHolder, 'the stealer owns the upgraded row');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $upgradedHolder);
    }

    /**
     * Regresi I-12 (issue #175): a runner that ACQUIRED the lock renews
     * happily — the holder CAS must not break the heartbeat of the
     * rightful owner across several steps.
     */
    public function testRightfulOwnerRenewsAcrossStepsUninterrupted(): void
    {
        $m = $this->migrator(300.0);
        $m->register($this->migration('20260101000001'));
        $m->register($this->migration('20260101000002'));

        $this->now = self::T0 + 50; // well within the TTL, between steps

        self::assertSame(['20260101000001', '20260101000002'], $m->migrate());
        self::assertNull($this->lockRow(), 'the owner releases the lock when done');
    }

    // ---------------------------------------------------------- fixtures

    private function migrator(float $ttl = 300.0): Migrator
    {
        return new Migrator($this->conn, fn (): int => $this->now, $ttl);
    }

    /** @return null|array<string, mixed> */
    private function lockRow(): ?array
    {
        return $this->conn->fetchOne(
            QueryBuilder::table(Migrator::LOCK_TABLE)->select('locked_at', 'ttl', 'holder')->where('id', '=', 1)->build(),
        );
    }

    private function seedLockRow(int $lockedAt, float $ttl, string $holder): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, '
            . '"locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL, "holder" VARCHAR(64) NOT NULL DEFAULT \'\')',
        ));
        $this->conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)
                ->insert(['id' => 1, 'locked_at' => $lockedAt, 'ttl' => $ttl, 'holder' => $holder])->build(),
        );
    }

    /**
     * Legacy pre-v2.31.0 lock table: NO holder column (the exact DDL the
     * pre-upgrade CREATE TABLE produced).
     */
    private function seedLegacyLockRowWithoutHolder(int $lockedAt, float $ttl): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . Migrator::LOCK_TABLE . '" ("id" INTEGER NOT NULL PRIMARY KEY, '
            . '"locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL)',
        ));
        $this->conn->execute(
            QueryBuilder::table(Migrator::LOCK_TABLE)->insert(['id' => 1, 'locked_at' => $lockedAt, 'ttl' => $ttl])->build(),
        );
    }

    private function migration(
        string $version,
        ?float $ttl = null,
        ?\Closure $up = null,
        ?\Closure $down = null,
    ): MigrationInterface {
        return new readonly class(
            $version,
            $ttl,
            $up ?? static function (ConnectionInterface $c): void {},
            $down ?? static function (ConnectionInterface $c): void {},
        ) implements MigrationInterface {
            public function __construct(
                private string $v,
                private ?float $ttl,
                private \Closure $up,
                private \Closure $down,
            ) {}

            public function version(): string
            {
                return $this->v;
            }

            public function name(): string
            {
                return 'm' . $this->v;
            }

            public function up(ConnectionInterface $connection): void
            {
                ($this->up)($connection);
            }

            public function down(ConnectionInterface $connection): void
            {
                ($this->down)($connection);
            }

            public function getLockTtl(): ?float
            {
                return $this->ttl;
            }
        };
    }
}

/**
 * Regresi I-12 (issue #175) racing double (the RaceLosingConnection
 * pattern): when the Migrator under test fires its conditional steal
 * UPDATE (the ONLY statement shaped `... "locked_at" + "ttl" <= ?`), a
 * faster runner's steal is committed FIRST — the row is renewed with the
 * winner's token and TTL before the victim's UPDATE is delegated, so the
 * victim observes the real TOCTOU outcome: 0 affected rows.
 *
 * @internal
 */
final class StealRacingConnection implements ConnectionInterface
{
    /** @var callable(): int */
    private $now;

    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly string $lockTable,
        callable $now,
        private readonly float $winnerTtl,
    ) {
        $this->now = $now;
    }

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        if (str_contains($query->sql, 'UPDATE "' . $this->lockTable . '"')
            && str_contains($query->sql, '"locked_at" + "ttl"')) {
            // The faster runner's steal lands in between: it renews the
            // stale row with its own holder token and TTL.
            $this->inner->execute(
                QueryBuilder::table($this->lockTable)
                    ->update(['locked_at' => ($this->now)(), 'ttl' => $this->winnerTtl, 'holder' => 'faster-runner-faster-runner-fast'])
                    ->where('id', '=', 1)
                    ->build(),
            );
        }

        return $this->inner->execute($query);
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return $this->inner->fetchAll($query);
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->inner->fetchOne($query);
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->inner->beginTransaction($isolation);
    }

    #[\Override]
    public function commit(): void
    {
        $this->inner->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $this->inner->transaction($fn, $isolation);
    }
}
