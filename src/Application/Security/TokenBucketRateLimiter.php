<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

use Zef\Framework\Cache\CacheClockInterface;

/**
 * Token-bucket rate limiter (cost-aware, continuous refill).
 *
 * A bucket starts FULL with `limit` tokens (so the initial burst tolerance
 * equals the whole window quota) and refills continuously at
 * `limit / windowSeconds` tokens per second. Each admission withdraws
 * `cost` tokens atomically; a denial reports the ceil time until the
 * requested cost is affordable again.
 *
 * Why token bucket: it is the only classic algorithm that models BOTH a
 * burst allowance (the full bucket) and a sustained rate (the refill slope),
 * which is the right shape for "N per minute" quotas that must not reject a
 * legitimate burst at window start.
 *
 * Key/shape contract: one key must always be used with the SAME limit AND
 * windowSeconds — the bucket stores its capacity and refill slope; a
 * mismatch is a configuration bug and throws instead of silently re-shaping
 * the bucket. {@see TieredRateLimiter} guarantees this by prefixing the
 * rule name to the key.
 *
 * Mirrors {@see InMemoryRateLimiter} operational guards: maxKeys capacity
 * (a FULL bucket that stayed idle a whole window is collectable), fail-fast
 * argument validation.
 */
final class TokenBucketRateLimiter implements CostAwareRateLimiterInterface
{
    /**
     * Per-key state. `tokens` is the fractional current level,
     * `lastNs` the timestamp of the last refill application, `capacity` and
     * `windowNs` the shape the bucket was created with.
     *
     * @var array<string, array{tokens: float, lastNs: int, capacity: int, windowNs: int}>
     */
    private array $buckets = [];

    public function __construct(
        private readonly CacheClockInterface $clock,
        private readonly int $maxKeys = 10000,
    ) {
        if ($maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
        }
    }

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        return $this->consume($key, $limit, $windowSeconds, 1);
    }

    #[\Override]
    public function consume(string $key, int $limit, int $windowSeconds, int $cost = 1): RateLimitDecision
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Rate limit key must not be empty.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be >= 1.');
        }
        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('windowSeconds must be >= 1.');
        }
        if ($cost < 1 || $cost > $limit) {
            throw new \InvalidArgumentException('cost must be >= 1 and <= limit.');
        }

        $nowNs = $this->clock->nowUnixNano();
        $windowNs = $windowSeconds * 1_000_000_000;

        $bucket = $this->buckets[$key] ?? null;
        if ($bucket !== null && ($bucket['capacity'] !== $limit || $bucket['windowNs'] !== $windowNs)) {
            throw new \InvalidArgumentException(sprintf(
                'Rate limit key "%s" is already in use with a different limit/window shape.',
                $key,
            ));
        }

        $this->sweep($nowNs);

        if ($bucket === null) {
            $tokens = (float) $limit;
        } else {
            $refillPerSec = $bucket['capacity'] / ($bucket['windowNs'] / 1_000_000_000);
            $elapsedSec = ($nowNs - $bucket['lastNs']) / 1_000_000_000;
            $tokens = min((float) $bucket['capacity'], $bucket['tokens'] + $elapsedSec * $refillPerSec);
        }

        if ($tokens < $cost) {
            // deficit > 0 (strict, by the branch) and refillPerSec > 0, so
            // the ceil is always >= 1 — no clamp needed.
            $refillPerSec = $limit / $windowSeconds;
            $deficit = $cost - $tokens;
            $retryAfter = (int) ceil($deficit / $refillPerSec);
            $untilFull = (int) ceil(($limit - $tokens) / $refillPerSec);

            return new RateLimitDecision(false, $limit, (int) floor($tokens), $retryAfter, $untilFull);
        }

        $remainingTokens = $tokens - $cost;
        // remainingTokens < limit (a token was just withdrawn), so
        // untilFull is always >= 1 — no clamp needed.
        $refillPerSec = $limit / $windowSeconds;
        $untilFull = (int) ceil(($limit - $remainingTokens) / $refillPerSec);

        if ($bucket === null && count($this->buckets) >= $this->maxKeys) {
            throw new RateLimiterCapacityException('Rate limiter capacity exhausted.');
        }
        $this->buckets[$key] = [
            'tokens' => $remainingTokens,
            'lastNs' => $nowNs,
            'capacity' => $limit,
            'windowNs' => $windowNs,
        ];

        return new RateLimitDecision(true, $limit, (int) floor($remainingTokens), $untilFull, $untilFull);
    }

    /**
     * Drops keys whose bucket has been idle for at least one full window of
     * its own shape — after a full idle window the refill slope guarantees
     * the bucket is back at capacity, so its history carries no information
     * and only consumes maxKeys capacity. (The refill itself is applied
     * lazily on touch, which is why idleness alone is the right criterion.).
     */
    private function sweep(int $nowNs): void
    {
        foreach ($this->buckets as $bucketKey => $bucket) {
            if ($nowNs - $bucket['lastNs'] >= $bucket['windowNs']) {
                unset($this->buckets[$bucketKey]);
            }
        }
    }
}
