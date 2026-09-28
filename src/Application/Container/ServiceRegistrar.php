<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\InvalidFactoryException;

/**
 * @internal
 */
final readonly class ServiceRegistrar
{
    public function __construct(private ServiceRegistry $registry) {}

    public function register(
        string $id,
        callable $factory,
        array $deps = [],
        ?string $module = null,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void {
        // N-19 (issue #176): an empty/invalid identifier is its own failure —
        // reporting it as "already registered" described a collision that
        // never happened.
        if ($id === '') {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: service ID must not be empty.");
        }
        if ($this->registry->hasFactory($id) || $this->registry->hasAlias($id)) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: service ID already registered.");
        }
        ServiceLifetime::assert($lifetime);
        foreach ($deps as $dep) {
            if (!is_string($dep) || $dep === '') {
                throw new InvalidFactoryException("Factory for '{$id}' is invalid: dependency IDs must be non-empty strings.");
            }
        }
        $this->registry->addFactory($id, $factory, $deps, $module, $lifetime);
    }

    public function alias(string $alias, string $target, ?string $module = null): void
    {
        // N-19 (issue #176): same split as register() — an empty alias is an
        // invalid identifier, not a collision.
        if ($alias === '') {
            throw new InvalidFactoryException("Factory for '{$alias}' is invalid: alias must not be empty.");
        }
        if ($this->registry->hasFactory($alias) || $this->registry->hasAlias($alias)) {
            throw new InvalidFactoryException("Factory for '{$alias}' is invalid: ID already registered.");
        }
        if ($target === '') {
            throw new InvalidFactoryException("Alias '{$alias}' must target a non-empty service ID.");
        }
        $this->registry->addAlias($alias, $target, $module);
    }
}

// @internal
