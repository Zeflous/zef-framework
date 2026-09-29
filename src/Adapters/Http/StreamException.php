<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

/**
 * Dedicated runtime failure of a {@see Stream} resource operation
 * (detached/unseekable/unreadable/unwritable streams and transport-level
 * read/write failures).
 *
 * Extends {@see \RuntimeException} so PSR-7 consumers and existing tests
 * catching the generic SPL exception keep working unchanged.
 */
final class StreamException extends \RuntimeException {}
