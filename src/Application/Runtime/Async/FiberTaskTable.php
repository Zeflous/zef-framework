<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 * Extracted from FiberScheduler during the sonar-zero campaign.
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal live-task registry owned by a FiberScheduler.
 *
 * Hands out monotonically increasing task ids, keeps every task of the
 * current run (for deadlock diagnostics and failure surfacing), and keeps
 * the id counter stable across runs while clear() drops finished tasks.
 */
final class FiberTaskTable
{
    private int $nextId = 1;

    /**
     * @var list<FiberTask>
     */
    private array $tasks = [];

    /**
     * Creates and registers a task; the id counter is bumped in a dedicated
     * statement so the returned task keeps the pre-increment id.
     *
     * @param \Closure(): mixed $fn
     */
    public function create(FiberScheduler $scheduler, string $name, string $prefix, \Closure $fn): FiberTask
    {
        $id = $this->nextId;
        ++$this->nextId;
        $task = new FiberTask($scheduler, $id, $name !== '' ? $name : sprintf('%s-%d', $prefix, $id), $fn);
        $this->tasks[] = $task;

        return $task;
    }

    /** @return list<FiberTask> */
    public function all(): array
    {
        return $this->tasks;
    }

    /** @return list<string> */
    public function suspendedNames(): array
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

    public function clear(): void
    {
        $this->tasks = [];
    }
}
