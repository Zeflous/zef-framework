<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

use Zef\Framework\Runtime\SleeperInterface;
use Zef\Framework\Runtime\SystemSleeper;

/**
 * Fiber-based coroutine scheduler — the v2.26.0 async runtime kernel.
 *
 * The scheduler owns an explicit ready-queue of fiber steps and a timer
 * queue driven by the monotonic clock port. It is single-threaded and
 * cooperative: coroutines run on native PHP fibers and hand control back at
 * well-defined suspension points (await, suspend, sleep, channel, semaphore,
 * wait group). There is no preemption; a coroutine that never suspends
 * starves its peers by design, which keeps the execution model deterministic
 * and debuggable.
 *
 * Semantics worth knowing by heart:
 * - spawn() only queues; the fiber starts on the next pump tick.
 * - cancel() is a cooperative request: a running coroutine observes it at
 *   its next suspension point as TaskCancelledException. A task cancelled
 *   before its first step settles as Cancelled without executing.
 * - await() on a failed task rethrows the original throwable; await() on a
 *   cancelled task throws TaskCancelledException.
 * - After the run settles, the first unobserved failure (never awaited)
 *   surfaces as UnobservedTaskException — failures are never swallowed.
 * - If every remaining task is suspended and nothing can wake them (no
 *   timers, empty ready queue), the run aborts with DeadlockException.
 * - Timeouts are enforced at suspension points only: a coroutine that never
 *   suspends cannot be interrupted (cooperative, not preemptive).
 */
final class FiberScheduler
{
    private const int NANOS_PER_MILLISECOND = 1_000_000;

    private const int NANOS_PER_SECOND = 1_000_000_000;

    /**
     * Ready queue of fiber steps (spawn starts, suspension resolutions),
     * drained FIFO for deterministic, starvation-free round-robin order.
     *
     * @var \SplQueue<\Closure>
     */
    private \SplQueue $ready;

    /**
     * Timer queue kept ascending by due; equal-due timers fire in insertion
     * order (splice-in keeps them stable). Entries carry their task so
     * requestCancel() can splice pending timers out.
     *
     * @var list<array{due: int, task: FiberTask, wake: \Closure(): void}>
     */
    private array $timers = [];

    private int $nextId = 1;

    /**
     * @var list<FiberTask>
     */
    private array $tasks = [];

    private bool $running = false;

    /**
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, FiberTask>
     */
    private \WeakMap $fiberToTask;

    public function __construct(
        private readonly MonotonicClockInterface $clock = new HrMonotonicClock(),
        private readonly SleeperInterface $sleeper = new SystemSleeper(),
    ) {
        $this->ready = new \SplQueue();
        $this->fiberToTask = new \WeakMap();
    }

    // ------------------------------------------------------------------
    // Public coroutine API
    // ------------------------------------------------------------------

    /**
     * Queues a new coroutine. The task stays Pending until the scheduler
     * pumps it (immediately inside run(), at the next tick otherwise);
     * spawn never executes user code synchronously.
     *
     * @param callable(): mixed $fn
     */
    public function spawn(callable $fn, string $name = ''): TaskInterface
    {
        $id = $this->nextId++;
        $task = new FiberTask($this, $id, $name !== '' ? $name : sprintf('task-%d', $id), fn (): mixed => $fn());
        $this->tasks[] = $task;
        $this->ready->enqueue(function () use ($task): void { $this->step($task, null); });

        return $task;
    }

    /**
     * Queues $fn to run once the clock passes $seconds (measured from now).
     * Cancelling the returned task before the deadline removes the timer;
     * cancelling it while the callback runs is a normal cooperative cancel.
     *
     * @param callable(): mixed $fn
     */
    public function delay(float $seconds, callable $fn, string $name = ''): TaskInterface
    {
        $this->assertNonNegative($seconds, 'delay()');
        $id = $this->nextId++;
        $task = new FiberTask($this, $id, $name !== '' ? $name : sprintf('timer-%d', $id), fn (): mixed => $fn());
        $this->tasks[] = $task;
        $this->insertTimer(
            $this->clock->nowNano() + (int) round($seconds * self::NANOS_PER_SECOND),
            $task,
            function () use ($task): void { $this->step($task, null); },
        );

        return $task;
    }

    /**
     * Suspends the current coroutine until $task settles, then returns its
     * result (or rethrows its failure/cancellation). Marks the task observed,
     * so its failure will not surface again as unobserved after the run.
     */
    public function await(TaskInterface $task): mixed
    {
        $target = $this->assertOwnedTask($task, 'await()');
        $target->markObserved();

        // No isDone fast-path here on purpose: for an already-settled target
        // the completion callback fires synchronously, so the generic
        // suspension path resolves on the next pump tick without a branch.
        $handle = $this->beginSuspension('await()');
        $target->addCompletionCallback(static fn () => $handle->deliver(null));
        $this->awaitSuspension($handle);

        return $target->result();
    }

    /**
     * Awaits every task in order and returns the results in input order.
     * All tasks are marked observed upfront (the call expresses the intent
     * to await all of them); the first failure is rethrown and does NOT
     * cancel the remaining tasks.
     *
     * @param list<TaskInterface> $tasks
     *
     * @return list<mixed>
     */
    public function awaitAll(array $tasks): array
    {
        foreach ($tasks as $task) {
            $this->assertOwnedTask($task, 'awaitAll()')->markObserved();
        }

        $results = [];
        foreach ($tasks as $task) {
            $results[] = $this->await($task);
        }

        return $results;
    }

    /**
     * Runs a task guarded by a deadline: the guard timer cancels the inner
     * task at $seconds if it has not settled by then. Returns the inner
     * result, or throws AsyncTimeoutException when the deadline won. A
     * coroutine that never suspends cannot be interrupted (cooperative
     * cancellation) and will still win the race by finishing first.
     *
     * @param callable(): mixed $fn
     */
    public function timeout(float $seconds, callable $fn): mixed
    {
        $this->assertNonNegative($seconds, 'timeout()');
        $inner = $this->assertOwnedTask($this->spawn($fn), 'timeout()');
        $guard = $this->delay($seconds, static fn (): bool => $inner->cancel());

        try {
            return $this->await($inner);
        } catch (TaskCancelledException $cancellation) {
            if ($inner->state() === TaskState::Cancelled) {
                throw new AsyncTimeoutException(sprintf('the operation exceeded its %.6g second timeout', $seconds), $cancellation->getCode(), previous: $cancellation);
            }

            throw $cancellation;
        } finally {
            $guard->cancel();
        }
    }

    /**
     * Cooperatively yields control: re-queues the current coroutine at the
     * tail of the ready queue so already-queued peers make progress first.
     */
    public function suspend(): void
    {
        $handle = $this->beginSuspension('suspend()');
        $handle->deliver(null);
        $this->awaitSuspension($handle);
    }

    /**
     * Suspends the current coroutine until the clock passes $seconds. The
     * timer is driven by the monotonic clock port + sleeper port, so tests
     * can advance time deterministically without real waits.
     */
    public function sleep(float $seconds): void
    {
        $this->assertNonNegative($seconds, 'sleep()');

        // sleep(0) needs no fast-path: a due-now timer resolves on the next
        // fireDueTimers pass, which is exactly one cooperative yield.
        // Routing through beginSuspension() applies the deferred-cancel
        // entry guard: a cancel-requested task cannot park into a new timer.
        $handle = $this->beginSuspension('sleep()');
        $this->insertTimer(
            $this->clock->nowNano() + (int) round($seconds * self::NANOS_PER_SECOND),
            $handle->owner(),
            static fn () => $handle->deliver(null),
        );
        $this->awaitSuspension($handle);
    }

    /**
     * Drives the whole run: starts $main as the observed "main" task, pumps
     * until every task settles, then surfaces failures (main first, then the
     * first unobserved failure, then deadlocks detected mid-pump). Returns 0
     * on success; failures are thrown, not returned as exit codes.
     *
     * @param callable(): mixed $main
     */
    public function run(callable $main): int
    {
        if ($this->running) {
            throw new \LogicException('the scheduler is already running');
        }

        $this->running = true;

        try {
            // The main task needs no observed flag: surfaceFailures() keys
            // off its state before the unobserved-failure scan runs.
            $mainTask = $this->assertOwnedTask($this->spawn($main, 'main'), 'run()');

            try {
                $this->pump();
            } catch (DeadlockException $deadlock) {
                // N-12 (issue #176): the deadlock-abort path used to skip
                // surfaceFailures() entirely, so a main/unobserved failure
                // the run was already carrying was silently discarded —
                // violating the documented surfacing order (main first,
                // then unobserved failures, then deadlocks detected
                // mid-pump). Surface the failures first; the deadlock is
                // chained as the previous exception of an unobserved
                // aggregate, or rethrown unchanged when nothing surfaces.
                $this->surfaceFailures($mainTask, $deadlock);

                throw $deadlock;
            }
            $this->surfaceFailures($mainTask);

            return 0;
        } finally {
            $this->running = false;
            $this->reset();
        }
    }

    // ------------------------------------------------------------------
    // Suspension surface used by the blocking primitives
    // ------------------------------------------------------------------

    /**
     * @internal creates and arms a suspension handle for the current task;
     * the calling primitive registers the handle wherever its wake-up lives
     */
    public function beginSuspension(string $context): SuspensionHandle
    {
        $current = $this->requireCurrentTask($context);
        if ($current->isCancelRequested()) {
            // Deferred-cancel (ZEF-DEEP-03): a task whose cancellation was
            // requested while it ran (including a committed value returned
            // after a delivery/cancel race) must not park again. Surfacing
            // the cancellation HERE — before the calling primitive registers
            // any wake-up — guarantees no dead waiter entries are left behind
            // to swallow future permits or messages.
            throw new TaskCancelledException(sprintf('%s was cancelled while suspended', $current->name()));
        }
        $handle = new SuspensionHandle($this, $current);
        $current->arm($handle);

        return $handle;
    }

    /**
     * @internal parks the current fiber until the armed handle settles.
     * Returns the delivered value or rethrows the delivered failure.
     */
    public function awaitSuspension(SuspensionHandle $handle): mixed
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
     * @internal re-queues a settled suspension as a fiber step carrying the
     * resolution payload. Called by SuspensionHandle deliver()/fail() and by
     * the wake closures of queued spawns/timers.
     */
    public function enqueueResume(FiberTask $task, SuspendFail|SuspendValue $payload): void
    {
        $this->ready->enqueue(function () use ($task, $payload): void { $this->step($task, $payload); });
    }

    /**
     * @internal cooperative cancellation request from TaskInterface::cancel()
     */
    public function requestCancel(FiberTask $task): bool
    {
        if ($task->isDone() || $task->isCancelRequested()) {
            return false;
        }

        $task->requestCancellation();
        $this->spliceTimers($task);

        $fiber = $task->fiber();

        if (!$fiber instanceof \Fiber) {
            // Never started (spawn-queued or timer-pending): settle at once.
            $this->finish(
                $task,
                TaskState::Cancelled,
                null,
                new TaskCancelledException(sprintf('%s was cancelled before it started', $task->name())),
            );

            return true;
        }

        if ($fiber->isSuspended()) {
            $armed = $task->armedHandle();

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

    // ------------------------------------------------------------------
    // Pump internals
    // ------------------------------------------------------------------

    private function pump(): void
    {
        while (true) {
            $this->drainReady();
            $this->fireDueTimers();

            if (!$this->ready->isEmpty()) {
                continue;
            }

            $nextDue = $this->nextDueNano();

            if ($nextDue === null) {
                $suspended = $this->suspendedNames();

                if ($suspended !== []) {
                    throw new DeadlockException(sprintf(
                        'deadlock: %d suspended task(s) with nothing pending to wake them: %s',
                        count($suspended),
                        implode(', ', $suspended),
                    ));
                }

                return;
            }

            $this->sleeper->sleepMilliseconds((int) ceil(($nextDue - $this->clock->nowNano()) / self::NANOS_PER_MILLISECOND));
        }
    }

    private function drainReady(): void
    {
        while (!$this->ready->isEmpty()) {
            $step = $this->ready->dequeue();
            $step();
        }
    }

    private function fireDueTimers(): void
    {
        $now = $this->clock->nowNano();

        while ($this->timers !== [] && $this->timers[0]['due'] <= $now) {
            $entry = array_shift($this->timers);
            ($entry['wake'])();
        }
    }

    private function nextDueNano(): ?int
    {
        if ($this->timers === []) {
            return null;
        }

        return $this->timers[0]['due'];
    }

    /**
     * @return list<string>
     */
    private function suspendedNames(): array
    {
        $names = [];

        foreach ($this->tasks as $task) {
            $fiber = $task->fiber();

            if ($task->isDone() || !$fiber instanceof \Fiber || !$fiber->isSuspended()) {
                continue;
            }

            $names[] = $task->name();
        }

        return $names;
    }

    /**
     * Runs one fiber step: starts the fiber if needed, otherwise resumes it
     * with the resolution payload, then settles the task if it terminated.
     *
     * An uncaught exception inside the fiber propagates out of start()/
     * resume() — it settles the task as Failed (or Cancelled when it is a
     * cancellation) and is never allowed to escape the pump.
     */
    private function step(FiberTask $task, SuspendFail|SuspendValue|null $payload): void
    {
        if ($task->isDone()) {
            return;
        }

        $fiber = $task->fiber();

        try {
            if (!$fiber instanceof \Fiber) {
                // A task cancelled before its first step is settled directly
                // by requestCancel(); this branch only starts live tasks.
                $fiber = new \Fiber(static fn (): mixed => $task->invoke());
                $this->fiberToTask[$fiber] = $task;
                $task->attach($fiber);
                $task->markRunning();
                $fiber->start();
            } else {
                $fiber->resume($payload);
            }
        } catch (\Throwable $exception) {
            $this->settleFromThrowable($task, $exception);

            return;
        }

        $this->settleIfTerminated($task, $fiber);
    }

    /**
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    private function settleIfTerminated(FiberTask $task, \Fiber $fiber): void
    {
        if (!$fiber->isTerminated()) {
            return;
        }

        $this->finish($task, TaskState::Succeeded, $fiber->getReturn(), null);
    }

    private function settleFromThrowable(FiberTask $task, \Throwable $exception): void
    {
        if ($task->isCancelRequested() || $exception instanceof TaskCancelledException) {
            $this->finish($task, TaskState::Cancelled, null, $exception);

            return;
        }

        $this->finish($task, TaskState::Failed, null, $exception);
    }

    private function finish(FiberTask $task, TaskState $state, mixed $result, ?\Throwable $throwable): void
    {
        foreach ($task->finish($state, $result, $throwable) as $callback) {
            $callback();
        }
    }

    private function surfaceFailures(FiberTask $mainTask, ?DeadlockException $deadlock = null): void
    {
        $mainState = $mainTask->state();

        if ($mainState === TaskState::Failed || $mainState === TaskState::Cancelled) {
            throw $mainTask->requireThrowable();
        }

        $unobserved = [];

        foreach ($this->tasks as $task) {
            if ($task->state() === TaskState::Failed && !$task->isObserved()) {
                $unobserved[] = $task->requireThrowable();
            }
        }

        if ($unobserved !== []) {
            throw new UnobservedTaskException($unobserved, $deadlock);
        }
    }

    private function reset(): void
    {
        $this->tasks = [];
        $this->timers = [];
        $this->ready = new \SplQueue();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertTimer(int $due, FiberTask $task, \Closure $wake): void
    {
        $index = count($this->timers);

        while ($index > 0 && $this->timers[$index - 1]['due'] > $due) {
            --$index;
        }

        array_splice($this->timers, $index, 0, [['due' => $due, 'task' => $task, 'wake' => $wake]]);
    }

    private function spliceTimers(FiberTask $task): void
    {
        $kept = [];

        foreach ($this->timers as $entry) {
            if ($entry['task'] !== $task) {
                $kept[] = $entry;
            }
        }

        $this->timers = $kept;
    }

    private function assertOwnedTask(TaskInterface $task, string $method): FiberTask
    {
        if (!$task instanceof FiberTask || !$task->isOwnedBy($this)) {
            throw new \InvalidArgumentException(sprintf('%s only accepts tasks spawned by this scheduler.', $method));
        }

        return $task;
    }

    private function requireCurrentTask(string $context): FiberTask
    {
        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber || !isset($this->fiberToTask[$fiber])) {
            throw new \LogicException(sprintf(
                '%s can only be called from inside a coroutine managed by this scheduler.',
                $context,
            ));
        }

        return $this->fiberToTask[$fiber];
    }

    private function assertNonNegative(float $seconds, string $method): void
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(sprintf('%s requires a non-negative duration, got %F seconds.', $method, $seconds));
        }
    }
}
