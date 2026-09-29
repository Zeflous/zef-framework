<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 * Extracted from FiberScheduler during the sonar-zero campaign.
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal fiber stepping and task-settlement collaborator of FiberScheduler.
 *
 * Runs one fiber step per call: starts the fiber if needed, otherwise
 * resumes it with the resolution payload, then settles the task if it
 * terminated. An uncaught exception inside the fiber propagates out of
 * start()/resume() — it settles the task as Failed (or Cancelled when it
 * is a cancellation) and is never allowed to escape the pump. This class
 * also owns the fiber-to-task map used to find the current coroutine and
 * the post-run failure surfacing.
 */
final class FiberTaskRunner
{
    /**
     * @var null|\WeakMap<\Fiber<mixed, mixed, mixed, mixed>, FiberTask>
     */
    private ?\WeakMap $fiberToTask = null;

    /**
     * Runs one fiber step for the task, starting or resuming it.
     */
    public function step(FiberTask $task, SuspendFail|SuspendValue|null $payload): void
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
                $this->fiberToTask()[$fiber] = $task;
                $task->attach($fiber);
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
     * The task parked on the given fiber, when it belongs to this runner.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    public function taskFor(\Fiber $fiber): ?FiberTask
    {
        return $this->fiberToTask()[$fiber] ?? null;
    }

    /**
     * Surfaces failures after a run settles: the main task's failure first,
     * then the first unobserved failure (never awaited) — failures are
     * never swallowed. A mid-pump deadlock is chained onto the aggregate.
     *
     * @param list<FiberTask> $tasks every task of the finished run
     */
    public function surfaceFailures(FiberTask $mainTask, array $tasks, ?DeadlockException $deadlock = null): void
    {
        $mainState = $mainTask->state();

        if ($mainState === TaskState::Failed || $mainState === TaskState::Cancelled) {
            throw $mainTask->requireThrowable();
        }

        $unobserved = [];

        foreach ($tasks as $task) {
            if ($task->state() === TaskState::Failed && !$task->isObserved()) {
                $unobserved[] = $task->requireThrowable();
            }
        }

        if ($unobserved !== []) {
            throw new UnobservedTaskException($unobserved, $deadlock);
        }
    }

    /**
     * Commits the terminal state and fires the task's completion callbacks.
     */
    public function finish(FiberTask $task, TaskState $state, mixed $result, ?\Throwable $throwable): void
    {
        foreach ($task->finish($state, $result, $throwable) as $callback) {
            $callback();
        }
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

    /**
     * @return \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, FiberTask>
     */
    private function fiberToTask(): \WeakMap
    {
        return $this->fiberToTask ??= new \WeakMap();
    }
}
