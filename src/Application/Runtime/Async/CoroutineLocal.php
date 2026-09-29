<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (Application layer: async primitives)
 * Added in v2.26.0 (Async Runtime: fiber scheduler, channels, cancellation).
 */

namespace Zef\Framework\Runtime\Async;

/**
 * Per-coroutine key/value storage (fiber-local state).
 *
 * Each coroutine sees its own isolated bag of values — the async-native
 * replacement for static/global request state. The store is keyed by fiber
 * identity through a WeakMap, so finished coroutines are garbage-collected
 * together with their state. Calls outside any coroutine are a programming
 * error and throw LogicException.
 */
final class CoroutineLocal
{
    /**
     * Lazily-created backing store (see {@see store()}): the constructor
     * stays free of object creation (S2830) and the map is built on first use.
     *
     * @var null|\WeakMap<\Fiber<mixed, mixed, mixed, mixed>, array<string, mixed>>
     */
    private ?\WeakMap $store = null;

    /**
     * Reads $key from the current coroutine's scope, falling back to
     * $default when the key was never set in this scope.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $scope = $this->scope();

        return array_key_exists($key, $scope) ? $scope[$key] : $default;
    }

    /** Writes $key into the current coroutine's scope (never shared). */
    public function set(string $key, mixed $value): void
    {
        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            throw new \LogicException('CoroutineLocal can only be used inside a coroutine.');
        }

        $scope = $this->store()[$fiber] ?? [];
        $scope[$key] = $value;
        // @phpstan-ignore offsetAssign.dimType
        $this->store()[$fiber] = $scope;
    }

    /**
     * The backing WeakMap, created on first use (never in the constructor).
     *
     * @return \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, array<string, mixed>>
     */
    private function store(): \WeakMap
    {
        $this->store ??= new \WeakMap();

        return $this->store;
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(): array
    {
        $fiber = \Fiber::getCurrent();

        if (!$fiber instanceof \Fiber) {
            throw new \LogicException('CoroutineLocal can only be used inside a coroutine.');
        }

        return $this->store()[$fiber] ?? [];
    }
}
