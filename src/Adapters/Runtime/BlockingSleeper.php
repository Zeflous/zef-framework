<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Runtime;

final class BlockingSleeper
{
    public static function sleepMilliseconds(int $ms): void
    {
        if ($ms > 0 && function_exists('usleep')) {
            usleep($ms * 1000);
        }
    }
}
