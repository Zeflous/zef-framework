<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

final readonly class RequestBodyPolicy
{
    public const int DEFAULT_MAX_BYTES = 2097152;

    public function __construct(public int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Request body maxBytes must be greater than zero.');
        }
    }
}
