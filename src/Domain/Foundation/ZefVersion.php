<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Foundation;

/**
 * Single source of truth for the framework version string.
 */
final class ZefVersion
{
    public const string VERSION = '2.34.1';
}

/*
 * Typed helpers for reading environment variables.
 * Replaces inline getenv() parsing scattered across the codebase.
 */
