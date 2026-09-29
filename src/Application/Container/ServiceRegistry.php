<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * @internal
 */
final class ServiceRegistry extends ContainerEventListenerRegistry
{
    /**
     * @var array<string,ServiceDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<string,string>
     */
    private array $aliases = [];

    /**
     * @var array<string,mixed>
     */
    private array $instances = [];

    /**
     * @var array<string,null|string>
     */
    private array $moduleOf = [];

    public function hasFactory(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function hasAlias(string $id): bool
    {
        return isset($this->aliases[$id]);
    }

    /** @return array<string,ServiceDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array<string,mixed> */
    public function factories(): array
    {
        $out = [];
        foreach ($this->definitions as $id => $definition) {
            $out[$id] = $definition->factory;
        }

        return $out;
    }

    /** @return array<string,string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** @return array<string,array<int|string,mixed>> */
    public function depsOf(): array
    {
        $out = [];
        foreach ($this->definitions as $id => $definition) {
            $out[$id] = $definition->dependencies;
        }

        return $out;
    }

    /** @return array<string,null|string> */
    public function moduleOf(): array
    {
        return $this->moduleOf;
    }

    /** @return array<string,string> */
    public function lifetimeOf(): array
    {
        $out = [];
        foreach ($this->definitions as $id => $definition) {
            $out[$id] = $definition->lifetime;
        }

        return $out;
    }

    public function hasInstance(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    public function instance(string $id): mixed
    {
        return $this->instances[$id];
    }

    public function setInstance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function clearInstances(): void
    {
        $this->instances = [];
    }

    public function addDefinition(ServiceDefinition $definition): void
    {
        $this->definitions[$definition->id] = $definition;
        $this->moduleOf[$definition->id] = $definition->module;
    }

    public function addFactory(
        string $id,
        callable $factory,
        array $deps,
        ?string $module,
        string $lifetime,
    ): void {
        // $deps arrives as a (possibly string-keyed) map of dependency ids
        // from Container::register(); the ServiceDefinition constructor
        // validates every entry is a non-empty string.
        /** @var list<string> $dependencyIds */
        $dependencyIds = array_values($deps);
        $this->addDefinition(
            new ServiceDefinition(
                $id,
                $factory,
                $dependencyIds,
                $module,
                $lifetime,
                $lifetime === ServiceLifetime::SINGLETON,
            )
        );
    }

    public function addAlias(string $alias, string $target, ?string $module): void
    {
        $this->aliases[$alias] = $target;
        $this->moduleOf[$alias] = $module;
    }
}

// Read-only view of the service registry exposed to application code.
