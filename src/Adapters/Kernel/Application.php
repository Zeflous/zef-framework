<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigMetricsInterface;
use Zef\Framework\Config\ConfigMigrator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSourceInterface;
use Zef\Framework\Config\MeterConfigMetrics;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Config\SecretsProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\InitializationGuard;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Router\Router;

final class Application
{
    private readonly ConfigAggregator $config;
    private readonly Container $container;
    private readonly Router $router;
    private readonly ModuleBootstrapper $bootstrapper;
    private readonly ModuleRegistry $modules;
    private readonly Dispatcher $dispatcher;
    private readonly ResponseEmitter $emitter;
    private ?MiddlewarePipeline $pipeline = null;
    private bool $booted = false;
    private bool $shutdown = false;

    /**
     * @var list<string>
     */
    private array $trustedHosts = [];

    /**
     * @var list<string>
     */
    private array $trustedProxies = [];

    /**
     * @var list<ConfigSourceInterface>
     */
    private array $configSources = [];
    private ?SecretsProviderInterface $secretsProvider = null;
    private ?ConfigSchema $configSchema = null;
    private ?ConfigMigrator $configMigrator = null;
    private ?int $configSourceSchemaVersion = null;
    private ?Config $appConfig = null;
    private readonly Http\RequestBodyPolicy $bodyPolicy;
    private readonly Policy\ArchitecturePolicy $architecturePolicy;

    public function __construct(
        private readonly bool $debug = false,
        ?LoggerInterface $logger = null,
        ?Http\RequestBodyPolicy $bodyPolicy = null,
        ?Policy\ArchitecturePolicy $architecturePolicy = null,
        ?InitializationGuard $initializationGuard = null,
    ) {
        $this->config = new ConfigAggregator();
        $this->architecturePolicy = $architecturePolicy ?? new Policy\ArchitecturePolicy();
        $this->container = new Container($debug, $this->architecturePolicy, $initializationGuard);
        $this->router = new Router(
            new Validation\RouteConstraintValidator(),
            $this->architecturePolicy,
        );
        $this->bootstrapper = new ModuleBootstrapper($this->container, $this->router);
        $this->modules = new ModuleRegistry();
        $this->dispatcher = new Dispatcher($this->router, $this->container);
        $this->emitter = new ResponseEmitter();
        $this->bodyPolicy = $bodyPolicy ?? new Http\RequestBodyPolicy();

        // Bug fix #18: LoggerInterface registered BEFORE TelemetryLogger.
        $this->container->register(
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
        $this->container->register(
            Foundation\EnvInterface::class,
            static fn (): Foundation\EnvInterface => new Foundation\Env(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->registerObservabilityServices($logger);
        $this->registerEventServices();
        $this->registerCqrsServices();
        $this->registerCacheServices();
    }

    public function addProvider(ConfigProviderInterface $provider): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot add provider after boot.');
        }
        $this->modules->addProvider($provider);
    }

    public function addModule(ModuleInterface $module): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot add module after boot.');
        }
        $this->modules->add($module);
    }

    // v2.21.0 — Configuration System v2: multi-source application settings.

    public function registerConfigSource(ConfigSourceInterface $source): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot add config source after boot.');
        }
        $this->configSources[] = $source;
    }

    public function registerSecretsProvider(SecretsProviderInterface $secrets): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot register a secrets provider after boot.');
        }
        $this->secretsProvider = $secrets;
    }

    public function setConfigSchema(ConfigSchema $schema): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot set the config schema after boot.');
        }
        $this->configSchema = $schema;
    }

    /**
     * Bind the ordered schema-version migration steps (v2.23.0, issue #60
     * P4) used when the incoming configuration data carries an older
     * schema version than {@see setConfigSchema()}'s target.
     */
    public function setConfigMigrator(ConfigMigrator $migrator, ?int $sourceSchemaVersion = null): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot set the config migrator after boot.');
        }
        $this->configMigrator = $migrator;
        $this->configSourceSchemaVersion = $sourceSchemaVersion;
    }

    /**
     * The validated, immutable application configuration bag (also available
     * as the `Config::class` container singleton after boot).
     */
    public function config(): Config
    {
        $this->appConfig ??= new ConfigLoader(
            $this->configSources,
            $this->secretsProvider,
            $this->configSchema,
            $this->configMigrator,
            $this->configSourceSchemaVersion,
        )->load();

        return $this->appConfig;
    }

    public function setTrustedHosts(array $hosts): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot change trusted hosts after boot.');
        }
        $this->trustedHosts = array_values(
            array_filter(array_map(strval(...), $hosts), static fn (string $v): bool => $v !== '')
        );
    }

    public function setTrustedProxies(array $proxies): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot change trusted proxies after boot.');
        }
        $this->trustedProxies = array_values(
            array_filter(array_map(strval(...), $proxies), static fn (string $v): bool => $v !== '')
        );
    }

    public function setMaxCrossModuleRefs(int $limit): void
    {
        $this->container->configurePolicies($limit);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        foreach ($this->modules->providers() as $p) {
            $this->config->addProvider($p);
        }
        $this->config->merge();
        $maxRefs = (int) $this->config->get('framework.container.max_cross_module_refs', 0);
        $this->container->configurePolicies($maxRefs);
        // v2.21.0: build + validate the application configuration eagerly —
        // a schema violation fails the boot before any module registers.
        $this->container->register(
            Config::class,
            fn (): Config => $this->config(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->config();
        // v2.8.0: tagged service locator — read side for ServiceDefinition tags.
        $this->container->register(
            TaggedServiceLocator::class,
            fn (ContainerInterface $c): TaggedServiceLocator => new TaggedServiceLocator(
                $c,
                $this->container->getRegistry(),
            ),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );

        /** @var Event\EventDispatcher $eventBus */
        $eventBus = $this->container->get(Event\EventDispatcher::class);

        /** @var CQRS\CommandBusInterface $commandBus */
        $commandBus = $this->container->get(CQRS\CommandBusInterface::class);
        $this->modules->registerAll($this->bootstrapper, $this->container);
        $eventBus->freeze();
        $commandBus->freeze();

        /** @var CQRS\QueryBusInterface $queryBus */
        $queryBus = $this->container->get(CQRS\QueryBusInterface::class);
        $queryBus->freeze();
        $this->container->validateAndFreeze();
        $this->router->freeze();
        $this->container->warmSingletons();
        $this->modules->bootAll($this->container);
        $this->modules->startAll($this->container);
        $this->pipeline = new PipelineFactory($this->container, $this->config, $this->dispatcher)->build();
        $this->booted = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->shutdown) {
            throw new \LogicException('Application has already been shut down.');
        }
        if (!$this->booted) {
            $this->boot();
        }

        try {
            $request = RequestFactory::validateIngress($request, $this->trustedHosts, $this->trustedProxies, $this->bodyPolicy);
        } catch (Exception\PayloadTooLargeException) {
            return JsonResponse::error(413, 'Content Too Large');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error(400, 'Bad Request', [
                'message' => $this->debug ? $e->getMessage() : 'Invalid request.',
            ]);
        }
        $scope = $this->container->createRequestScope();

        /** @var Telemetry $telemetry */
        $telemetry = $this->container->get(Telemetry::class);
        $parent = $telemetry->extract(
            $request->getHeaderLine('traceparent'),
            $request->getHeaderLine('tracestate'),
        );
        $span = $telemetry->startSpan(
            'zef.http.request',
            [
                'http.request.method' => $request->getMethod(),
                'url.path' => $request->getUri()->getPath(),
                'server.address' => $request->getUri()->getHost(),
            ],
            $parent,
        );
        $request = $request
            ->withAttribute('__zef_request_scope', $scope)
            ->withAttribute('__zef_telemetry_span', $span)
            ->withAttribute('__zef_trusted_proxies', $this->trustedProxies)
        ;
        $traceId = $span->getContext()->isValid() ? $span->getContext()->traceId : '';
        $startNs = hrtime(true);
        $this->recordLifecycle($telemetry, 'request.started', $traceId);

        try {
            $response = $this->pipeline?->handle($request)
                ?? new Response(500, ['Content-Type' => 'text/plain'], 'Application pipeline unavailable.');
            if (strtoupper($request->getMethod()) === 'HEAD') {
                $response = $response->withBody(Stream::fromString(''));
            }
            $elapsed = (hrtime(true) - $startNs) / 1_000_000_000;
            $span
                ->setAttribute('http.response.status_code', $response->getStatusCode())
                ->setAttribute('zef.request.duration_seconds', $elapsed)
                ->setStatus($response->getStatusCode() >= 500 ? 'ERROR' : 'OK')
            ;
            $telemetry->meter()->increment(
                'zef.http.requests.total',
                1,
                [
                    'http.request.method' => $request->getMethod(),
                    'http.response.status_code' => $response->getStatusCode(),
                ],
            );
            $telemetry->meter()->observe(
                'zef.http.request.duration_seconds',
                $elapsed,
                ['http.request.method' => $request->getMethod()],
            );
            $this->recordLifecycle($telemetry, 'request.completed', $traceId);

            return $telemetry->isEnabled()
                ? $response->withHeader('traceparent', $span->getContext()->traceParent())
                : $response;
        } catch (\Throwable $e) {
            $span->setStatus('ERROR', $e::class);
            $span->addEvent('exception', [
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
            ]);
            $telemetry->meter()->increment(
                'zef.http.errors.total',
                1,
                [
                    'http.request.method' => $request->getMethod(),
                    'exception.type' => $e::class,
                ],
            );
            $this->recordLifecycle($telemetry, 'request.failed', $traceId);

            throw $e;
        } finally {
            $span->end();
            // Issue #55 step 3: the flush-per-request knob reads through the
            // container-bound EnvInterface port (singleton — the per-request
            // get() is a plan lookup, not a construction).
            $envPort = $this->container->get(Foundation\EnvInterface::class);
            if ($envPort instanceof Foundation\EnvInterface && $envPort->readBool('ZEF_OTEL_FLUSH_PER_REQUEST')) {
                $telemetry->flush();
            }
            $scope->close();
        }
    }

    public function shutdown(): void
    {
        if (!$this->booted || $this->shutdown) {
            return;
        }
        $this->shutdown = true;
        $this->modules->shutdownAll($this->container);

        try {
            $telemetry = $this->container->get(Telemetry::class);
            if ($telemetry instanceof Telemetry) {
                $telemetry->shutdown();
            }
        } catch (\Throwable) {
        }
    }

    public function handleGlobals(): ResponseInterface
    {
        try {
            return $this->handle(
                RequestFactory::fromGlobals($this->trustedHosts, $this->trustedProxies, $this->bodyPolicy)
            );
        } catch (Exception\PayloadTooLargeException) {
            return JsonResponse::error(413, 'Content Too Large');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error(400, 'Bad Request', [
                'message' => $this->debug ? $e->getMessage() : 'Invalid request.',
            ]);
        }
    }

    public function emit(ResponseInterface $response): void
    {
        $this->emitter->emit($response);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getConfigAggregator(): ConfigAggregator
    {
        return $this->config;
    }

    public function getModuleRegistry(): ModuleRegistry
    {
        return $this->modules;
    }

    public function getCommandBus(): CQRS\CommandBusInterface
    {
        // @var CQRS\CommandBusInterface $bus
        return $this->container->get(CQRS\CommandBusInterface::class);
    }

    public function getQueryBus(): CQRS\QueryBusInterface
    {
        // @var CQRS\QueryBusInterface $bus
        return $this->container->get(CQRS\QueryBusInterface::class);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** @return list<string> */
    public function getTrustedHosts(): array
    {
        return $this->trustedHosts;
    }

    /**
     * No-op per-request lifecycle hook for long-running runtimes.
     *
     * RoadRunnerRuntime (and future workers) call this in their per-request
     * finally block so runtimes can rely on a stable application contract
     * without depending on container internals. It intentionally has an
     * empty body: the container's request-scope teardown already runs
     * inside the runtime itself.
     *
     * @internal
     */
    public function runtimeAfterRequest(): void {}

    private function registerObservabilityServices(?LoggerInterface $logger): void
    {
        // Issue #36 exit ramp (OtlpExporter): the default exporter factory is
        // an ordinary container service — the default wiring is a config-level
        // decision applications can override by re-registering the port.
        $this->container->register(
            Observability\OtlpExporterFactoryInterface::class,
            static fn (): Observability\OtlpExporterFactoryInterface => new Observability\OtlpExporterFactory(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            Telemetry::class,
            static function (ContainerInterface $c) use ($logger): Telemetry {
                /** @var Observability\OtlpExporterFactoryInterface $exporterFactory */
                $exporterFactory = $c->get(Observability\OtlpExporterFactoryInterface::class);

                /** @var Foundation\EnvInterface $env */
                $env = $c->get(Foundation\EnvInterface::class);

                return Telemetry::fromEnvironment($logger, true, $exporterFactory, $env);
            },
            [Observability\OtlpExporterFactoryInterface::class, Foundation\EnvInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            Observability\TracerInterface::class,
            static function (ContainerInterface $c): Observability\TracerInterface {
                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return $telemetry->tracer();
            },
            [Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            Observability\MeterInterface::class,
            static function (ContainerInterface $c): Observability\MeterInterface {
                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return $telemetry->meter();
            },
            [Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        // v2.23.0 (issue #60 P1): config observability port over the meter.
        $this->container->register(
            ConfigMetricsInterface::class,
            static function (ContainerInterface $c): ConfigMetricsInterface {
                /** @var Observability\MeterInterface $meter */
                $meter = $c->get(Observability\MeterInterface::class);

                return new MeterConfigMetrics($meter);
            },
            [Observability\MeterInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            Observability\TelemetryLogger::class,
            static function (ContainerInterface $c): Observability\TelemetryLogger {
                /** @var LoggerInterface $logger */
                $logger = $c->get(LoggerInterface::class);

                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return new Observability\TelemetryLogger($logger, $telemetry);
            },
            [LoggerInterface::class, Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
    }

    private function registerEventServices(): void
    {
        $this->container->register(
            Event\EventDispatcher::class,
            static fn (): Event\EventDispatcher => new Event\EventDispatcher(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->alias(
            Event\EventBusInterface::class,
            Event\EventDispatcher::class,
            'framework',
        );
    }

    private function registerCqrsServices(): void
    {
        $this->container->register(
            CQRS\InMemoryIdempotencyStore::class,
            static fn (): CQRS\InMemoryIdempotencyStore => new CQRS\InMemoryIdempotencyStore(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            CQRS\CommandBus::class,
            static function (ContainerInterface $c): CQRS\CommandBus {
                /** @var CQRS\InMemoryIdempotencyStore $store */
                $store = $c->get(CQRS\InMemoryIdempotencyStore::class);

                /** @var Event\EventBusInterface $eventBus */
                $eventBus = $c->get(Event\EventBusInterface::class);

                return new CQRS\CommandBus($store, 3600, $eventBus);
            },
            [
                CQRS\InMemoryIdempotencyStore::class,
                Event\EventBusInterface::class,
            ],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->alias(
            CQRS\CommandBusInterface::class,
            CQRS\CommandBus::class,
            'framework',
        );
        $this->container->register(
            CQRS\QueryBus::class,
            static fn (): CQRS\QueryBus => new CQRS\QueryBus(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->alias(
            CQRS\QueryBusInterface::class,
            CQRS\QueryBus::class,
            'framework',
        );
    }

    private function registerCacheServices(): void
    {
        $this->container->register(
            Cache\SystemCacheClock::class,
            static fn (): Cache\SystemCacheClock => new Cache\SystemCacheClock(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->alias(
            Cache\CacheClockInterface::class,
            Cache\SystemCacheClock::class,
            'framework',
        );
        $this->container->register(
            Cache\InMemoryCacheStore::class,
            static function (ContainerInterface $c): Cache\InMemoryCacheStore {
                /** @var Cache\CacheClockInterface $clock */
                $clock = $c->get(Cache\CacheClockInterface::class);

                return new Cache\InMemoryCacheStore(10000, $clock);
            },
            [Cache\CacheClockInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->register(
            Cache\InMemoryCache::class,
            static function (ContainerInterface $c): Cache\InMemoryCache {
                /** @var Cache\InMemoryCacheStore $store */
                $store = $c->get(Cache\InMemoryCacheStore::class);

                return new Cache\InMemoryCache($store);
            },
            [Cache\InMemoryCacheStore::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $this->container->alias(
            Cache\CacheInterface::class,
            Cache\InMemoryCache::class,
            'framework',
        );
    }

    private function recordLifecycle(Telemetry $telemetry, string $event, string $traceId = ''): void
    {
        $attributes = ['event.name' => $event];
        if ($traceId !== '') {
            $attributes['trace_id'] = $traceId;
        }
        $telemetry->recordLog('INFO', $event, $attributes);
        $telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => $event]);
    }
}
