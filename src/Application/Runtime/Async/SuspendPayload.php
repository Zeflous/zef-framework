<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal suspension payloads crossing the fiber boundary; a suspension is
 * either resolved with a value (SuspendValue) or with a failure that is
 * rethrown at the suspension point (SuspendFail, its own file since the
 * Sonar one-class-per-file split). Never exposed publicly.
 */
final readonly class SuspendValue
{
    public function __construct(public mixed $value) {}
}
