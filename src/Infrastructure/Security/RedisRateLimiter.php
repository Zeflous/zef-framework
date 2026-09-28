<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

final readonly class RedisRateLimiter implements RateLimiterInterface
{
    /**
     * N-1 (issue #176): $maxKeys is accepted for configuration symmetry
     * with the in-process limiter adapters and validated, but this
     * adapter keeps NO per-key state, so it cannot bound the shared
     * store's memory — bounding is the store's responsibility (Redis
     * maxmemory with an eviction policy). Keys this limiter creates
     * expire through the store's per-window TTL, so the resident set
     * only grows with distinct active keys, which maxmemory caps.
     */
    public function __construct(
        private SharedRateLimitStoreInterface $store,
        private int $maxKeys = 10000,
    ) {
        if ($this->maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
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
        ['count' => $count, 'reset' => $reset] = $this->store->increment($key, $windowSeconds, $now);
        $remaining = max(0, $limit - $count);

        return new RateLimitDecision($count <= $limit, $limit, $remaining, max(1, $reset - $now));
    }
}
