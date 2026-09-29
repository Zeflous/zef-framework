<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

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
use Zef\Framework\Kernel\ApplicationConfigState;
use Zef\Framework\Kernel\FrameworkServiceRegistrar;
use Zef\Framework\Kernel\HttpRequestRunner;
use Zef\Framework\Kernel\KernelBootSequence;
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
    private ?HttpRequestRunner $requestRunner = null;
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
        $this->assertNotBooted('add provider');
        $this->modules->addProvider($provider);
    }

    public function addModule(ModuleInterface $module): void
    {
        $this->assertNotBooted('add module');
        $this->modules->add($module);
    }

    // v2.21.0 — Configuration System v2: multi-source application settings.

    public function registerConfigSource(ConfigSourceInterface $source): void
    {
        $this->assertNotBooted('add config source');
        $this->configState()->addSource($source);
    }

    public function registerSecretsProvider(SecretsProviderInterface $secrets): void
    {
        $this->assertNotBooted('register a secrets provider');
        $this->configState()->setSecretsProvider($secrets);
    }

    public function setConfigSchema(ConfigSchema $schema): void
    {
        $this->assertNotBooted('set the config schema');
        $this->configState()->setSchema($schema);
    }

    /**
     * Bind the ordered schema-version migration steps (v2.23.0, issue #60
     * P4) used when the incoming configuration data carries an older
     * schema version than {@see setConfigSchema()}'s target.
     */
    public function setConfigMigrator(ConfigMigrator $migrator, ?int $sourceSchemaVersion = null): void
    {
        $this->assertNotBooted('set the config migrator');
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
        $this->assertNotBooted('change trusted hosts');
        $this->trustedHosts = array_values(
            array_filter(array_map(strval(...), $hosts), static fn (string $v): bool => $v !== '')
        );
    }

    public function setTrustedProxies(array $proxies): void
    {
        $this->assertNotBooted('change trusted proxies');
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
        $this->requestRunner = new HttpRequestRunner(
            $this->container,
            KernelBootSequence::run(
                $this->config,
                $this->container,
                $this->router,
                $this->bootstrapper,
                $this->modules,
                $this->configState(),
                $this->dispatcher,
            ),
            $this->bodyPolicy,
            $this->debug,
        );
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

        return $this->requestRunner()->run($request, $this->trustedHosts, $this->trustedProxies);
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
        return $this->requestRunner()->runFromGlobals(
            $this->trustedHosts,
            $this->trustedProxies,
            fn (ServerRequestInterface $request): ResponseInterface => $this->handle($request),
        );
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

    /**
     * Pre-boot mutation guard. The message is interpolated so every public
     * setter keeps its historical, byte-identical LogicException text.
     */
    private function assertNotBooted(string $action): void
    {
        if ($this->booted) {
            throw new \LogicException("Cannot {$action} after boot.");
        }
    }

    private function configState(): ApplicationConfigState
    {
        // Lazily created (php:S2830): the state holder is a plain value
        // aggregator, nothing observes its construction timing.
        return $this->configState ??= new ApplicationConfigState();
    }

    private function requestRunner(): HttpRequestRunner
    {
        // Pre-boot stub without a pipeline: boot() installs the real
        // pipeline-backed runner, and a request that somehow reaches the
        // stub reproduces the historic "pipeline unavailable" 500 fallback
        // (unreachable in practice — handle() always boots first).
        return $this->requestRunner
            ?? new HttpRequestRunner($this->container, null, $this->bodyPolicy, $this->debug);
    }
}
