<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * Bug fix #4: added guards $maxKeys>=1, $key!='', $limit>=1, $windowSeconds>=1.
 */
final class InMemoryRateLimiter implements RateLimiterInterface
{
    /**
     * @var array<string,array{count:int,reset:int}>
     */
    private array $buckets = [];

    public function __construct(private readonly int $maxKeys = 10000)
    {
        if ($maxKeys < 1) {
            throw new \InvalidArgumentException('maxKeys must be >= 1.');
        }
    }

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
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
        $now = time();
        foreach ($this->buckets as $bucketKey => $bucket) {
            if ($bucket['reset'] <= $now) {
                unset($this->buckets[$bucketKey]);
            }
        }
        if (!isset($this->buckets[$key]) && count($this->buckets) >= $this->maxKeys) {
            throw new RateLimiterCapacityException('Rate limiter capacity exhausted.');
        }
        $bucket = $this->buckets[$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];
        if ($bucket['reset'] <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
        }
        ++$bucket['count'];
        $this->buckets[$key] = $bucket;
        $remaining = max(0, $limit - $bucket['count']);

        return new RateLimitDecision(
            $bucket['count'] <= $limit,
            $limit,
            $remaining,
            max(1, $bucket['reset'] - $now),
        );
    }
}
