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
 *
 * Since the sonar-zero campaign the timer bookkeeping lives in
 * AsyncTimerQueue, fiber stepping/settlement in FiberTaskRunner, and the
 * live-task registry in FiberTaskTable.
 */
final class FiberScheduler
{
    private const int NANOS_PER_MILLISECOND = 1_000_000;

    private const int NANOS_PER_SECOND = 1_000_000_000;

    /**
     * Ready queue of fiber steps (spawn starts, suspension resolutions),
     * drained FIFO for deterministic, starvation-free round-robin order.
     *
     * @var list<\Closure(): mixed>
     */
    private array $ready = [];

    private bool $running = false;

    private ?AsyncTimerQueue $timers = null;

    private ?FiberTaskRunner $runner = null;

    private ?FiberTaskTable $taskTable = null;

    public function __construct(
        private readonly MonotonicClockInterface $clock = new HrMonotonicClock(),
        private readonly SleeperInterface $sleeper = new SystemSleeper(),
    ) {}

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
        $task = $this->taskTable()->create($this, $name, 'task', fn (): mixed => $fn());
        $this->ready[] = fn (): mixed => $this->runner()->step($task, null);

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
        $task = $this->taskTable()->create($this, $name, 'timer', fn (): mixed => $fn());
        $this->timers()->insert(
            $this->clock->nowNano() + (int) round($seconds * self::NANOS_PER_SECOND),
            $task,
            fn (): mixed => $this->runner()->step($task, null),
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
                throw new AsyncTimeoutException(
                    sprintf('the operation exceeded its %.6g second timeout', $seconds),
                    $cancellation->getCode(),
                    previous: $cancellation,
                );
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
        $this->timers()->insert(
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
                $this->runner()->surfaceFailures($mainTask, $this->taskTable()->all(), $deadlock);

                throw $deadlock;
            }
            $this->runner()->surfaceFailures($mainTask, $this->taskTable()->all());

            return 0;
        } finally {
            $this->running = false;
            $this->ready = [];
            $this->timers()->clear();
            $this->taskTable()->clear();
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
        $fiber = \Fiber::getCurrent();
        $current = $fiber instanceof \Fiber ? $this->runner()->taskFor($fiber) : null;

        if ($current === null) {
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

        $handle = new SuspensionHandle($this, $current);
        $current->suspension()->arm($handle);

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
        $this->ready[] = fn (): mixed => $this->runner()->step($task, $payload);
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
        $this->timers()->splice($task);

        $fiber = $task->fiber();

        if (!$fiber instanceof \Fiber) {
            // Never started (spawn-queued or timer-pending): settle at once.
            $this->runner()->finish(
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

    // ------------------------------------------------------------------
    // Pump internals
    // ------------------------------------------------------------------

    private function pump(): void
    {
        while (true) {
            while ($this->ready !== []) {
                $step = array_shift($this->ready);
                $step();
            }

            $this->timers()->fireDue($this->clock->nowNano());

            if ($this->ready !== []) {
                continue;
            }

            $nextDue = $this->timers()->nextDueNano();

            if ($nextDue === null) {
                $suspended = $this->taskTable()->suspendedNames();

                if ($suspended !== []) {
                    throw new DeadlockException(sprintf(
                        'deadlock: %d suspended task(s) with nothing pending to wake them: %s',
                        count($suspended),
                        implode(', ', $suspended),
                    ));
                }

                return;
            }

            $this->sleeper->sleepMilliseconds(
                (int) ceil(($nextDue - $this->clock->nowNano()) / self::NANOS_PER_MILLISECOND)
            );
        }
    }

    private function assertOwnedTask(TaskInterface $task, string $method): FiberTask
    {
        if (!$task instanceof FiberTask || !$task->isOwnedBy($this)) {
            throw new \InvalidArgumentException(sprintf('%s only accepts tasks spawned by this scheduler.', $method));
        }

        return $task;
    }

    private function assertNonNegative(float $seconds, string $method): void
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(
                sprintf('%s requires a non-negative duration, got %F seconds.', $method, $seconds)
            );
        }
    }

    private function timers(): AsyncTimerQueue
    {
        return $this->timers ??= new AsyncTimerQueue();
    }

    private function runner(): FiberTaskRunner
    {
        return $this->runner ??= new FiberTaskRunner();
    }

    private function taskTable(): FiberTaskTable
    {
        return $this->taskTable ??= new FiberTaskTable();
    }
}
