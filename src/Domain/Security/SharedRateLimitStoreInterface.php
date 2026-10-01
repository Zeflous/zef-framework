<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

interface SharedRateLimitStoreInterface
{
    /** @return array{count:int, reset:int} */
    public function increment(string $key, int $windowSeconds, int $now): array;

    /**
     * Audit #322: the window is part of the bucket identity — a bucket is
     * (key, windowSeconds), so peeking takes the same window the increment
     * used. Re-using one key with a different window is a DIFFERENT bucket,
     * never a silent mix.
     *
     * @return null|array{count:int, reset:int}
     */
    public function peek(string $key, int $windowSeconds, int $now): ?array;
}
