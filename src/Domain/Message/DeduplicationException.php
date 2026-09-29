<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Domain layer (ports, contracts, value objects).
 * Dedicated exception for the message pipeline's idempotency/dedup store
 * contract (php:S112: replaces the generic RuntimeException previously
 * thrown by DeduplicatingMiddleware). Extends RuntimeException so existing
 * catch blocks keep working unchanged.
 */

namespace Zef\Framework\Message;

final class DeduplicationException extends \RuntimeException {}
