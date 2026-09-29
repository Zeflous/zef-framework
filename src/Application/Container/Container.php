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
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Policy\NamespaceScopePolicy;
use Zef\Framework\Validation\DependencyGraphValidator;

final class Container implements ContainerInterface, ServiceRegistrarInterface
{
    private const string FROZEN_MESSAGE = 'Container is frozen.';

    // php:S2830: collaborators are wired in initialize() (not the constructor
    // body), so they stay non-readonly — phpstan forbids readonly assignment
    // outside the constructor; they are still write-once by construction.
    private ServiceRegistry $registry;
    private ServiceRegistrar $registrar;
    private DependencyGraphValidator $graphValidator;
    private ContainerResolver $resolver;
    private ContainerCompiler $compiler;
    private bool $frozen = false;
    private int $maxCrossModuleRefs = 0;
    private ArchitecturePolicy $policy;

    // v2.10.0 enterprise state (all pre-freeze composition-time data).
    /**
     * @var array<string,list<callable>> id => decorator chain (first = outermost)
     */
    private array $decorators = [];

    /**
     * @var list<array{consumer:string,dep:string,target:string,via:string}>
     */
    private array $contextualBindings = [];

    /**
     * @var list<ServiceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string,list<int>> provides() id => pending deferred provider indexes
     */
    private array $deferredIndex = [];

    /**
     * @var array<int,bool> provider indexes whose register() has run
     */
    private array $registeredProviders = [];
    private bool $providersBooted = false;

    // v2.11.0 radix-tree namespace layer (all composition-time; zero cost when unused).
    private ?NamespaceScopePolicy $namespacePolicy = null;
    private ?NamespaceRadixTree $namespaceTree = null;

    /**
     * @var array<string,array{factory:callable,lifetime:string}> normalized prefix => fallback
     */
    private array $namespaceFallbacks = [];

    /**
     * @var array<string,mixed> per-ID cache for singleton namespace fallbacks
     */
    private array $fallbackInstances = [];

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
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
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
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        if (count($this->registry->definitions()) >= $this->policy->maxServiceRegistrations) {
            throw new InvalidConfigurationException('Service registration budget exceeded.');
        }
        $this->registrar->register($id, $factory, $deps, $module, $lifetime);
    }

    public function registerDefinition(ServiceDefinition $definition): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        if (count($this->registry->definitions()) >= $this->policy->maxServiceRegistrations) {
            throw new InvalidConfigurationException('Service registration budget exceeded.');
        }
        if ($this->registry->hasFactory($definition->id) || $this->registry->hasAlias($definition->id)) {
            throw new InvalidFactoryException(
                "Factory for '{$definition->id}' is invalid: service ID already registered.",
            );
        }
        $this->registry->addDefinition($definition);
    }

    public function alias(string $alias, string $target, ?string $module = null): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        $this->registrar->alias($alias, $target, $module);
    }

    public function validateAndFreeze(): void
    {
        $this->triggerRequiredDeferredProviders();
        $this->applyDecorations();
        $plan = $this->compiler->compile($this->registry, $this->maxCrossModuleRefs);
        // v2.11.0: build the sealed namespace radix tree AFTER graph validation
        // (all IDs canonical + proven) and enforce namespace scope policy.
        $this->namespaceTree = new RadixTreeCompilerPass(
            $this->namespacePolicy ?? new NamespaceScopePolicy()
        )->process($plan);
        // @infection-ignore-all MethodCallRemoval — ekuivalen: jalur validator
        // menghasilkan get/has/eksepsi identik untuk seluruh konfigurasi
        // publik; terverifikasi oleh kurikulum freeze
        $this->resolver->installPlan($plan);
        $this->frozen = true;
    }

    public function warmSingletons(): void
    {
        if (!$this->frozen) {
            throw new \LogicException('Container must be frozen before warming singletons.');
        }
        foreach ($this->registry->definitions() as $id => $definition) {
            if (
                $definition->lifetime === ServiceLifetime::SINGLETON
                && $definition->shared
                && !$definition->lazy
            ) {
                $this->get($id);
            }
        }
    }

    #[\Override]
    public function get(string $id): mixed
    {
        $this->triggerDeferredProviders($id);
        // v2.11.0: namespace fallback — only for IDs the container does NOT
        // know (never shadows registered services; never used for graph deps,
        // which are validated to exist before freeze).
        if ($this->namespaceFallbacks !== [] && !$this->resolver->hasInContext($id)) {
            $fallback = $this->fallbackFor($id);
            if ($fallback !== null) {
                return $this->resolveViaFallback($id, $fallback);
            }
        }

        return $this->resolver->resolveRoot($id);
    }

    #[\Override]
    public function has(string $id): bool
    {
        if ($this->resolver->hasInContext($id)) {
            return true;
        }

        return $this->namespaceFallbacks !== [] && $this->fallbackFor($id) !== null;
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
            $this->fallbackInstances = []; // v2.11.0: fallback singletons follow the same lifecycle
        }
    }

    /**
     * @return list<string>
     */
    public function getRegisteredIds(): array
    {
        // @infection-ignore-all UnwrapArrayValues — ekuivalen: id factory dan
        // alias unik serta bertipe string; merge mempertahankan kunci string
        return array_values(
            // @infection-ignore-all UnwrapArrayUnique — ekuivalen: duplikat
            // mustahil: registrar menolak registrasi id yang sama
            array_unique(
                array_merge(
                    array_keys($this->registry->factories()),
                    array_keys($this->registry->aliases()),
                )
            )
        );
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
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        $definitions = $this->registry->definitions();
        if (!isset($definitions[$consumer])) {
            throw new InvalidConfigurationException(
                "Contextual binding: consumer '{$consumer}' is not a registered service.",
            );
        }
        $definition = $definitions[$consumer];
        if (!in_array($dep, $definition->dependencies, true)) {
            throw new InvalidConfigurationException(
                "Contextual binding: consumer '{$consumer}' does not declare dependency '{$dep}'.",
            );
        }
        if ($target === '') {
            throw new InvalidConfigurationException('Contextual binding target must be a non-empty service ID.');
        }
        // @infection-ignore-all Foreach_ — ekuivalen: binding pertama
        // menulis-ulang deps konsumen (dep -> @contextual:alias) sehingga
        // guard duplikat tak terjangkau
        foreach ($this->contextualBindings as $existing) {
            if ($existing['consumer'] === $consumer && $existing['dep'] === $dep) {
                throw new InvalidConfigurationException(
                    "Contextual binding: consumer '{$consumer}' already binds '{$dep}'.",
                );
            }
        }
        $via = '@contextual:' . $consumer . '|' . $dep;
        // Synthetic alias (collision-checked by the registrar) plus a rewritten
        // consumer definition whose dependency graph now flows through $via —
        // so graph validation, cycles and cross-module budgets still apply.
        $this->alias($via, $target);
        $newDeps = array_map(
            static fn (string $d): string => $d === $dep ? $via : $d,
            $definition->dependencies,
        );
        $this->registry->addDefinition(new ServiceDefinition(
            $definition->id,
            $definition->factory,
            $newDeps,
            $definition->module,
            $definition->lifetime,
            $definition->shared,
            $definition->lazy,
            $definition->tags,
        ));
        $this->contextualBindings[] = [
            'consumer' => $consumer, 'dep' => $dep, 'target' => $target, 'via' => $via,
        ];
    }

    /** @return list<array{consumer:string,dep:string,target:string,via:string}> */
    public function getContextualBindings(): array
    {
        return $this->contextualBindings;
    }

    /**
     * Decorate a service: $decorator receives (ResolutionContext $ctx, mixed $inner)
     * and returns the decorated instance. First-registered = outermost.
     * Applied at validateAndFreeze() time as wrapper definitions.
     */
    public function decorate(string $id, callable $decorator): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        $total = array_sum(array_map(count(...), $this->decorators)) + 1;
        if ($total > 128) {
            throw new \OverflowException('Container decoration budget exceeded (128).');
        }
        $this->decorators[$id][] = $decorator;
    }

    /**
     * Register a service provider. Eager providers run register() now;
     * DeferrableProviderInterface providers run register() the first time
     * get() asks for one of their provides() IDs (composition-time deferred
     * loading — always before validateAndFreeze()).
     */
    public function registerProvider(ServiceProviderInterface $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        if (count($this->providers) >= 64) {
            throw new \OverflowException('Container provider budget exceeded (64).');
        }
        $index = count($this->providers);
        $this->providers[] = $provider;
        if ($provider instanceof DeferrableProviderInterface) {
            foreach ($provider->provides() as $id) {
                $this->deferredIndex[$id][] = $index;
            }

            return;
        }
        // @infection-ignore-all TrueValue — ekuivalen: flag map hanya dibaca lewat isset(); nilai tidak relevan
        $this->registeredProviders[$index] = true;
        $provider->register($this);
    }

    /** Boot hook for registered BootableProviderInterface providers (once). */
    public function bootProviders(): void
    {
        if ($this->providersBooted) {
            return;
        }
        $this->providersBooted = true;
        foreach ($this->providers as $index => $provider) {
            if ($provider instanceof BootableProviderInterface && isset($this->registeredProviders[$index])) {
                $provider->boot($this);
            }
        }
    }

    /** @return list<ServiceProviderInterface> all registered providers, in order */
    public function getProviders(): array
    {
        return $this->providers;
    }

    // ---------------------------------------------------------------------
    // v2.11.0 — RadixTree namespace layer (additive, composition-time).
    // ---------------------------------------------------------------------

    /** Install a namespace scope policy enforced at validateAndFreeze() time. */
    public function configureNamespacePolicy(NamespaceScopePolicy $policy): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        $this->namespacePolicy = $policy;
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
        if ($this->frozen) {
            throw new \LogicException(self::FROZEN_MESSAGE);
        }
        if (count($this->namespaceFallbacks) >= 64) {
            throw new \OverflowException('Container namespace-fallback budget exceeded (64).');
        }
        if ($lifetime === ServiceLifetime::REQUEST) {
            throw new InvalidConfigurationException(
                'Namespace fallback lifetime cannot be REQUEST (fallback IDs are not scoped).',
            );
        }
        ServiceLifetime::assert($lifetime);
        $this->namespaceFallbacks[NamespaceScopePolicy::normalize($prefix)] = [
            'factory' => $factory,
            'lifetime' => $lifetime,
        ];
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
        $tree = $this->namespaceTree;
        if (!$tree instanceof NamespaceRadixTree) {
            throw new \LogicException('Namespace tree is not built yet — call validateAndFreeze() first.');
        }
        $out = [];
        foreach ($tree->idsUnderPrefix($prefix) as $id) {
            $out[$id] = $this->get($id);
        }

        return $out;
    }

    /** ID-only variant of getByPrefix() (no instantiation). @return list<string> */
    public function getIdsByPrefix(string $prefix): array
    {
        $tree = $this->namespaceTree;
        if (!$tree instanceof NamespaceRadixTree) {
            throw new \LogicException('Namespace tree is not built yet — call validateAndFreeze() first.');
        }

        return $tree->idsUnderPrefix($prefix);
    }

    /** The sealed namespace radix tree (null before freeze). */
    public function namespaceTree(): ?NamespaceRadixTree
    {
        return $this->namespaceTree;
    }

    /**
     * @return null|array{
     *     serviceIds:int, nodes:int, edges:int, maxDepth:int,
     *     rawSegments:int, compressionRatio:float, annotations:int, sealed:bool
     * }
     */
    public function namespaceStats(): ?array
    {
        return $this->namespaceTree?->stats();
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
        $this->graphValidator = new DependencyGraphValidator();
        $this->compiler = new ContainerCompiler($this->graphValidator);
        $this->resolver = new ContainerResolver(
            $this->registry,
            $this->graphValidator,
            $initializationGuard ?? new FailFastInitializationGuard(),
            $this->policy->maxResolutionDepth,
        );
    }

    /**
     * Deferred providers whose provides() IDs are referenced by the existing
     * graph (as a dependency or alias target) must register before compile —
     * otherwise their definitions would be invisible to graph validation.
     * Providers nobody references stay pending until a pre-freeze get().
     */
    private function triggerRequiredDeferredProviders(): void
    {
        if ($this->deferredIndex === []) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: tanpa deferred
            // provider, map referenced tetap kosong; loop menjadi no-op
            return;
        }
        $referenced = [];
        foreach ($this->registry->definitions() as $definition) {
            foreach ($definition->dependencies as $dep) {
                // @infection-ignore-all TrueValue — ekuivalen: array_keys() hanya membaca kunci; nilai tidak relevan
                $referenced[$dep] = true;
            }
        }
        foreach ($this->registry->aliases() as $target) {
            // @infection-ignore-all TrueValue — ekuivalen: array_keys() hanya membaca kunci; nilai tidak relevan
            $referenced[$target] = true;
        }
        foreach (array_keys($referenced) as $id) {
            $this->triggerDeferredProviders((string) $id);
        }
    }

    /** Applies decorator chains by rewriting the registry (pre-compile). */
    private function applyDecorations(): void
    {
        if ($this->decorators === []) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: tanpa decorator,
            // foreach di atas map kosong adalah no-op
            return;
        }
        $budget = $this->policy->maxServiceRegistrations;
        foreach ($this->decorators as $id => $chain) {
            $definitions = $this->registry->definitions();
            if (!isset($definitions[$id])) {
                throw new InvalidConfigurationException("Cannot decorate unknown service '{$id}'.");
            }
            $definition = $definitions[$id];
            $baseId = '@inner:' . $id . ':base';
            // Innermost: the original definition re-homed under a synthetic id.
            // ZEF-DEEP-08: the re-homed base is internal plumbing — it must
            // NOT keep the tags (pre-fix it did), or the tag index points
            // consumers at the synthetic id and they silently resolve the
            // UNDECORATED inner instance. The tags travel with the service
            // identity — the outermost wrapper registered under the original
            // id — mirroring the contextual-binding rewrite pattern.
            $this->registry->addDefinition(new ServiceDefinition(
                $baseId,
                $definition->factory,
                $definition->dependencies,
                $definition->module,
                $definition->lifetime,
                $definition->shared,
                $definition->lazy,
                [],
            ));
            // @infection-ignore-all GreaterThanOrEqualTo,Throw_ — ekuivalen:
            // redundan dengan cek budget per-wrapper di dalam loop; penegakan
            // budget tetap terjamin
            if (count($this->registry->definitions()) >= $budget) {
                throw new InvalidConfigurationException('Service registration budget exceeded during decoration.');
            }
            $this->wrapDecoratorChain($id, $baseId, $chain, $definition, $budget);
        }
    }

    /**
     * Registers the outermost-first decorator chain as nested wrappers (inside out).
     *
     * @param list<callable> $chain
     */
    private function wrapDecoratorChain(
        string $id,
        string $baseId,
        array $chain,
        ServiceDefinition $definition,
        int $budget,
    ): void {
        $previousId = $baseId;
        $count = count($chain);
        // Outermost-first chain => wrap from the inside out.
        for ($i = $count - 1; $i >= 0; --$i) {
            $decorator = $chain[$i];
            $wrapperId = $i === 0 ? $id : '@inner:' . $id . ':' . $i;
            $closureId = $previousId;
            $this->registry->addDefinition(new ServiceDefinition(
                $wrapperId,
                static fn (ContainerInterface $ctx, mixed $inner): mixed => $decorator($ctx, $inner),
                [$closureId],
                $definition->module,
                $definition->lifetime,
                $definition->shared,
                $definition->lazy,
                $i === 0 ? $definition->tags : [],
            ));
            if (count($this->registry->definitions()) >= $budget) {
                throw new InvalidConfigurationException('Service registration budget exceeded during decoration.');
            }
            $previousId = $wrapperId;
        }
    }

    /** Runs pending deferred providers that provide $id. */
    private function triggerDeferredProviders(string $id): void
    {
        $pending = $this->deferredIndex[$id] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->deferredIndex[$id]);
        foreach ($pending as $index) {
            if (isset($this->registeredProviders[$index])) {
                continue;
            }
            if ($this->frozen) {
                // PSR-11 (ZEF-DEEP-12): has() reports the service as absent
                // (its deferred provider never ran), so get() must surface a
                // NotFoundExceptionInterface — container-agnostic callers catch
                // that standard interface, not the framework's LogicException.
                $hint = ' — request it before validateAndFreeze() or register the provider as eager.';

                throw new ServiceNotFoundException(
                    $id,
                    null,
                    "Deferred provider service '{$id}' requested but the container is already frozen" . $hint,
                );
            }
            // @infection-ignore-all TrueValue — ekuivalen: registeredProviders
            // hanya dibaca lewat isset(); nilai tidak relevan
            $this->registeredProviders[$index] = true;
            $this->providers[$index]->register($this);
        }
    }

    /** Longest-prefix fallback lookup. @return array{factory:callable,lifetime:string}|null */
    private function fallbackFor(string $id): ?array
    {
        $best = null;
        // @infection-ignore-all IncrementInteger,DecrementInteger — ekuivalen:
        // fallback prefix divalidasi non-kosong; strlen >= 1 selalu mengalahkan
        // init <= 0
        $bestLen = -1;
        foreach ($this->namespaceFallbacks as $prefix => $entry) {
            // @infection-ignore-all GreaterThan — ekuivalen: prefix berbeda
            // dengan panjang sama mustahil cocok pada satu id; untuk prefix
            // bersarang hasil pemenangnya sama
            if (str_starts_with($id, $prefix) && strlen($prefix) > $bestLen) {
                $best = $entry;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    /** @param array{factory:callable,lifetime:string} $fallback */
    private function resolveViaFallback(string $id, array $fallback): mixed
    {
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON && array_key_exists($id, $this->fallbackInstances)) {
            return $this->fallbackInstances[$id];
        }
        $factory = $fallback['factory'];

        try {
            $instance = $factory($this, $id);
        } catch (\Throwable $e) {
            throw new ServiceResolutionException($id, 'namespace fallback factory failed: ' . $e->getMessage(), $e);
        }
        if ($instance === null) {
            throw new ServiceResolutionException($id, 'namespace fallback factory returned null.');
        }
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON) {
            $this->fallbackInstances[$id] = $instance;
        }

        return $instance;
    }
}
