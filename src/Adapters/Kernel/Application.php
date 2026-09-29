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
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigMigrator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSourceInterface;
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
use Zef\Framework\Kernel\ApplicationConfigState;
use Zef\Framework\Kernel\FrameworkServiceRegistrar;
use Zef\Framework\Kernel\KernelGraphFactory;
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

    private ?ApplicationConfigState $configState = null;
    private readonly Http\RequestBodyPolicy $bodyPolicy;
    private readonly Policy\ArchitecturePolicy $architecturePolicy;

    public function __construct(
        private readonly bool $debug = false,
        ?LoggerInterface $logger = null,
        ?Http\RequestBodyPolicy $bodyPolicy = null,
        ?Policy\ArchitecturePolicy $architecturePolicy = null,
        ?InitializationGuard $initializationGuard = null,
    ) {
        // php:S2830: the graph is assembled through named factories
        // (KernelGraphFactory) in the historical construction order.
        $this->config = KernelGraphFactory::configAggregator();
        $this->architecturePolicy = KernelGraphFactory::architecturePolicy($architecturePolicy);
        $this->container = KernelGraphFactory::container($debug, $this->architecturePolicy, $initializationGuard);
        $this->router = KernelGraphFactory::router($this->architecturePolicy);
        $this->bootstrapper = KernelGraphFactory::moduleBootstrapper($this->container, $this->router);
        $this->modules = KernelGraphFactory::moduleRegistry();
        $this->dispatcher = KernelGraphFactory::dispatcher($this->router, $this->container);
        $this->emitter = KernelGraphFactory::responseEmitter();
        $this->bodyPolicy = KernelGraphFactory::bodyPolicy($bodyPolicy);
        FrameworkServiceRegistrar::registerDefaults($this->container, $logger);
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
        $this->configState()->addSource($source);
    }

    public function registerSecretsProvider(SecretsProviderInterface $secrets): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot register a secrets provider after boot.');
        }
        $this->configState()->setSecretsProvider($secrets);
    }

    public function setConfigSchema(ConfigSchema $schema): void
    {
        if ($this->booted) {
            throw new \LogicException('Cannot set the config schema after boot.');
        }
        $this->configState()->setSchema($schema);
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
        $this->configState()->setMigrator($migrator, $sourceSchemaVersion);
    }

    /**
     * The validated, immutable application configuration bag (also available
     * as the `Config::class` container singleton after boot).
     */
    public function config(): Config
    {
        return $this->configState()->config();
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
            $request = RequestFactory::validateIngress(
                $request,
                $this->trustedHosts,
                $this->trustedProxies,
                $this->bodyPolicy,
            );
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
                // ZEF-DEEP-05: the body is now empty, so the handler's
                // GET-representation Content-Length no longer matches the
                // response object. Drop it to keep the object internally
                // consistent — the SAPI emitter has always reconciled this
                // lying header away; the RoadRunner path forwards verbatim
                // and would otherwise ship a stale framing header.
                $response = $response->withoutHeader('Content-Length');
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
            // Shutdown is best-effort: a telemetry backend that is already
            // failing must not prevent the module shutdown that ran above
            // from completing quietly.
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

    private function configState(): ApplicationConfigState
    {
        // Lazily created (php:S2830): the state holder is a plain value
        // aggregator, nothing observes its construction timing.
        return $this->configState ??= new ApplicationConfigState();
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
