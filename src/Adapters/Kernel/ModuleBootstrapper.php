<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Router\Router;

final readonly class ModuleBootstrapper implements Config\ModuleRegistrar
{
    public function __construct(
        private Container $container,
        private Router $router,
    ) {}

    /** @param array<string,mixed>|Config\ModuleDefinition $config */
    #[\Override]
    public function registerModule(string $module, array|Config\ModuleDefinition $config): void
    {
        try {
            $definition = $config instanceof Config\ModuleDefinition
                ? $config
                : Config\ModuleDefinition::fromArray($module, $config);
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Module '{$module}' has invalid definition: {$e->getMessage()}",
                0,
                $e,
            );
        }
        if (strtolower($definition->name) !== strtolower($module)) {
            throw new InvalidConfigurationException(
                "Module definition name '{$definition->name}' does not match '{$module}'.",
            );
        }
        foreach ($definition->services as $serviceDefinition) {
            if ($serviceDefinition->module !== $module) {
                $serviceDefinition = new ServiceDefinition(
                    $serviceDefinition->id,
                    $serviceDefinition->factory,
                    $serviceDefinition->dependencies,
                    $module,
                    $serviceDefinition->lifetime,
                    $serviceDefinition->shared,
                    $serviceDefinition->lazy,
                    $serviceDefinition->tags,
                );
            }
            $this->container->registerDefinition($serviceDefinition);
        }
        foreach ($definition->aliases as $alias => $target) {
            $this->container->alias($alias, $target, $module);
        }
        foreach ($definition->routes as $route) {
            $this->router->add($route->method, $route->path, $route->handler, $module, $route->priority, $route->name);
        }
    }
}
