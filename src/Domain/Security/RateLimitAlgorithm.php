<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Domain layer: value objects)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * Rate-limiting algorithm families the framework ships.
 *
 * The enum is a labelling contract, not the arithmetic itself: a
 * {@see RateLimiterInterface} implementation owns the math, the label exists
 * so configuration, diagnostics and wiring code can name a strategy without
 * referencing concrete limiter classes. String-backed so environment /
 * config-driven selection stays dependency-free and serialisable.
 */
enum RateLimitAlgorithm: string
{
    /**
     * Classic fixed window: a counter per (key, window) bucket, reset at the
     * window boundary. Cheapest, but allows up to 2x the limit across a
     * boundary (burst at the end of one window + start of the next).
     */
    case FixedWindow = 'fixed';

    /**
     * Sliding window counter: interpolates the previous window's count with
     * the elapsed fraction of the current window, removing the boundary
     * burst while keeping O(1) state per key (two counters, no request log).
     */
    case SlidingWindow = 'sliding';

    /**
     * Token bucket: a bucket of `limit` tokens refilled continuously at
     * `limit / windowSeconds` tokens per second. Natively burst-tolerant
     * (a burst drains the bucket, then the steady refill rate governs).
     */
    case TokenBucket = 'token';

    /**
     * Parses a user-supplied algorithm name (config string, env var) with
     * fail-fast validation: unknown names are a boot-time error, never a
     * silent fallback to a different algorithm.
     *
     * @throws \InvalidArgumentException on empty or unknown names
     */
    public static function fromString(string $value): self
    {
        $normalised = strtolower(trim($value));
        $algorithm = self::tryFrom($normalised);
        if ($algorithm instanceof self) {
            return $algorithm;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown rate limit algorithm "%s" (expected one of: %s).',
            $value,
            implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
        ));
    }
}
