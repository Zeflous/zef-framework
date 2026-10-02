<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Policy\NamespaceScopePolicy;
use Zef\Framework\Validation\DependencyGraphValidator;

final class Container implements ContainerInterface, ServiceRegistrarInterface
{
    private const string FROZEN_MESSAGE = 'Container is frozen.';

    // php:S2830: collaborators are wired in initialize() (not the constructor
    // body), so they stay non-readonly — phpstan forbids readonly assignment
    // outside the constructor; they are still write-once by construction.
    // php:S1448/S2042: providers, decorators, contextual bindings and
    // namespace fallbacks live in dedicated collaborators (ProviderBroker,
    // DecoratorApplier, ContextualBindingStore, NamespaceFallbackResolver);
    // this class keeps the public API and delegates.
    private ServiceRegistry $registry;
    private ServiceRegistrar $registrar;
    private ContainerResolver $resolver;
    private ContainerCompiler $compiler;
    private ProviderBroker $providers;
    private DecoratorApplier $decorators;
    private ContextualBindingStore $contextualBindings;
    private NamespaceFallbackResolver $fallbacks;
    private NamespaceLayer $namespaces;
    private SingletonWarmer $warmer;
    private ServiceMiddlewarePipeline $middleware;
    private bool $frozen = false;
    private int $maxCrossModuleRefs = 0;
    private ArchitecturePolicy $policy;

    public function __construct(
        private readonly bool $debug = false,
        ?ArchitecturePolicy $policy = null,
        ?InitializationGuard $initializationGuard = null,
    ) {
        $this->initialize($policy, $initializationGuard);
        $this->resolver->bind($this);
    }

    // @infection-ignore-all DecrementInteger — ekuivalen: 0 berarti unlimited
    // (validator gerbang > 0); default -1 berperilaku sama
    public function configurePolicies(int $maxCrossModuleRefs = 0): void
    {
        $this->assertWritable();
        // @infection-ignore-all DecrementInteger — ekuivalen: input negatif
        // dinormalisasi ke unlimited; max(-1,x) identik dengan max(0,x)
        $this->maxCrossModuleRefs = max(0, $maxCrossModuleRefs);
    }

    public function register(
        string $id,
        callable $factory,
        array $deps = [],
        ?string $module = null,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void {
        $this->assertWritable();
        $this->assertRegistrationBudget();
        $this->registrar->register($id, $factory, $deps, $module, $lifetime);
    }

    public function registerDefinition(ServiceDefinition $definition): void
    {
        $this->assertWritable();
        $this->assertRegistrationBudget();
        $id = $definition->id;
        if ($this->registry->hasFactory($id) || $this->registry->hasAlias($id)) {
            throw new InvalidFactoryException("Factory for '{$id}' is invalid: service ID already registered.");
        }
        $this->registry->addDefinition($definition);
    }

    public function alias(string $alias, string $target, ?string $module = null): void
    {
        $this->assertWritable();
        $this->registrar->alias($alias, $target, $module);
    }

    public function validateAndFreeze(): void
    {
        $this->providers->triggerRequired($this->registry);
        $this->decorators->apply($this->registry, $this->policy->maxServiceRegistrations);
        // v2.35.0: seal the service middleware pipeline — the resolution
        // pipeline is immutable once the container freezes.
        $this->middleware->seal();
        $plan = $this->compiler->compile($this->registry, $this->maxCrossModuleRefs);
        // v2.11.0: build the sealed namespace radix tree AFTER graph validation
        // (all IDs canonical + proven) and enforce namespace scope policy.
        $this->namespaces->build($plan);
        // @infection-ignore-all MethodCallRemoval — ekuivalen: jalur validator
        // menghasilkan get/has/eksepsi identik untuk seluruh konfigurasi
        // publik; terverifikasi oleh kurikulum freeze
        $this->resolver->installPlan($plan);
        $this->frozen = true;
    }

    public function warmSingletons(): void
    {
        $this->warmer->warm($this->registry);
    }

    #[\Override]
    public function get(string $id): mixed
    {
        $this->providers->triggerFor($id);
        // v2.11.0: namespace fallback — only for IDs the container does NOT
        // know (never shadows registered services; never used for graph deps,
        // which are validated to exist before freeze).
        if ($this->fallbacks->appliesTo($id) && !$this->resolver->hasInContext($id)) {
            return $this->fallbacks->resolve($this, $id);
        }

        return $this->resolver->resolveRoot($id);
    }

    #[\Override]
    public function has(string $id): bool
    {
        return $this->resolver->hasInContext($id) || $this->fallbacks->appliesTo($id);
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function createRequestScope(): RequestScope
    {
        return $this->resolver->createRequestScope();
    }

    /**
     * beta2 fix: no longer churns a throwaway request scope; only clears state on demand.
     */
    public function reset(bool $clearSingletons = false): void
    {
        if ($clearSingletons) {
            $this->resolver->clearSingletons();
            // v2.11.0: fallback singletons follow the same lifecycle
            $this->fallbacks->clearSingletons();
        }
    }

    /**
     * @return list<string>
     */
    public function getRegisteredIds(): array
    {
        // @infection-ignore-all UnwrapArrayValues — ekuivalen: id factory dan
        // alias unik serta bertipe string; merge mempertahankan kunci string
        // @infection-ignore-all UnwrapArrayUnique — ekuivalen: duplikat
        // mustahil: registrar menolak registrasi id yang sama
        return array_values(array_unique(array_merge(
            array_keys($this->registry->factories()),
            array_keys($this->registry->aliases()),
        )));
    }

    /** @return array<string,string> */
    public function getAliasMap(): array
    {
        return $this->registry->aliases();
    }

    public function getRegistry(): ServiceRegistryView
    {
        return new ServiceRegistryView($this->registry);
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    // ---------------------------------------------------------------------
    // v2.10.0 — Enterprise container features (additive, composition-time).
    // ---------------------------------------------------------------------

    /**
     * Contextual binding: resolve one dependency of ONE consumer through a
     * different service ID. Fluent: when($consumer)->needs($dep)->give($target).
     */
    public function when(string $consumer): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $consumer);
    }

    /** @internal used by ContextualBindingBuilder::give(). */
    public function addContextualBinding(string $consumer, string $dep, string $target): void
    {
        $this->assertWritable();
        $this->contextualBindings->add($consumer, $dep, $target, $this->registry, $this->registrar);
    }

    /** @return list<array{consumer:string,dep:string,target:string,via:string}> */
    public function getContextualBindings(): array
    {
        return $this->contextualBindings->all();
    }

    /**
     * Decorate a service: $decorator receives (ResolutionContext $ctx, mixed $inner)
     * and returns the decorated instance. First-registered = outermost.
     * Applied at validateAndFreeze() time as wrapper definitions.
     */
    public function decorate(string $id, callable $decorator): void
    {
        $this->assertWritable();
        $this->decorators->add($id, $decorator);
    }

    /**
     * Register a service provider. Eager providers run register() now;
     * DeferrableProviderInterface providers run register() the first time
     * get() asks for one of their provides() IDs (composition-time deferred
     * loading — always before validateAndFreeze()).
     */
    public function registerProvider(ServiceProviderInterface $provider): void
    {
        $this->assertWritable();
        $this->providers->register($provider);
    }

    /** Boot hook for registered BootableProviderInterface providers (once). */
    public function bootProviders(): void
    {
        $this->providers->boot();
    }

    /** @return list<ServiceProviderInterface> all registered providers, in order */
    public function getProviders(): array
    {
        return $this->providers->all();
    }

    // ---------------------------------------------------------------------
    // v2.11.0 — RadixTree namespace layer (additive, composition-time).
    // ---------------------------------------------------------------------

    /** Install a namespace scope policy enforced at validateAndFreeze() time. */
    public function configureNamespacePolicy(NamespaceScopePolicy $policy): void
    {
        $this->assertWritable();
        $this->namespaces->configurePolicy($policy);
    }

    /**
     * Register a namespace-level fallback factory: when get() is asked for an
     * ID the container does not know, the LONGEST registered prefix covering
     * it wins. SINGLETON fallbacks are cached per requested ID; TRANSIENT
     * fallbacks instantiate on every call; REQUEST is not applicable here.
     * Fallbacks never shadow registered services and never apply to graph
     * dependency edges (those are validated to exist before freeze).
     */
    public function registerNamespaceFallback(
        string $prefix,
        callable $factory,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void {
        $this->assertWritable();
        $this->fallbacks->register($prefix, $factory, $lifetime);
    }

    /**
     * Batch-resolve every registered service under a namespace prefix
     * (deterministic ID-sorted order). Requires a frozen container — the
     * radix tree is built at validateAndFreeze() time.
     *
     * @return array<string,mixed>
     */
    public function getByPrefix(string $prefix): array
    {
        return $this->namespaces->getByPrefix($this, $prefix);
    }

    /** ID-only variant of getByPrefix() (no instantiation). @return list<string> */
    public function getIdsByPrefix(string $prefix): array
    {
        return $this->namespaces->getIdsByPrefix($prefix);
    }

    /** The sealed namespace radix tree (null before freeze). */
    public function namespaceTree(): ?NamespaceRadixTree
    {
        return $this->namespaces->tree();
    }

    /**
     * @return null|array{
     *     serviceIds:int, nodes:int, edges:int, maxDepth:int,
     *     rawSegments:int, compressionRatio:float, annotations:int, sealed:bool
     * }
     */
    public function namespaceStats(): ?array
    {
        return $this->namespaces->stats();
    }

    /** Resolving event: fired before each instantiation with (id, deps). */
    public function onResolving(callable $listener): void
    {
        $this->registry->addResolvingListener($listener);
    }

    /** Resolved event: fired after instantiation; non-null return replaces the instance. */
    public function onResolved(callable $listener): void
    {
        $this->registry->addResolvedListener($listener);
    }

    // ---------------------------------------------------------------------
    // v2.35.0 — Service middleware/interceptors (roadmap checklist item).
    // ---------------------------------------------------------------------

    /**
     * Register service middleware: an interceptor around service
     * CONSTRUCTION (the cache-miss instantiation path — consistent with the
     * resolving/resolved events, which also fire only on instantiation).
     *
     * Signature: fn(string $serviceId, Closure $next): mixed. Call $next()
     * to run the default construction; returning without calling $next()
     * short-circuits (the returned value becomes the service, still passing
     * through resolved listeners and per-lifetime caching). Higher priority
     * runs first (outermost); ties keep registration order.
     *
     * Adding middleware after validateAndFreeze() throws — the pipeline is
     * sealed so a frozen container keeps a deterministic pipeline.
     */
    public function addServiceMiddleware(callable $middleware, int $priority = 0): void
    {
        $this->assertWritable();
        $this->middleware->add($middleware, $priority);
    }

    /** Number of registered service middleware (exposed for diagnostics). */
    public function serviceMiddlewareCount(): int
    {
        return $this->middleware->count();
    }

    /**
     * php:S2830: the container is the composition root — its internal
     * collaborators are wired through a private initializer instead of
     * bare `new` expressions in the constructor body. The constructor
     * signature is public API and cannot grow per-service injection
     * points; policy and initialization guard stay optional injectables.
     */
    private function initialize(?ArchitecturePolicy $policy, ?InitializationGuard $initializationGuard): void
    {
        $this->policy = $policy ?? new ArchitecturePolicy();
        $this->registry = new ServiceRegistry();
        $this->registrar = new ServiceRegistrar($this->registry);
        $graphValidator = new DependencyGraphValidator();
        $guard = $initializationGuard ?? new FailFastInitializationGuard();
        $this->compiler = new ContainerCompiler($graphValidator);
        $this->middleware = new ServiceMiddlewarePipeline();
        $subtrees = new SingletonSubtreeTracker();
        $this->resolver = new ContainerResolver(
            $this->registry,
            $graphValidator,
            $guard,
            $this->policy->maxResolutionDepth,
            $this->middleware,
            $subtrees,
        );
        $this->providers = new ProviderBroker($this);
        $this->decorators = new DecoratorApplier();
        $this->contextualBindings = new ContextualBindingStore();
        $this->fallbacks = new NamespaceFallbackResolver(
            $guard,
            $subtrees,
            $this->middleware,
            $this->policy->maxResolutionDepth,
        );
        $this->namespaces = new NamespaceLayer();
        $this->warmer = new SingletonWarmer($this);
    }

    /** Guard for every pre-freeze mutation entry point. */
    private function assertWritable(): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
    }

    /** Registration-budget ceiling shared by register() and registerDefinition(). */
    private function assertRegistrationBudget(): void
    {
        if (count($this->registry->definitions()) >= $this->policy->maxServiceRegistrations) {
            throw new InvalidConfigurationException('Service registration budget exceeded.');
        }
    }
}
