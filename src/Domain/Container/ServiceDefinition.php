<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\InvalidFactoryException;

/**
 * Typed, immutable service configuration.
 */
final readonly class ServiceDefinition
{
    /**
     * @param list<string> $dependencies canonical dependency ids (validated non-empty below)
     */
    public function __construct(
        public string $id,
        public mixed $factory,
        public array $dependencies = [],
        public ?string $module = null,
        public string $lifetime = ServiceLifetime::SINGLETON,
        public bool $shared = true,
        public bool $lazy = false,
        public array $tags = [],
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Service definition ID must not be empty.');
        }
        if (!is_callable($factory)) {
            throw new \InvalidArgumentException("Service '{$id}' factory must be callable.");
        }
        ServiceLifetime::assert($lifetime);
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || $dependency === '') {
                throw new \InvalidArgumentException("Service '{$id}' dependencies must be non-empty strings.");
            }
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new \InvalidArgumentException("Service '{$id}' tags must be non-empty strings.");
            }
        }
        if ($lifetime !== ServiceLifetime::SINGLETON && $shared) {
            throw new \InvalidArgumentException("Service '{$id}' cannot be shared unless lifetime is singleton.");
        }
    }

    public static function fromArray(string $id, array $config, ?string $module = null): self
    {
        if (!array_key_exists('factory', $config) || !is_callable($config['factory'])) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: callable factory required.");
        }
        $deps = $config['deps'] ?? [];
        if (!is_array($deps)) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: dependencies must be an array.");
        }
        $lifetime = $config['lifetime'] ?? ServiceLifetime::SINGLETON;
        if (!is_string($lifetime)) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: lifetime must be a string.");
        }
        $tags = $config['tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: tags must be an array.");
        }

        // The constructor validates every dependency is a non-empty string,
        // so the narrowed list below matches the enforced runtime shape.
        /** @var list<string> $dependencyIds */
        $dependencyIds = array_values($deps);

        return new self(
            id: $id,
            factory: $config['factory'],
            dependencies: $dependencyIds,
            module: $module,
            lifetime: $lifetime,
            shared: $config['shared'] ?? ($lifetime === ServiceLifetime::SINGLETON),
            lazy: (bool) ($config['lazy'] ?? false),
            tags: array_values($tags),
        );
    }
}
