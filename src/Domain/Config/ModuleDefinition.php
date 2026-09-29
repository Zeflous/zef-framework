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
        $this->services = $this->validatedServices($services);
        $this->aliases = $this->validatedAliases($aliases);
        $this->routes = $this->validatedRoutes($routes);
        $this->dependencies = $this->normalizedDependencies($dependencies);
    }

    public static function fromArray(string $name, array $config): self
    {
        $servicesRaw = self::arraySection($config, 'services', $name);
        $aliasesRaw = self::arraySection($config, 'aliases', $name);
        $routesRaw = self::arraySection($config, 'routes', $name);
        $dependenciesRaw = self::arraySection($config, 'dependencies', $name, 'requires');

        return new self(
            $name,
            self::servicesFromRaw($name, $servicesRaw),
            self::aliasesFromRaw($name, $aliasesRaw),
            self::routesFromRaw($routesRaw),
            self::extensionsFrom($config),
            self::dependenciesFromRaw($name, $dependenciesRaw),
        );
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
     * Fetch a module section, throwing the section-specific guard error when
     * the raw value is not an array. `dependencies` additionally falls back
     * to the legacy `requires` key.
     *
     * @param array<mixed,mixed> $config
     *
     * @return array<mixed,mixed>
     */
    private static function arraySection(array $config, string $key, string $name, ?string $fallbackKey = null): array
    {
        $raw = $config[$key] ?? ($fallbackKey !== null ? ($config[$fallbackKey] ?? []) : []);
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("Module '{$name}' {$key} must be an array.");
        }

        return $raw;
    }

    /**
     * @param array<mixed,mixed> $servicesRaw
     *
     * @return array<string,ServiceDefinition>
     */
    private static function servicesFromRaw(string $name, array $servicesRaw): array
    {
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

        return $services;
    }

    /**
     * @param array<mixed,mixed> $aliasesRaw
     *
     * @return array<string,string>
     */
    private static function aliasesFromRaw(string $name, array $aliasesRaw): array
    {
        $aliases = [];
        foreach ($aliasesRaw as $alias => $target) {
            if (!is_string($alias) || !is_string($target)) {
                throw new \InvalidArgumentException("Module '{$name}' contains an invalid alias definition.");
            }
            $aliases[$alias] = $target;
        }

        return $aliases;
    }

    /**
     * @param array<mixed,mixed> $routesRaw
     *
     * @return list<RouteDefinition>
     */
    private static function routesFromRaw(array $routesRaw): array
    {
        $routes = [];
        foreach ($routesRaw as $route) {
            $routes[] = $route instanceof RouteDefinition
                ? $route
                : RouteDefinition::fromArray($route);
        }

        return $routes;
    }

    /**
     * @param array<mixed,mixed> $dependenciesRaw
     *
     * @return list<string>
     */
    private static function dependenciesFromRaw(string $name, array $dependenciesRaw): array
    {
        $dependencies = [];
        foreach ($dependenciesRaw as $dependency) {
            if (!is_string($dependency)) {
                throw new \InvalidArgumentException("Module '{$name}' dependencies must contain strings.");
            }
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Everything left in the config after the five known module keys is the
     * module's extension payload.
     *
     * @param array<mixed,mixed> $config
     *
     * @return array<mixed,mixed>
     */
    private static function extensionsFrom(array $config): array
    {
        $extensions = $config;
        unset(
            $extensions['services'],
            $extensions['aliases'],
            $extensions['routes'],
            $extensions['dependencies'],
            $extensions['requires'],
        );

        return $extensions;
    }

    /**
     * @param array<array-key,mixed> $services
     *
     * @return array<string,ServiceDefinition>
     */
    private function validatedServices(array $services): array
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
    private function validatedAliases(array $aliases): array
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
    private function validatedRoutes(array $routes): array
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
    private function normalizedDependencies(array $dependencies): array
    {
        $normalized = [];
        foreach ($dependencies as $dependency) {
            Identifier::assertModuleName($dependency, 'module dependency');
            $normalized[] = strtolower($dependency);
        }

        return array_values(array_unique($normalized));
    }
}
