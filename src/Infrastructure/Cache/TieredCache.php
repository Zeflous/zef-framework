<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Cache;

/**
 * Two-tier cache (L1 hot + L2 capacity) implementing CacheInterface.
 *
 * Reads check L1 first, then L2, promoting hits into L1; writes go to both
 * tiers (write-through); deletes and clears propagate to both. L1 entries are
 * additionally capped by $l1TtlSeconds so hot-tier staleness stays bounded
 * even when the underlying L1 has a longer native TTL. Promotions are capped
 * by the L2 entry's REMAINING lifetime whenever the L2 implements
 * TtlAwareCacheInterface, so a promoted copy can never outlive the TTL the
 * original set() asked for (ZEF-DEEP-14).
 */
final readonly class TieredCache implements CacheInterface
{
    public function __construct(
        private CacheInterface $l1,
        private CacheInterface $l2,
        private ?int $l1TtlSeconds = 60,
    ) {
        if ($this->l1TtlSeconds !== null && $this->l1TtlSeconds < 1) {
            throw new \InvalidArgumentException('L1 TTL must be >= 1 second (or null).');
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->l1->has($key)) {
            return $this->l1->get($key, $default);
        }
        if (!$this->l2->has($key)) {
            return $default;
        }
        $value = $this->l2->get($key, $default);
        $remaining = $this->l2 instanceof TtlAwareCacheInterface
            ? $this->l2->getRemainingTtlSeconds($key)
            : null;
        if ($remaining === null || $remaining > 0) {
            $this->l1->set($key, $value, $this->promotionTtl($remaining));
        }

        return $value;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->l2->set($key, $value, $ttlSeconds);
        // Caller TTL wins when no L1 cap is configured; otherwise the
        // promotion can never outlive the hotter tier's budget.
        $l1Ttl = match (true) {
            $ttlSeconds === null => $this->l1TtlSeconds,
            $this->l1TtlSeconds !== null => min($ttlSeconds, $this->l1TtlSeconds),
            default => $ttlSeconds,
        };
        $this->l1->set($key, $value, $l1Ttl);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->l1->delete($key);
        $this->l2->delete($key);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->l1->has($key) || $this->l2->has($key);
    }

    #[\Override]
    public function clear(): void
    {
        $this->l1->clear();
        $this->l2->clear();
    }

    /**
     * TTL for promoting an L2 hit into L1: the constructor cap, but never
     * longer than the L2 entry's remaining lifetime (floored by contract),
     * so a promotion cannot extend freshness past the deadline the caller
     * asked for. null = promote without a deadline (uncapped L1, or an L2
     * entry / adapter that carries no deadline).
     */
    private function promotionTtl(?int $remaining): ?int
    {
        if ($remaining === null) {
            return $this->l1TtlSeconds;
        }
        if ($this->l1TtlSeconds === null) {
            return $remaining;
        }

        return min($this->l1TtlSeconds, $remaining);
    }
}
