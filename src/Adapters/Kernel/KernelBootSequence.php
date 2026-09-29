<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: boot sequence.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; no public API change).
 */

namespace Zef\Framework\Kernel;

use Psr\Container\ContainerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\QueryBusInterface;
use Zef\Framework\Dispatcher;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\ModuleBootstrapper;
use Zef\Framework\PipelineFactory;
use Zef\Framework\Router\Router;

/**
 * @internal
 *
 * The {@see Application::boot()} sequence, extracted verbatim: provider
 * merge, eager configuration build, framework service registration, bus
 * freezing and module start-up. Returns the built middleware pipeline;
 * Application marks itself booted only afterwards.
 */
final class KernelBootSequence
{
    public static function run(
        ConfigAggregator $config,
        Container $container,
        Router $router,
        ModuleBootstrapper $bootstrapper,
        ModuleRegistry $modules,
        ApplicationConfigState $configState,
        Dispatcher $dispatcher,
    ): MiddlewarePipeline {
        foreach ($modules->providers() as $p) {
            $config->addProvider($p);
        }
        $config->merge();
        $maxRefs = (int) $config->get('framework.container.max_cross_module_refs', 0);
        $container->configurePolicies($maxRefs);
        // v2.21.0: build + validate the application configuration eagerly —
        // a schema violation fails the boot before any module registers.
        $container->register(
            Config::class,
            fn (): Config => $configState->config(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $configState->config();
        // v2.8.0: tagged service locator — read side for ServiceDefinition tags.
        $container->register(
            TaggedServiceLocator::class,
            fn (ContainerInterface $c): TaggedServiceLocator => new TaggedServiceLocator(
                $c,
                $container->getRegistry(),
            ),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );

        /** @var EventDispatcher $eventBus */
        $eventBus = $container->get(EventDispatcher::class);

        /** @var CommandBusInterface $commandBus */
        $commandBus = $container->get(CommandBusInterface::class);
        $modules->registerAll($bootstrapper, $container);
        $eventBus->freeze();
        $commandBus->freeze();

        /** @var QueryBusInterface $queryBus */
        $queryBus = $container->get(QueryBusInterface::class);
        $queryBus->freeze();
        $container->validateAndFreeze();
        $router->freeze();
        $container->warmSingletons();
        $modules->bootAll($container);
        $modules->startAll($container);

        return new PipelineFactory($container, $config, $dispatcher)->build();
    }
}
