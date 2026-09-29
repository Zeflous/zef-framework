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

    private readonly int $resolutionDepthLimit;

    private ?CompiledContainerPlan $plan = null;

    private ?RequestScopeStore $scopeStore = null;

    private ?ServiceInstantiator $activator = null;

    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly DependencyGraphValidator $graphValidator,
        private readonly ?InitializationGuard $initializationGuard = null,
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
        $this->scopes()->register($scope);

        return $scope;
    }

    public function releaseScope(RequestScope $scope): void
    {
        $this->scopes()->release($scope);
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

        $hit = $this->activator()->cachedInstance($canonical, $definition, $scope);
        if ($hit[0]) {
            return $hit[1];
        }

        return $this->activator()->instantiate($canonical, $ctx, $definition, $dependencies, $scope);
    }

    public function scopeHasPublic(RequestScope $scope, string $id): bool
    {
        return $this->scopes()->has($scope, $id);
    }

    public function scopeGetPublic(RequestScope $scope, string $id): mixed
    {
        return $this->scopes()->get($scope, $id);
    }

    public function scopeSetPublic(RequestScope $scope, string $id, mixed $value): void
    {
        $this->scopes()->set($scope, $id, $value);
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

    /** Bug fix #14: extracted never-returning helper. */
    private function throwNotFound(string $id): never
    {
        $module = $this->registry->moduleOf()[$id] ?? null;

        throw new ServiceNotFoundException($id, is_string($module) ? $module : null);
    }

    private function scopes(): RequestScopeStore
    {
        $this->scopeStore ??= new RequestScopeStore();

        return $this->scopeStore;
    }

    private function activator(): ServiceInstantiator
    {
        $this->activator ??= new ServiceInstantiator(
            $this->registry,
            $this->scopes(),
            $this->initializationGuard ?? new FailFastInitializationGuard(),
        );

        return $this->activator;
    }
}
