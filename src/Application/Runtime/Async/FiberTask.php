<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal scheduler-owned implementation of TaskInterface.
 *
 * All state transitions run through the owning scheduler (spawn -> pending,
 * pump start -> running, settle -> terminal, requestCancel -> cooperative
 * cancellation). The task stores the fiber, the completion-callback list
 * used by await(), and the suspension handle armed while the fiber is
 * parked, so the scheduler can deliver cancellation into the exact
 * suspension point.
 */
final class FiberTask implements TaskInterface
{
    /** @var null|\Fiber<mixed, mixed, mixed, mixed> */
    private ?\Fiber $fiber = null;

    private TaskState $state = TaskState::Pending;

    private mixed $result = null;

    private ?\Throwable $throwable = null;

    private bool $cancelRequested = false;

    private bool $observed = false;

    /** Lazily created: only tasks that actually suspend pay for the slot. */
    private ?TaskSuspensionSlot $suspension = null;

    /**
     * @var list<\Closure(): void>
     */
    private array $completionCallbacks = [];

    /**
     * @internal instantiated only by FiberScheduler
     *
     * @param \Closure(): mixed $fn
     */
    public function __construct(
        private readonly FiberScheduler $scheduler,
        private readonly int $id,
        private readonly string $name,
        private readonly \Closure $fn,
    ) {}

    #[\Override]
    public function id(): int
    {
        return $this->id;
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function state(): TaskState
    {
        return $this->state;
    }

    #[\Override]
    public function isDone(): bool
    {
        return $this->state->isTerminal();
    }

    #[\Override]
    public function cancel(): bool
    {
        return $this->scheduler->requestCancel($this);
    }

    #[\Override]
    public function result(): mixed
    {
        if ($this->state === TaskState::Succeeded) {
            return $this->result;
        }

        if ($this->state === TaskState::Failed || $this->state === TaskState::Cancelled) {
            throw $this->requireThrowable();
        }

        throw new \LogicException(sprintf(
            '%s has not completed yet (state: %s); await it before reading the result.',
            $this->name,
            $this->state->name,
        ));
    }

    #[\Override]
    public function throwable(): ?\Throwable
    {
        return $this->throwable;
    }

    // ------------------------------------------------------------------
    // Scheduler-internal surface (same namespace, deliberately public)
    // ------------------------------------------------------------------

    public function isOwnedBy(FiberScheduler $scheduler): bool
    {
        return $this->scheduler === $scheduler;
    }

    /**
     * @return null|\Fiber<mixed, mixed, mixed, mixed>
     */
    public function fiber(): ?\Fiber
    {
        return $this->fiber;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function requestCancellation(): void
    {
        $this->cancelRequested = true;
    }

    public function isObserved(): bool
    {
        return $this->observed;
    }

    public function markObserved(): void
    {
        $this->observed = true;
    }

    /**
     * Attaches the started fiber and commits the Pending -> Running
     * transition (no user code runs between attach and fiber start).
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function attach(\Fiber $fiber): void
    {
        $this->fiber = $fiber;
        $this->state = TaskState::Running;
    }

    /** @internal scheduler-facing suspension slot (armed while parked) */
    public function suspension(): TaskSuspensionSlot
    {
        if ($this->suspension === null) {
            $this->suspension = new TaskSuspensionSlot();
        }

        return $this->suspension;
    }

    /** Runs the coroutine body; invoked as the fiber's entry point. */
    public function invoke(): mixed
    {
        return ($this->fn)();
    }

    public function addCompletionCallback(\Closure $callback): void
    {
        if ($this->state->isTerminal()) {
            // Completion already happened (e.g. cancel raced with await):
            // fire immediately so the waiter never parks on a done task.
            $callback();

            return;
        }

        $this->completionCallbacks[] = $callback;
    }

    /**
     * Commits a terminal state and clears the armed suspension handle.
     *
     * @return list<\Closure(): void> the callbacks to fire after the commit
     */
    public function finish(TaskState $state, mixed $result, ?\Throwable $throwable): array
    {
        $this->state = $state;
        $this->result = $result;
        $this->throwable = $throwable;
        $this->suspension?->clear();

        $callbacks = $this->completionCallbacks;
        $this->completionCallbacks = [];

        return $callbacks;
    }

    /** The stored failure reason; only meaningful for Failed/Cancelled. */
    public function requireThrowable(): \Throwable
    {
        if (!$this->throwable instanceof \Throwable) {
            throw new \LogicException(
                sprintf('%s carries no failure reason (state: %s).', $this->name, $this->state->name),
            );
        }

        return $this->throwable;
    }
}
