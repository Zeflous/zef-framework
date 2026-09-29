<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

use Zef\Framework\Cache\CacheClockInterface;

/**
 * Sliding-window counter rate limiter (cost-aware).
 *
 * Smooths the classic fixed-window boundary burst: the effective usage is
 * the PREVIOUS window's count weighted by the fraction of the current
 * window that has NOT elapsed yet, plus the CURRENT window's count:
 *
 *     used = ceil(prevCount * (1 - elapsedRatio) + currCount)
 *
 * State is O(1) per key (two counters + the current window index) — no
 * per-request log, unlike a true sliding-window log. The cost is the
 * ceiling, so the estimate errs on the strict side (never admits more than
 * it should).
 *
 * Key/window contract: one key must always be used with the SAME
 * windowSeconds — the entry stores its window and throws on a mismatch,
 * because mixing window sizes under one key would silently corrupt the
 * interpolation. {@see TieredRateLimiter} guarantees this by prefixing the
 * rule name to the key. The same guarantee applies to `limit` only weakly
 * (limit participates in the decision, not the stored shape), so changing
 * the limit for an existing key is safe (a live quota retune).
 *
 * Mirrors {@see InMemoryRateLimiter} operational guards: maxKeys capacity,
 * expired-key GC sweep, fail-fast argument validation.
 */
final class SlidingWindowRateLimiter implements CostAwareRateLimiterInterface
{
    /**
     * Per-key state. `currWindow` is the absolute window index
     * (intdiv(nowNs, windowNs)) of the `curr` counter; `prev` is always the
     * counter of the window immediately before it.
     *
     * @var array<string, array{prev: int, curr: int, currWindow: int, windowNs: int}>
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
        $currentWindow = intdiv($nowNs, $windowNs);

        $this->sweep($currentWindow);

        $bucket = $this->buckets[$key] ?? null;
        if ($bucket !== null && $bucket['windowNs'] !== $windowNs) {
            throw new \InvalidArgumentException(sprintf(
                'Rate limit key "%s" is already in use with a different window size.',
                $key,
            ));
        }

        $elapsedNs = $nowNs - ($currentWindow * $windowNs);
        $prev = 0;
        $curr = 0;
        if ($bucket !== null) {
            if ($bucket['currWindow'] === $currentWindow) {
                $prev = $bucket['prev'];
                $curr = $bucket['curr'];
            } elseif ($bucket['currWindow'] === $currentWindow - 1) {
                // Exactly one window old: its "current" counter becomes the
                // weighted "previous" contribution for this window.
                $prev = $bucket['curr'];
            } else {
                /* Older than one window: both counters are fully expired. */
            }
        }

        $elapsedRatio = $elapsedNs / $windowNs;
        $used = (int) ceil($prev * (1 - $elapsedRatio) + $curr);

        if ($used + $cost > $limit) {
            // elapsed < window on this path, so the boundary wait is
            // strictly positive and the ceil is always >= 1.
            $untilBoundary = (int) ceil(($windowNs - $elapsedNs) / 1_000_000_000);

            return new RateLimitDecision(false, $limit, $limit - $used, $untilBoundary, $untilBoundary);
        }

        $remaining = $limit - ($used + $cost);
        $untilBoundary = (int) ceil(($windowNs - $elapsedNs) / 1_000_000_000);

        if ($bucket === null && count($this->buckets) >= $this->maxKeys) {
            throw new RateLimiterCapacityException('Rate limiter capacity exhausted.');
        }
        $this->buckets[$key] = [
            'prev' => $prev,
            'curr' => $curr + $cost,
            'currWindow' => $currentWindow,
            'windowNs' => $windowNs,
        ];

        return new RateLimitDecision(true, $limit, $remaining, $untilBoundary, $untilBoundary);
    }

    /**
     * Drops keys whose counters are two or more windows stale — they cannot
     * contribute to any future interpolation. Runs before every admission
     * decision so `maxKeys` bounds live keys, not history.
     */
    private function sweep(int $currentWindow): void
    {
        foreach ($this->buckets as $bucketKey => $bucket) {
            if ($bucket['currWindow'] < $currentWindow - 1) {
                unset($this->buckets[$bucketKey]);
            }
        }
    }
}
