<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added for ZEF-DEEP-14 (issue #168): the TieredCache L1 promotion must not
 * re-arm the hot-tier TTL past the L2 entry's own deadline, so caches that
 * can introspect remaining lifetime expose it through this narrow port.
 */

namespace Zef\Framework\Cache;

/**
 * Optional capability port for caches that can report how much lifetime a
 * key still has.
 *
 * Consumers such as TieredCache use this to cap re-cached copies so a value
 * can never be served fresher (for longer) than the deadline its source
 * entry carries. Implementations MUST floor the result to whole seconds so
 * a caller re-caching with the reported value can never overshoot the real
 * deadline — under-reporting is safe (the next read re-promotes), over-
 * reporting is not (stale serve past expiry).
 */
interface TtlAwareCacheInterface
{
    /**
     * Whole seconds remaining before the entry expires (floored).
     *
     * Returns 0 when the entry is already at (or past) its deadline, and
     * null when the key carries no deadline at all or its lifetime cannot
     * be introspected — absent keys included, so pair this with has().
     */
    public function getRemainingTtlSeconds(string $key): ?int;
}
