<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: graph assembly.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; construction order unchanged).
 */

namespace Zef\Framework\Kernel;

use Zef\Framework\Application;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\InitializationGuard;
use Zef\Framework\Dispatcher;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\ModuleBootstrapper;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\ResponseEmitter;
use Zef\Framework\Router\Router;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 *
 * Named factories for the kernel object graph (extracted from the
 * {@see Application} constructor so it only wires fields,
 * php:S2830). Each factory is a pure `new` over its arguments — no
 * conditionals, no side effects — so the composition stays trivially
 * auditable; the constructor calls them in the historical order.
 */
final class KernelGraphFactory
{
    public static function configAggregator(): ConfigAggregator
    {
        return new ConfigAggregator();
    }

    public static function architecturePolicy(?ArchitecturePolicy $override): ArchitecturePolicy
    {
        return $override ?? new ArchitecturePolicy();
    }

    public static function container(
        bool $debug,
        ArchitecturePolicy $architecturePolicy,
        ?InitializationGuard $initializationGuard,
    ): Container {
        return new Container($debug, $architecturePolicy, $initializationGuard);
    }

    public static function router(ArchitecturePolicy $architecturePolicy): Router
    {
        return new Router(
            new RouteConstraintValidator(),
            $architecturePolicy,
        );
    }

    public static function moduleBootstrapper(Container $container, Router $router): ModuleBootstrapper
    {
        return new ModuleBootstrapper($container, $router);
    }

    public static function moduleRegistry(): ModuleRegistry
    {
        return new ModuleRegistry();
    }

    public static function dispatcher(Router $router, Container $container): Dispatcher
    {
        return new Dispatcher($router, $container);
    }

    public static function responseEmitter(): ResponseEmitter
    {
        return new ResponseEmitter();
    }

    public static function bodyPolicy(?RequestBodyPolicy $override): RequestBodyPolicy
    {
        return $override ?? new RequestBodyPolicy();
    }
}
