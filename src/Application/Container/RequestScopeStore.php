<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from ContainerResolver during the sonar-zero campaign
 * (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * @internal per-request-scope instance storage of ContainerResolver.
 *
 * One WeakMap slot per RequestScope holds that scope's resolved
 * instances. WeakMap semantics let an unreleased scope be
 * garbage-collected together with its instances.
 */
final class RequestScopeStore
{
    /**
     * @var null|\WeakMap<RequestScope,array<string,mixed>>
     */
    private ?\WeakMap $instances = null;

    /** Registers a freshly created scope with an empty instance table. */
    public function register(RequestScope $scope): void
    {
        $this->instances()[$scope] = [];
    }

    /** Drops a released scope (and its instances) from the store. */
    public function release(RequestScope $scope): void
    {
        if ($this->instances instanceof \WeakMap) {
            unset($this->instances[$scope]);
        }
    }

    public function has(RequestScope $scope, string $id): bool
    {
        $instancesMap = $this->instances;

        return $instancesMap instanceof \WeakMap
            && isset($instancesMap[$scope])
            && array_key_exists($id, $instancesMap[$scope]);
    }

    public function get(RequestScope $scope, string $id): mixed
    {
        $instancesMap = $this->instances;
        if (!$instancesMap instanceof \WeakMap
            || !isset($instancesMap[$scope])
            || !array_key_exists($id, $instancesMap[$scope])
        ) {
            throw new \LogicException("No instance for '{$id}' in the active request scope.");
        }

        return $instancesMap[$scope][$id];
    }

    public function set(RequestScope $scope, string $id, mixed $value): void
    {
        $instancesMap = $this->instances();
        $state = $instancesMap[$scope] ?? [];
        $state[$id] = $value;
        $instancesMap[$scope] = $state;
    }

    /**
     * @return \WeakMap<RequestScope,array<string,mixed>>
     */
    private function instances(): \WeakMap
    {
        $this->instances ??= new \WeakMap();

        return $this->instances;
    }
}
