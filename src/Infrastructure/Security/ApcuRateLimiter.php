<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * APCu-backed rate limiter.
 *
 * Fixed-window bucketing (ZEF-DEEP-15 P-1, issue #169): the bucket id is
 * derived from the wall clock alone — intdiv(now, windowSeconds) — and the
 * counter lives under a per-bucket key, so one atomic apcu_inc decides the
 * outcome and the reset point is the next bucket boundary. The previous
 * two-entry (window + counter) scheme validated the window entry with a
 * fetch-then-store pair that a concurrent writer could interleave with,
 * resetting the window twice or leaking counts across it.
 *
 * Bug fix #5 (kept): apcu_add seeds a missing bucket counter so a first hit
 * never clobbers a concurrently incremented one; the counter TTL is twice
 * the window so finished buckets evaporate on their own.
 */
final readonly class ApcuRateLimiter implements RateLimiterInterface
{
    private const string PREFIX = 'zef:ratelimit:';

    public function __construct(private int $maxKeys = 10000)
    {
        if ($this->maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
        }
        if (!function_exists('apcu_fetch')) {
            throw new \RuntimeException('APCu extension is required for ApcuRateLimiter.');
        }
    }

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Rate limit key must not be empty.');
        }
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('limit and windowSeconds must be >= 1.');
        }
        $now = time();
        $bucket = intdiv($now, $windowSeconds);
        $counterKey = self::PREFIX . 'c:' . hash('sha256', $key) . ':' . $bucket;
        // Old buckets expire on their own once their window has closed.
        $ttl = 2 * $windowSeconds;
        $count = apcu_inc($counterKey, 1, $success, $ttl);
        if ($success !== true) {
            // Bug fix #5: apcu_add (not apcu_store) seeds a fresh bucket so a
            // concurrent first hit is never clobbered; losing the add race
            // means the winner seeded it, so the count climbs on top.
            if (apcu_add($counterKey, 1, $ttl)) {
                $count = 1;
            } else {
                $count = apcu_inc($counterKey, 1, $success, $ttl);
                if ($success !== true) {
                    apcu_store($counterKey, 1, $ttl);
                    $count = 1;
                }
            }
        }
        $remaining = max(0, $limit - $count);
        $reset = ($bucket + 1) * $windowSeconds;

        return new RateLimitDecision($count <= $limit, $limit, $remaining, $reset - $now);
    }
}
