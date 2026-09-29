<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal suspension and cancellation machinery of FiberScheduler.
 *
 * Owns the FIFO ready queue of fiber steps (drained round-robin by the
 * scheduler's pump loop), creates and parks suspension handles, re-queues
 * resolved suspensions as fiber steps, and executes cooperative
 * cancellation requests: settle-before-start for never-run tasks and
 * armed-handle failure for suspended ones, with the deferred-cancel entry
 * guard (ZEF-DEEP-03) at begin() time.
 */
final class FiberSuspensionCoordinator
{
    /**
     * Ready queue of fiber steps (spawn starts, suspension resolutions),
     * drained FIFO for deterministic, starvation-free round-robin order.
     *
     * @var list<\Closure(): void>
     */
    private array $ready = [];

    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly FiberTaskRunner $runner,
        private readonly AsyncTimerQueue $timers,
    ) {}

    /** Queues one fiber step at the tail of the ready queue. */
    public function enqueue(\Closure $step): void
    {
        $this->ready[] = $step;
    }

    public function hasPending(): bool
    {
        return $this->ready !== [];
    }

    /** Runs every currently queued step to completion (no re-drain). */
    public function drain(): void
    {
        while ($this->ready !== []) {
            $step = array_shift($this->ready);
            $step();
        }
    }

    /** Drops every queued step (scheduler run teardown). */
    public function clear(): void
    {
        $this->ready = [];
    }

    /**
     * Creates and arms a suspension handle for the current task; the
     * calling primitive registers the handle wherever its wake-up lives.
     */
    public function begin(string $context): SuspensionHandle
    {
        $fiber = \Fiber::getCurrent();
        $current = $fiber instanceof \Fiber ? $this->runner->taskFor($fiber) : null;

        if (!$current instanceof FiberTask) {
            throw new \LogicException(sprintf(
                '%s can only be called from inside a coroutine managed by this scheduler.',
                $context,
            ));
        }

        if ($current->isCancelRequested()) {
            // Deferred-cancel (ZEF-DEEP-03): a task whose cancellation was
            // requested while it ran (including a committed value returned
            // after a delivery/cancel race) must not park again. Surfacing
            // the cancellation HERE — before the calling primitive registers
            // any wake-up — guarantees no dead waiter entries are left behind
            // to swallow future permits or messages.
            throw new TaskCancelledException(sprintf('%s was cancelled while suspended', $current->name()));
        }

        $handle = new SuspensionHandle($this->scheduler, $current);
        $current->suspension()->arm($handle);

        return $handle;
    }

    /**
     * Parks the current fiber until the armed handle settles. Returns the
     * delivered value or rethrows the delivered failure.
     */
    public function await(SuspensionHandle $handle): mixed
    {
        $payload = \Fiber::suspend($handle);

        // No disarm here on purpose: the armed slot is only ever read while
        // the fiber is suspended, and the next suspension overwrites it.
        if ($payload instanceof SuspendFail) {
            throw $payload->throwable;
        }

        if (!$payload instanceof SuspendValue) {
            // Defensive: enqueueResume() only delivers SuspendValue|SuspendFail
            // and the SuspendFail arm throws above, so this is unreachable.
            throw new \LogicException('unexpected suspension payload');
        }

        // A delivered value is COMMITTED (ZEF-DEEP-03, issue #157): the settle
        // hooks already ran, queue entries were spliced, and the hand-over
        // physically happened — the semaphore permit was transferred, the
        // channel message left the sender, the awaited task finished. A
        // cancellation that lands between delivery and resume must NOT
        // discard it: that raced permanently with the old post-resume
        // cancel check, leaking semaphore permits (every later acquire()
        // deadlocked) and silently dropping delivered channel messages.
        // Cancellation is deferred to the next suspension entry instead
        // (beginSuspension rejects parking for cancel-requested tasks).
        return $payload->value;
    }

    /**
     * Re-queues a settled suspension as a fiber step carrying the
     * resolution payload. Called by SuspensionHandle deliver()/fail() and
     * by the wake closures of queued spawns.
     */
    public function resume(FiberTask $task, SuspendFail|SuspendValue $payload): void
    {
        $this->ready[] = function () use ($task, $payload): void {
            $this->runner->step($task, $payload);
        };
    }

    /**
     * Cooperative cancellation request from TaskInterface::cancel():
     * settles never-started tasks at once and fails the armed handle of
     * suspended ones; a running coroutine observes the flag at its next
     * suspension point.
     */
    public function requestCancel(FiberTask $task): bool
    {
        if ($task->isDone() || $task->isCancelRequested()) {
            return false;
        }

        $task->requestCancellation();
        $this->timers->splice($task);

        $fiber = $task->fiber();

        if (!$fiber instanceof \Fiber) {
            // Never started (spawn-queued or timer-pending): settle at once.
            $this->runner->finish(
                $task,
                TaskState::Cancelled,
                null,
                new TaskCancelledException(sprintf('%s was cancelled before it started', $task->name())),
            );

            return true;
        }

        if ($fiber->isSuspended()) {
            $armed = $task->suspension()->handle();

            if ($armed instanceof SuspensionHandle) {
                $armed->fail(new TaskCancelledException(sprintf('%s was cancelled while suspended', $task->name())));
            }
            // No armed handle at a suspension point is impossible by
            // construction; if state ever drifted, deadlock detection names
            // the task on the next pump pass instead of guessing here.
        }
        // Running synchronously (self-cancel or cancel from a completion
        // callback): the flag is honoured at the next suspension point.

        return true;
    }
}
