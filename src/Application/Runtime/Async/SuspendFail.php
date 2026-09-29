<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 *
 * Split out of SuspendPayload.php (one class per file) during the
 * Sonar zero-violation campaign; behavior and namespace are unchanged.
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal failure payload: the throwable is rethrown inside the suspended
 * fiber exactly at the point where it called Fiber::suspend()
 */
final readonly class SuspendFail
{
    public function __construct(public \Throwable $throwable) {}
}
