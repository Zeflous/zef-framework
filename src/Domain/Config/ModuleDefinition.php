<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Router\RouteDefinition;
use Zef\Framework\Validation\Identifier;

/**
 * Typed, immutable module configuration boundary.
 */
final readonly class ModuleDefinition
{
    /**
     * @var array<string,ServiceDefinition>
     */
    public array $services;

    /**
     * @var array<string,string>
     */
    public array $aliases;

    /**
     * @var list<RouteDefinition>
     */
    public array $routes;

    /**
     * @var list<string>
     */
    public array $dependencies;

    /** @param array<int,string> $dependencies */
    public function __construct(
        public string $name,
        array $services = [],
        array $aliases = [],
        array $routes = [],
        /** @var array<string,mixed> */
        public array $extensions = [],
        array $dependencies = [],
    ) {
        $name = trim($name);
        Identifier::assertModuleName($name);
        $this->services = self::validatedServices($services);
        $this->aliases = self::validatedAliases($aliases);
        $this->routes = self::validatedRoutes($routes);
        $this->dependencies = self::normalizedDependencies($dependencies);
    }

    public static function fromArray(string $name, array $config): self
    {
        $servicesRaw = $config['services'] ?? [];
        $aliasesRaw = $config['aliases'] ?? [];
        $routesRaw = $config['routes'] ?? [];
        $dependenciesRaw = $config['dependencies'] ?? ($config['requires'] ?? []);
        if (!is_array($servicesRaw)) {
            throw new \InvalidArgumentException("Module '{$name}' services must be an array.");
        }
        if (!is_array($aliasesRaw)) {
            throw new \InvalidArgumentException("Module '{$name}' aliases must be an array.");
        }
        if (!is_array($routesRaw)) {
            throw new \InvalidArgumentException("Module '{$name}' routes must be an array.");
        }
        if (!is_array($dependenciesRaw)) {
            throw new \InvalidArgumentException("Module '{$name}' dependencies must be an array.");
        }
        $services = [];
        foreach ($servicesRaw as $id => $definition) {
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException("Module '{$name}' has an invalid service ID.");
            }
            if ($definition instanceof ServiceDefinition) {
                if ($definition->id !== $id) {
                    throw new \InvalidArgumentException(
                        "Service definition ID '{$definition->id}' does not match registry key '{$id}'.",
                    );
                }
                $services[$id] = $definition->module === $name
                    ? $definition
                    : new ServiceDefinition(
                        $definition->id,
                        $definition->factory,
                        $definition->dependencies,
                        $name,
                        $definition->lifetime,
                        $definition->shared,
                        $definition->lazy,
                        $definition->tags,
                    );

                continue;
            }
            if (!is_array($definition)) {
                throw new \InvalidArgumentException("Service '{$id}' has an invalid definition.");
            }
            $services[$id] = ServiceDefinition::fromArray($id, $definition, $name);
        }
        $aliases = [];
        foreach ($aliasesRaw as $alias => $target) {
            if (!is_string($alias) || !is_string($target)) {
                throw new \InvalidArgumentException("Module '{$name}' contains an invalid alias definition.");
            }
            $aliases[$alias] = $target;
        }
        $routes = [];
        foreach ($routesRaw as $route) {
            $routes[] = $route instanceof RouteDefinition
                ? $route
                : RouteDefinition::fromArray($route);
        }
        $dependencies = [];
        foreach ($dependenciesRaw as $dependency) {
            if (!is_string($dependency)) {
                throw new \InvalidArgumentException("Module '{$name}' dependencies must contain strings.");
            }
            $dependencies[] = $dependency;
        }
        $extensions = $config;
        unset(
            $extensions['services'],
            $extensions['aliases'],
            $extensions['routes'],
            $extensions['dependencies'],
            $extensions['requires'],
        );

        return new self($name, $services, $aliases, $routes, $extensions, $dependencies);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge(
            [
                'services' => $this->services,
                'aliases' => $this->aliases,
                'routes' => $this->routes,
                'dependencies' => $this->dependencies,
            ],
            $this->extensions,
        );
    }

    /**
     * @param array<array-key,mixed> $services
     *
     * @return array<string,ServiceDefinition>
     */
    private static function validatedServices(array $services): array
    {
        foreach ($services as $id => $definition) {
            if (!is_string($id) || $id === '' || !$definition instanceof ServiceDefinition) {
                throw new \InvalidArgumentException(
                    'Module services must map non-empty IDs to ServiceDefinition instances.',
                );
            }
            if ($definition->id !== $id) {
                throw new \InvalidArgumentException(
                    "Service definition ID '{$definition->id}' does not match registry key '{$id}'.",
                );
            }
        }

        return $services;
    }

    /**
     * @param array<array-key,mixed> $aliases
     *
     * @return array<string,string>
     */
    private static function validatedAliases(array $aliases): array
    {
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                throw new \InvalidArgumentException('Module aliases must map non-empty strings to non-empty strings.');
            }
        }

        return $aliases;
    }

    /**
     * @param array<mixed> $routes
     *
     * @return list<RouteDefinition>
     */
    private static function validatedRoutes(array $routes): array
    {
        foreach ($routes as $route) {
            if (!$route instanceof RouteDefinition) {
                throw new \InvalidArgumentException('Module routes must contain RouteDefinition instances.');
            }
        }

        return array_values($routes);
    }

    /**
     * @param array<int,string> $dependencies
     *
     * @return list<string>
     */
    private static function normalizedDependencies(array $dependencies): array
    {
        $normalized = [];
        foreach ($dependencies as $dependency) {
            Identifier::assertModuleName($dependency, 'module dependency');
            $normalized[] = strtolower($dependency);
        }

        return array_values(array_unique($normalized));
    }
}
