<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

final readonly class CacheItem
{
    public function __construct(
        public mixed $value,
        public ?int $expiresAtUnixNano = null,
    ) {}

    public function isExpired(?int $nowUnixNano = null): bool
    {
        // P-24 (issue #172): $expiresAtUnixNano is unix nanoseconds, so the
        // default "now" must be a wall-clock reading (microtime), not
        // hrtime(true) — monotonic nanoseconds (~4.2e12 since boot) would
        // make a persisted unix-ns deadline look eternally fresh. The
        // microtime float quantises near 1e18 to ~256 ns steps: irrelevant
        // at cache-TTL granularity.
        return $this->expiresAtUnixNano !== null
            && ($nowUnixNano ?? (int) round(microtime(true) * 1_000_000_000)) >= $this->expiresAtUnixNano;
    }
}
