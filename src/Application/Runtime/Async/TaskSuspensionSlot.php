<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal The suspension slot a task arms while its fiber parks.
 *
 * A fiber suspends at exactly one point, so at most one handle is armed
 * at a time: FiberScheduler::beginSuspension() arms it, requestCancel()
 * reads it to deliver a cancellation into the exact suspension point,
 * and FiberTask::finish() clears it when the task settles.
 */
final class TaskSuspensionSlot
{
    private ?SuspensionHandle $handle = null;

    public function handle(): ?SuspensionHandle
    {
        return $this->handle;
    }

    public function arm(SuspensionHandle $handle): void
    {
        $this->handle = $handle;
    }

    public function clear(): void
    {
        $this->handle = null;
    }
}
