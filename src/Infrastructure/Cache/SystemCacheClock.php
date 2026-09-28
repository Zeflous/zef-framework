<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes). One deliberate
 * behavioural fix since: P-24 (issue #172) — see the class docblock.
 */

namespace Zef\Framework\Cache;

/**
 * Wall-clock implementation of the cache clock port.
 *
 * P-24 (issue #172): nowUnixNano() returns REAL unix nanoseconds
 * (~1.77e18), not hrtime(true) (~4.2e12 since boot). The port feeds
 * CacheItem::$expiresAtUnixNano deadlines: a monotonic reading would
 * only work by accident while every writer and reader shares the same
 * uptime clock — persisting a deadline cross-process or comparing it
 * against another implementation (microtime-based job/outbox clocks,
 * HrTimeClock) would silently never expire. Precision caveat: the
 * microtime(true) float quantises around 1e18 to ~256 ns steps — far
 * below the second-granularity cache TTLs this clock serves.
 */
final class SystemCacheClock implements CacheClockInterface
{
    #[\Override]
    public function nowUnixNano(): int
    {
        return (int) round(microtime(true) * 1_000_000_000);
    }
}
