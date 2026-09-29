<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Counter barrier for "wait until N pieces of work finished" joins.
 *
 * add() registers pending work, done() retires it, await() parks until the
 * counter hits zero. Every done() must be matched by exactly one add():
 * done() at zero is a bookkeeping bug and throws LogicException, mirroring
 * the semaphore's fail-fast stance.
 */
final class WaitGroup
{
    private int $count = 0;

    /**
     * @var list<SuspensionHandle>
     */
    private array $waiters = [];

    public function __construct(private readonly FiberScheduler $scheduler) {}

    /** Registers $delta (>= 1) units of pending work. */
    public function add(int $delta = 1): void
    {
        if ($delta < 1) {
            throw new \InvalidArgumentException(sprintf('WaitGroup::add() requires a positive delta, got %d.', $delta));
        }

        $this->count += $delta;
    }

    /** Retires one unit; wakes every parked awaiter once the count hits zero. */
    public function done(): void
    {
        if ($this->count === 0) {
            throw new \LogicException('WaitGroup::done() called without a matching add()');
        }

        --$this->count;

        if ($this->count === 0) {
            $parked = $this->waiters;
            $this->waiters = [];

            foreach ($parked as $waiter) {
                $waiter->deliver(null);
            }
        }
    }

    /** Units of work still pending. */
    public function count(): int
    {
        return $this->count;
    }

    /** Returns immediately at zero; otherwise parks until the count hits zero. */
    public function await(): void
    {
        if ($this->count === 0) {
            return;
        }

        $handle = $this->scheduler->beginSuspension('waitgroup await');
        $this->waiters[] = $handle;

        // No settle-splice needed: done() delivers to every queued waiter and
        // a stale (cancelled) handle ignores deliveries, so splicing cancelled
        // entries out would be unobservable bookkeeping.
        $this->scheduler->awaitSuspension($handle);
    }
}
