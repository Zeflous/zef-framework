<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 * Extracted from FiberScheduler during the sonar-zero campaign.
 */

namespace Zef\Framework\Runtime\Async;

/**
 * @internal ascending timer queue owned by a FiberScheduler.
 *
 * Entries are kept sorted by due time; equal-due timers fire in insertion
 * order (the splice-in keeps them stable). Entries carry their task so
 * requestCancel() can splice pending timers out.
 */
final class AsyncTimerQueue
{
    /**
     * @var list<array{due: int, task: FiberTask, wake: \Closure(): void}>
     */
    private array $timers = [];

    public function insert(int $due, FiberTask $task, \Closure $wake): void
    {
        $index = count($this->timers);

        while ($index > 0 && $this->timers[$index - 1]['due'] > $due) {
            --$index;
        }

        array_splice($this->timers, $index, 0, [['due' => $due, 'task' => $task, 'wake' => $wake]]);
    }

    /** Removes every pending timer belonging to the given task. */
    public function splice(FiberTask $task): void
    {
        $kept = [];

        foreach ($this->timers as $entry) {
            if ($entry['task'] !== $task) {
                $kept[] = $entry;
            }
        }

        $this->timers = $kept;
    }

    /** Fires every entry whose deadline has passed $now (in due order). */
    public function fireDue(int $now): void
    {
        while ($this->timers !== [] && $this->timers[0]['due'] <= $now) {
            $entry = array_shift($this->timers);
            ($entry['wake'])();
        }
    }

    public function nextDueNano(): ?int
    {
        if ($this->timers === []) {
            return null;
        }

        return $this->timers[0]['due'];
    }

    public function clear(): void
    {
        $this->timers = [];
    }
}
