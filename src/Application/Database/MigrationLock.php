<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: services over the port)
 * Single-row lock-table lease extracted from Migrator (php:S2042): the
 * acquire/steal/renew/release machinery with the holder-token CAS
 * discipline (Regresi I-12 / issue #175), behaviour-identical to the
 * code previously inlined in Migrator.
 */

namespace Zef\Framework\Database;

/**
 * Versioned-migration concurrency guard.
 *
 * - guards concurrent runners with a single-row lock table
 *   (`zef_migrations_lock`); a lock whose age exceeds the TTL recorded
 *   ON THE ROW is considered stale and stolen;
 * - every lock row carries a `holder` token (random per lock instance):
 *   renewal is a holder-token compare-and-swap (`UPDATE ... WHERE
 *   holder = token`, 0 rows = the lock was lost or stolen — the run
 *   aborts instead of refreshing somebody else's lease), the steal is
 *   ONE conditional UPDATE on the row's own TTL (two racing runners can
 *   never both win), and release is a compare-and-delete (a runner that
 *   lost the lock never deletes the row its thief now owns) — the same
 *   owner-token CAS discipline as RedisLockStore. Lock tables created
 *   before v2.31.0 are upgraded with a best-effort
 *   `ALTER TABLE ... ADD COLUMN holder`.
 *
 * The clock is injectable ($now returning unix seconds) so lock expiry
 * and heartbeat renewal are deterministically testable.
 */
final class MigrationLock
{
    private const string LOCK_TABLE = 'zef_migrations_lock';

    private int $depth = 0;

    /** @var callable(): int */
    private $now;

    /**
     * Random holder token for this lock instance: stamps the lock row so
     * renew/release act only on a lock this runner still owns.
     */
    private readonly string $holderToken;

    /**
     * @param callable(): int $now wall-clock unix seconds provider
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        callable $now,
        private readonly float $ttlSeconds,
    ) {
        $this->now = $now;
        $this->holderToken = bin2hex(random_bytes(16));
    }

    public function acquire(): void
    {
        if ($this->depth > 0) {
            throw new TransactionException('Migration lock is already held by this Migrator instance.');
        }
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . self::LOCK_TABLE . '" ('
            . '"id" INTEGER NOT NULL PRIMARY KEY, "locked_at" INTEGER NOT NULL, "ttl" REAL NOT NULL, '
            . '"holder" VARCHAR(64) NOT NULL DEFAULT \'\')',
        ));
        $this->ensureHolderColumn();
        // (int) hardening: the now stamp is inlined into the steal statement
        // below, so a clock violating the callable():int contract must never
        // reach the SQL text.
        $nowStamp = (int) ($this->now)();

        try {
            $this->connection->execute(
                QueryBuilder::table(self::LOCK_TABLE)
                    ->insert([
                        'id' => 1,
                        'locked_at' => $nowStamp,
                        'ttl' => $this->ttlSeconds,
                        'holder' => $this->holderToken,
                    ])->build(),
            );
        } catch (QueryException) {
            $this->stealStaleLock($nowStamp);
        }
        $this->depth = 1;
    }

    /**
     * Heartbeat: refresh the lock row with the effective TTL so other
     * runners never see this holder as stale while work progresses.
     *
     * Renewal is a holder-token compare-and-swap —
     * `UPDATE ... WHERE id = 1 AND holder = token`. Zero affected rows
     * means this runner no longer owns the lock (lost or stolen); the run
     * aborts instead of blindly refreshing somebody else's lease.
     */
    public function renew(float $ttl): void
    {
        if ($this->depth === 0) {
            return;
        }
        $renewed = $this->connection->execute(
            QueryBuilder::table(self::LOCK_TABLE)
                ->update(['locked_at' => ($this->now)(), 'ttl' => $ttl])
                ->where('id', '=', 1)
                ->where('holder', '=', $this->holderToken)
                ->build(),
        );
        if ($renewed === 0) {
            throw new TransactionException(
                'Migration lock renewal failed: this runner no longer holds the lock (lost or stolen).',
            );
        }
    }

    public function release(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth = 0;
        // Compare-and-delete — a runner that lost its lock must never
        // remove the row its thief now owns (mirrors RedisLockStore's
        // owner-token release).
        $this->connection->execute(
            QueryBuilder::table(self::LOCK_TABLE)
                ->where('id', '=', 1)
                ->where('holder', '=', $this->holderToken)
                ->delete()
                ->build(),
        );
    }

    /**
     * The steal path taken when the INSERT hit an existing row: read the
     * row's own recorded age/TTL, refuse while it is still fresh, then
     * take it over with ONE conditional UPDATE.
     */
    private function stealStaleLock(int $nowStamp): void
    {
        $rows = $this->connection->fetchAll(
            QueryBuilder::table(self::LOCK_TABLE)
                ->select('locked_at', 'ttl')->where('id', '=', 1)->build(),
        );
        $lockedRaw = $rows[0]['locked_at'] ?? null;
        $ttlRaw = $rows[0]['ttl'] ?? null;
        if (!self::isNumericScalar($lockedRaw) || !self::isNumericScalar($ttlRaw)) {
            throw new QueryException('Migration lock row is malformed.');
        }
        $lockedAt = (int) $lockedRaw;
        $rowTtl = (float) $ttlRaw;
        $age = $nowStamp - $lockedAt;
        if ((float) $age < $rowTtl) {
            throw new TransactionException(
                'Migration lock is already held (age ' . $age . 's, ttl ' . $rowTtl . 's).',
            );
        }
        // The steal is ONE conditional UPDATE — a compare-and-swap on the
        // row's own recorded TTL (`locked_at + ttl <= now`, the same
        // age >= ttl boundary the check above uses). Two runners racing on
        // the same stale row cannot both win: the loser matches 0 rows and
        // throws instead of migrating concurrently.
        //
        // Raw SQL with inlined literals (no bound params) on purpose:
        // positional params bind as TEXT, and SQLite compares by type
        // class (numeric < text), so a `"locked_at" + "ttl" <= ?`
        // comparison would be TRUE even for a row another runner just
        // renewed — the CAS would never lose a race. Every inlined value
        // is generated here: $nowStamp is an int, the TTL was validated
        // positive at construction, and the holder token is bin2hex — no
        // injection surface.
        $stolen = $this->connection->execute(SqlQuery::raw(
            'UPDATE "' . self::LOCK_TABLE
            . '" SET "locked_at" = ' . $nowStamp . ', "ttl" = ' . var_export($this->ttlSeconds, true)
            . ', "holder" = \'' . $this->holderToken . '\' WHERE "id" = 1 AND "locked_at" + "ttl" <= ' . $nowStamp,
        ));
        if ($stolen === 0) {
            throw new TransactionException(
                'Migration lock steal lost the race: the row was renewed or stolen concurrently.',
            );
        }
    }

    /**
     * Best-effort holder-column upgrade for lock tables created before
     * v2.31.0, following the PdoOutbox legacy-upgrade convention:
     * duplicate-column errors from every supported driver (sqlite
     * "duplicate column", mysql "Duplicate column name", pgsql
     * "... already exists") are treated as "upgrade already applied".
     */
    private function ensureHolderColumn(): void
    {
        try {
            $this->connection->execute(SqlQuery::raw(
                'ALTER TABLE "' . self::LOCK_TABLE . '" ADD COLUMN "holder" VARCHAR(64) NOT NULL DEFAULT \'\'',
            ));
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'duplicate column') && !str_contains($message, 'already exists')) {
                throw $e;
            }
        }
    }

    /**
     * Lock-row cells may surface as int, float or string depending on the driver.
     *
     * @phpstan-assert-if-true int|float|string $value
     */
    private static function isNumericScalar(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_string($value);
    }
}
