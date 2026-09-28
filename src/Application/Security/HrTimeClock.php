<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\SystemCacheClock;

/**
 * Monotonic nanosecond clock (hrtime) — the production default for the
 * v2.25.0 rate-limiting algorithms.
 *
 * Lives in the Application layer because the Infrastructure
 * {@see SystemCacheClock} cannot be imported from here
 * (architecture rule: Application depends only on Domain); the algorithms
 * only need the port, and hrtime is the natural monotonic source for
 * continuous-refill arithmetic. Tests inject a fake clock instead.
 */
final class HrTimeClock implements CacheClockInterface
{
    /**
     * N-5 (issue #176): the method NAME is inherited from the shared port
     * ({@see CacheClockInterface::nowUnixNano()} — kept for BC; renaming it
     * would break every implementor), but this implementation's semantics
     * are MONOTONIC nanoseconds from an arbitrary epoch (hrtime(true),
     * ~4.2e12 since boot), deliberately NOT unix epoch nanoseconds. The
     * v2.25.0 rate-limit algorithms only consume differences and window
     * indices, which are invariant under any fixed time base, and a
     * monotonic base is immune to wall-clock steps (NTP/DST). It has
     * therefore genuinely diverged from Infrastructure SystemCacheClock,
     * which since P-24 (issue #172) returns real unix-ns wall time for
     * cross-process cache deadlines — never mix the two in one comparison.
     */
    #[\Override]
    public function nowUnixNano(): int
    {
        return hrtime(true);
    }
}
