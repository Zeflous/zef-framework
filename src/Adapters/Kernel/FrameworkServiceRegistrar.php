<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: default service wiring.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; registration order unchanged).
 */

namespace Zef\Framework\Kernel;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\SystemCacheClock;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\CQRS\QueryBusInterface;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

/**
 * @internal
 *
 * Default framework service registrations of the kernel composition root
 * (extracted from {@see \Zef\Framework\Application}).
 *
 * Pure container wiring: no state, no construction of the application graph
 * itself — every service is registered behind its factory closure, so the
 * registrations are lazy and overridable by applications re-registering the
 * same service ids.
 */
final class FrameworkServiceRegistrar
{
    /**
     * Registers the default framework services in the historical order:
     * core (logger, env), observability, events, CQRS buses, cache.
     */
    public static function registerDefaults(Container $container, ?LoggerInterface $logger): void
    {
        self::registerCoreServices($container, $logger);
        ObservabilityServiceRegistrar::register($container, $logger);
        self::registerEventServices($container);
        self::registerCqrsServices($container);
        self::registerCacheServices($container);
    }

    private static function registerCoreServices(Container $container, ?LoggerInterface $logger): void
    {
        // Bug fix #18: LoggerInterface registered BEFORE TelemetryLogger.
        $container->register(
            LoggerInterface::class,
            static fn (): LoggerInterface => $logger ?? new NullLogger(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        // Issue #55: the environment read surface is an ordinary container
        // service, next to ServiceRegistrarInterface and
        // OtlpExporterFactoryInterface. New production code depends on the
        // port; the historic static facade (Env::int/bool/string/csv) is
        // @deprecated since v2.28.0 and scheduled for removal in v3.0 —
        // the src/ migration completed with zero static call sites left.
        $container->register(
            EnvInterface::class,
            static fn (): EnvInterface => new Env(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
    }

    private static function registerEventServices(Container $container): void
    {
        $container->register(
            EventDispatcher::class,
            static fn (): EventDispatcher => new EventDispatcher(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->alias(
            EventBusInterface::class,
            EventDispatcher::class,
            'framework',
        );
    }

    private static function registerCqrsServices(Container $container): void
    {
        $container->register(
            InMemoryIdempotencyStore::class,
            static fn (): InMemoryIdempotencyStore => new InMemoryIdempotencyStore(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            CommandBus::class,
            static function (ContainerInterface $c): CommandBus {
                /** @var InMemoryIdempotencyStore $store */
                $store = $c->get(InMemoryIdempotencyStore::class);

                /** @var EventBusInterface $eventBus */
                $eventBus = $c->get(EventBusInterface::class);

                return new CommandBus($store, 3600, $eventBus);
            },
            [
                InMemoryIdempotencyStore::class,
                EventBusInterface::class,
            ],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->alias(
            CommandBusInterface::class,
            CommandBus::class,
            'framework',
        );
        $container->register(
            QueryBus::class,
            static fn (): QueryBus => new QueryBus(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->alias(
            QueryBusInterface::class,
            QueryBus::class,
            'framework',
        );
    }

    private static function registerCacheServices(Container $container): void
    {
        $container->register(
            SystemCacheClock::class,
            static fn (): SystemCacheClock => new SystemCacheClock(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->alias(
            CacheClockInterface::class,
            SystemCacheClock::class,
            'framework',
        );
        $container->register(
            InMemoryCacheStore::class,
            static function (ContainerInterface $c): InMemoryCacheStore {
                /** @var CacheClockInterface $clock */
                $clock = $c->get(CacheClockInterface::class);

                return new InMemoryCacheStore(10000, $clock);
            },
            [CacheClockInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            InMemoryCache::class,
            static function (ContainerInterface $c): InMemoryCache {
                /** @var InMemoryCacheStore $store */
                $store = $c->get(InMemoryCacheStore::class);

                return new InMemoryCache($store);
            },
            [InMemoryCacheStore::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->alias(
            CacheInterface::class,
            InMemoryCache::class,
            'framework',
        );
    }
}
