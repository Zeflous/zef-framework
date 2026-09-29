<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * @internal
 */
final class ContainerResolver
{
    private ?ContainerInterface $rootContainer = null;

    /**
     * @var null|\WeakMap<RequestScope,array<string,mixed>>
     */
    private ?\WeakMap $scopeInstances = null;
    private readonly int $resolutionDepthLimit;
    private ?CompiledContainerPlan $plan = null;

    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly DependencyGraphValidator $graphValidator,
        private readonly InitializationGuard $initializationGuard = new FailFastInitializationGuard(),
        int $resolutionDepthLimit = 256,
    ) {
        $this->resolutionDepthLimit = max(1, $resolutionDepthLimit);
    }

    public function bind(ContainerInterface $container): void
    {
        $this->rootContainer = $container;
    }

    public function installPlan(CompiledContainerPlan $plan): void
    {
        $this->plan = $plan;
    }

    public function maxResolutionDepth(): int
    {
        return $this->resolutionDepthLimit;
    }

    public function createRequestScope(): RequestScope
    {
        $scope = new RequestScope($this);
        $this->scopeInstances()[$scope] = [];

        return $scope;
    }

    public function releaseScope(RequestScope $scope): void
    {
        if ($this->scopeInstances !== null) {
            unset($this->scopeInstances[$scope]);
        }
    }

    public function clearSingletons(): void
    {
        $this->registry->clearInstances();
    }

    public function resolveRoot(string $id): mixed
    {
        if (!$this->rootContainer instanceof ContainerInterface) {
            throw new \LogicException('Container resolver is not bound.');
        }
        $ctx = new ResolutionContext($this, null);

        try {
            return $ctx->get($id);
        } catch (\LogicException $e) {
            throw new ServiceResolutionException($id, $e->getMessage(), $e);
        }
    }

    public function resolveInContext(string $id, ResolutionContext $ctx, ?RequestScope $scope): mixed
    {
        $activePlan = $this->plan;
        if (!$activePlan instanceof CompiledContainerPlan) {
            $canonical = $this->graphValidator->resolveAlias($id, $this->registry->aliases());
            $definition = $this->registry->definitions()[$canonical] ?? null;
            if ($definition === null) {
                $this->throwNotFound($id);
            }
            $dependencies = $definition->dependencies;
        } else {
            $canonical = $activePlan->canonical($id);
            if ($canonical === null) {
                // @infection-ignore-all MethodCallRemoval — ekuivalen: kontrol jatuh ke
                // throwNotFound identik pada null-check berikutnya; eksepsi sama
                $this->throwNotFound($id);
            }
            $definition = $activePlan->definitions[$canonical] ?? null;
            if ($definition === null) {
                $this->throwNotFound($id);
            }
            $dependencies = $activePlan->dependenciesOf($canonical);
        }

        $hit = $this->cachedInstance($canonical, $definition, $scope);
        if ($hit[0]) {
            return $hit[1];
        }

        return $this->instantiate($canonical, $ctx, $definition, $dependencies, $scope);
    }

    /**
     * Cached instance for the canonical id, if one is already materialized
     * for the definition's lifetime (singleton store or request scope).
     *
     * @return array{bool, mixed} [true, instance] on a cache hit
     */
    private function cachedInstance(
        string $canonical,
        ServiceDefinition $definition,
        ?RequestScope $scope,
    ): array {
        if (
            $definition->lifetime === ServiceLifetime::SINGLETON
            && $definition->shared
            && $this->registry->hasInstance($canonical)
        ) {
            return [true, $this->registry->instance($canonical)];
        }
        if ($definition->lifetime !== ServiceLifetime::REQUEST) {
            return [false, null];
        }
        if (!$scope instanceof RequestScope || $scope->isClosed()) {
            throw new \LogicException("Request-scoped service '{$canonical}' resolved outside a request scope.");
        }
        if ($this->scopeHas($scope, $canonical)) {
            return [true, $this->scopeGet($scope, $canonical)];
        }

        return [false, null];
    }

    /**
     * Instantiates the service: fires the resolving event, resolves the
     * dependencies, invokes the factory under the initialization guard,
     * fires the resolved event and caches the result per lifetime.
     *
     * @param array<mixed> $dependencies dependency ids of the canonical definition
     */
    private function instantiate(
        string $canonical,
        ResolutionContext $ctx,
        ServiceDefinition $definition,
        array $dependencies,
        ?RequestScope $scope,
    ): mixed {
        $ctx->push($canonical);

        try {
            $this->fireResolvingListeners($canonical, $dependencies);
            $instance = $this->invokeFactory($canonical, $ctx, $definition, $dependencies);
            if ($instance === null) {
                throw new ServiceResolutionException($canonical, 'factory returned null.');
            }
            $instance = $this->fireResolvedListeners($canonical, $instance);
            $this->cacheInstance($canonical, $definition, $scope, $instance);

            return $instance;
        } finally {
            $ctx->pop($canonical);
        }
    }

    /**
     * @param array<mixed> $dependencies dependency ids of the canonical definition
     */
    private function fireResolvingListeners(string $canonical, array $dependencies): void
    {
        // v2.10.0: resolving event — fired only on actual instantiation
        // (cache hits return early), before deps are resolved.
        if (!$this->registry->hasResolvingListeners()) {
            return;
        }
        try {
            foreach ($this->registry->resolvingListeners() as $listener) {
                $listener($canonical, $dependencies);
            }
        } catch (\Throwable $e) {
            throw new ServiceResolutionException(
                $canonical,
                'resolving listener failed: ' . $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @param array<mixed> $dependencies dependency ids of the canonical definition
     */
    private function invokeFactory(
        string $canonical,
        ResolutionContext $ctx,
        ServiceDefinition $definition,
        array $dependencies,
    ): mixed {
        $deps = [];
        foreach ($dependencies as $dep) {
            $deps[] = $ctx->get($dep);
        }
        if (!is_callable($definition->factory)) {
            throw new ServiceResolutionException($canonical, 'factory is not callable.');
        }
        $factory = $definition->factory;

        try {
            return $this->initializationGuard->synchronized(
                $canonical,
                fn () => $factory($ctx, ...$deps),
            );
        } catch (\Throwable $e) {
            if ($e instanceof ServiceResolutionException) {
                throw $e;
            }

            throw new ServiceResolutionException($canonical, $e->getMessage(), $e);
        }
    }

    /**
     * v2.10.0: resolved event — a non-null return value replaces the
     * instance before it is cached (runtime decorator-style hook).
     */
    private function fireResolvedListeners(string $canonical, mixed $instance): mixed
    {
        if (!$this->registry->hasResolvedListeners()) {
            return $instance;
        }
        try {
            foreach ($this->registry->resolvedListeners() as $listener) {
                $replacement = $listener($canonical, $instance);
                if ($replacement !== null) {
                    $instance = $replacement;
                }
            }
        } catch (\Throwable $e) {
            throw new ServiceResolutionException(
                $canonical,
                'resolved listener failed: ' . $e->getMessage(),
                $e,
            );
        }

        return $instance;
    }

    private function cacheInstance(
        string $canonical,
        ServiceDefinition $definition,
        ?RequestScope $scope,
        mixed $instance,
    ): void {
        if ($definition->lifetime === ServiceLifetime::SINGLETON && $definition->shared) {
            $this->registry->setInstance($canonical, $instance);
        } elseif ($definition->lifetime === ServiceLifetime::REQUEST && $scope instanceof RequestScope) {
            $this->scopeSet($scope, $canonical, $instance);
        } else {
            /* transient (or request-scoped with no active scope): never cached */
        }
    }

    public function scopeHasPublic(RequestScope $scope, string $id): bool
    {
        return $this->scopeHas($scope, $id);
    }

    public function scopeGetPublic(RequestScope $scope, string $id): mixed
    {
        return $this->scopeGet($scope, $id);
    }

    public function scopeSetPublic(RequestScope $scope, string $id, mixed $value): void
    {
        $this->scopeSet($scope, $id, $value);
    }

    public function hasInContext(string $id): bool
    {
        try {
            if ($this->plan instanceof CompiledContainerPlan) {
                // @infection-ignore-all ReturnRemoval — ekuivalen: jalur validator memberi jawaban
                // sama untuk id yang dikenal dan tidak dikenal; terverifikasi kurikulum freeze
                return $this->plan->canonical($id) !== null;
            }
            $canonical = $this->graphValidator->resolveAlias($id, $this->registry->aliases());

            // @infection-ignore-all LogicalAnd — ekuivalen: entri lifetime selalu datang dengan
            // factory pada registry; hasil konjungsi dan disjungsi berimpit
            return isset($this->registry->lifetimeOf()[$canonical]) && $this->registry->hasFactory($canonical);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Bug fix #14: extracted helper to avoid 3x repeated inline expression. */
    private function moduleFor(string $id): ?string
    {
        $module = $this->registry->moduleOf()[$id] ?? null;

        return is_string($module) ? $module : null;
    }

    /** Bug fix #14: extracted never-returning helper. */
    private function throwNotFound(string $id): never
    {
        throw new ServiceNotFoundException($id, $this->moduleFor($id));
    }

    private function scopeHas(RequestScope $scope, string $id): bool
    {
        $instances = $this->scopeInstances;

        return $instances !== null
            && isset($instances[$scope])
            && array_key_exists($id, $instances[$scope]);
    }

    private function scopeGet(RequestScope $scope, string $id): mixed
    {
        $instances = $this->scopeInstances;
        if ($instances === null
            || !isset($instances[$scope])
            || !array_key_exists($id, $instances[$scope])
        ) {
            throw new \LogicException("No instance for '{$id}' in the active request scope.");
        }

        return $instances[$scope][$id];
    }

    private function scopeSet(RequestScope $scope, string $id, mixed $value): void
    {
        $instances = $this->scopeInstances();
        $state = $instances[$scope] ?? [];
        $state[$id] = $value;
        $instances[$scope] = $state;
    }

    /**
     * @return \WeakMap<RequestScope,array<string,mixed>>
     */
    private function scopeInstances(): \WeakMap
    {
        return $this->scopeInstances ??= new \WeakMap();
    }
}
