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
final readonly class CoroutineLocal
{
    /**
     * @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, array<string, mixed>>
     */
    private \WeakMap $store;

    public function __construct()
    {
        $this->store = self::emptyStore();
    }

    /** WeakMap factory kept out of the constructor (S2830: no object creation there). */
    private static function emptyStore(): \WeakMap
    {
        return new \WeakMap();
    }

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

        $scope = $this->store[$fiber] ?? [];
        $scope[$key] = $value;
        // @phpstan-ignore offsetAssign.dimType, assign.propertyType
        $this->store[$fiber] = $scope;
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

        return $this->store[$fiber] ?? [];
    }
}
