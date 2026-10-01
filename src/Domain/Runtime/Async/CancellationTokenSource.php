<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Domain layer: async ports & value objects)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Single-cancellation registry: the write side of cancellation control flow.
 *
 * The source owns one boolean and one callback list; token() hands out a
 * read-only CancellationToken view for coroutines and blocking primitives
 * (semaphore acquisition, channel waits). cancel() fires all registered
 * callbacks synchronously in registration order and is idempotent — the
 * first call returns true, every later call reports false without re-firing.
 */
final class CancellationTokenSource
{
    private bool $cancelled = false;

    /**
     * @var array<int, callable(): void>
     */
    private array $callbacks = [];

    public function token(): CancellationToken
    {
        return new CancellationToken(
            isCancelled: fn (): bool => $this->cancelled,
            register: function (callable $callback): \Closure {
                if ($this->cancelled) {
                    // Registering on an already-cancelled source fires at once,
                    // mirroring "immediately if it already is" semantics.
                    $callback();

                    return static fn (): bool => false;
                }

                $this->callbacks[] = $callback;
                $index = array_key_last($this->callbacks);

                return function () use ($index): bool {
                    if (isset($this->callbacks[$index])) {
                        unset($this->callbacks[$index]);

                        return true;
                    }

                    return false;
                };
            },
            exceptionFactory: fn (): TaskCancelledException => new TaskCancelledException(
                'cancellation requested through the token source',
            ),
        );
    }

    /**
     * Cancels the source and fires registered callbacks in registration
     * order. Callbacks run synchronously on the caller's stack.
     *
     * Audit #323: a throwing callback no longer permanently drops the
     * remaining callbacks — every registered callback runs (isolated), and
     * the FIRST exception is rethrown after the batch completes. The
     * callback list is snapshotted and cleared BEFORE firing, so the
     * register-after-cancel "fires at once" path always observes an empty
     * list, and cancel() stays idempotent.
     */
    public function cancel(): bool
    {
        if ($this->cancelled) {
            return false;
        }

        $this->cancelled = true;

        $pending = $this->callbacks;
        $this->callbacks = [];

        $firstError = null;
        foreach ($pending as $callback) {
            try {
                $callback();
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }

        return true;
    }

    /** True once cancel() succeeded at least once. */
    public function isCancellationRequested(): bool
    {
        return $this->cancelled;
    }
}
