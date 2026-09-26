<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: durable idempotency cache for job handlers (Database Core port).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlQuery;

/**
 * PDO-backed {@see JobIdempotencyStoreInterface} — durable duplicate
 * suppression for job handlers (the InMemoryLockingJobIdempotencyStore
 * counterpart for multi-worker deployments).
 *
 * Semantics:
 * - remember(key, producer, ttl): return the cached value while the entry
 *   is live; otherwise run the producer once and cache its JSON result;
 * - producer exceptions propagate WITHOUT caching — a failed execution is
 *   never mistaken for a completed one;
 * - expired entries are swept lazily on the next remember() for the same
 *   key (no background reaper, no server clock dependency beyond the
 *   injected closure);
 * - the clock is re-read at INSERT time, so the full TTL is honoured even
 *   when the producer itself ran for a large fraction of it;
 * - concurrent first-execution races: both workers miss, both run the
 *   producer, the loser's INSERT hits UNIQUE(idem_key) and the loser then
 *   returns the winner's stored value (at-least-once execution,
 *   exactly-once effect — the documented contract for this port). The
 *   winner check re-reads the clock too: a winning entry that expired
 *   while the loser's producer was running is dead, not authoritative,
 *   and its INSERT error is rethrown instead.
 *
 * Values are JSON documents (mixed round-trip) — the same contract as the
 * PDO job queue payloads.
 */
final readonly class PdoJobIdempotencyStore implements JobIdempotencyStoreInterface
{
    private const int MAX_KEY_BYTES = 255;
    private const int MAX_TTL_SECONDS = 604_800; // 7 days
    private string $table;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port
     * @param string                 $table      idempotency table name
     * @param null|(\Closure(): int) $clock      expiry clock in unix SECONDS (default: time())
     */
    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_job_idempotency',
        ?\Closure $clock = null,
    ) {
        new QueryBuilder()->quoteIdentifier($table, 'table');
        $this->table = $table;
        $this->clock = $clock ?? time(...);
    }

    /**
     * Create the idempotency table (portable DDL, safe to run repeatedly).
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ('
            . '"idem_key" VARCHAR(255) NOT NULL, '
            . '"value" TEXT NOT NULL, '
            . '"expires_at" BIGINT NOT NULL, '
            . 'CONSTRAINT "uq_' . $this->table . '_key" UNIQUE ("idem_key"))',
        ));
    }

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_BYTES) {
            throw new \InvalidArgumentException('Idempotency key must be 1..255 bytes.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Idempotency TTL must be 1..604800 seconds.');
        }
        $now = ($this->clock)();
        $cached = $this->fetchRecord($key, $now);
        if ($cached !== null) {
            return $cached['value'];
        }

        $value = $producer();
        $stored = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);

        try {
            $this->connection->execute(
                QueryBuilder::table($this->table)->insert([
                    'idem_key' => $key,
                    'value' => $stored,
                    // Fresh clock at insert time: a producer that ran for a
                    // while must not shorten the entry's effective TTL.
                    'expires_at' => ($this->clock)() + $ttlSeconds,
                ])->build(),
            );

            return $value;
        } catch (\Throwable $insertError) {
            // Lost a first-execution race (UNIQUE idem_key) — the winner's
            // value is authoritative while it is still live. The clock is
            // re-read here: the producer may have outlasted the winner's
            // TTL, and an expired winner is swept, not adopted. Any other
            // failure rethrows below.
            $winner = $this->fetchRecord($key, ($this->clock)());
            if ($winner !== null) {
                return $winner['value'];
            }

            throw $insertError;
        }
    }

    /**
     * Live cached record for $key, or null when absent/expired.
     * The record wrapper distinguishes a stored JSON null from a cache miss.
     *
     * @return null|array{value: mixed}
     */
    private function fetchRecord(string $key, int $now): ?array
    {
        $row = $this->connection->fetchOne(
            QueryBuilder::table($this->table)
                ->select('value', 'expires_at')
                ->where('idem_key', '=', $key)
                ->limit(1)
                ->build(),
        );
        if ($row === null) {
            return null;
        }
        $expiresAt = $this->intVal($row['expires_at'] ?? null);
        if ($expiresAt <= $now) {
            $this->sweep($key);

            return null;
        }

        try {
            return ['value' => json_decode($this->str($row['value'] ?? null), true, 512, \JSON_THROW_ON_ERROR)];
        } catch (\JsonException $error) {
            throw new \RuntimeException('Stored idempotency value is not valid JSON.', 0, $error);
        }
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function intVal(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function sweep(string $key): void
    {
        $this->connection->execute(
            QueryBuilder::table($this->table)
                ->delete()
                ->where('idem_key', '=', $key)
                ->build(),
        );
    }
}
