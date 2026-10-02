<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from ContainerResolver during the sonar-zero campaign
 * (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ServiceResolutionException;

/**
 * @internal instantiation pipeline behind ContainerResolver::resolveInContext().
 *
 * Cached-instance check (per lifetime) → resolving listeners → guarded
 * factory invocation under the InitializationGuard → resolved listeners
 * → per-lifetime caching. The resolution stack (push/pop of the
 * canonical id) stays on the caller's ResolutionContext so circular
 * dependency detection keeps working across nested resolutions.
 *
 * v2.35.0: push/pop now carry the definition lifetime (runtime
 * implicit-capture guard data) and the factory invocation is wrapped by
 * the service middleware pipeline (roadmap: service middleware/
 * interceptors) — resolving/resolved events remain the outer invariants.
 *
 * v2.36.0 (audit #343/#347): singleton constructions additionally open a
 * container-wide SingletonSubtreeTracker entry (fiber-scoped) so root-
 * container pulls cannot silently capture non-singletons; synthetic
 * machinery ids ("@...", e.g. decorator "@inner:*") bypass the middleware
 * onion; a null construction result names the actual culprit (middleware
 * short-circuit vs factory return).
 */
final readonly class ServiceInstantiator
{
    public function __construct(
        private ServiceRegistry $registry,
        private RequestScopeStore $scopes,
        private InitializationGuard $initializationGuard,
        private ServiceMiddlewarePipeline $middleware = new ServiceMiddlewarePipeline(),
        private SingletonSubtreeTracker $subtrees = new SingletonSubtreeTracker(),
    ) {}

    /**
     * Cached instance for the canonical id, if one is already materialized
     * for the definition's lifetime (singleton store or request scope).
     *
     * @return array{bool, mixed} [true, instance] on a cache hit
     */
    public function cachedInstance(
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
        if ($definition->lifetime === ServiceLifetime::REQUEST) {
            if (!$scope instanceof RequestScope || $scope->isClosed()) {
                throw new \LogicException("Request-scoped service '{$canonical}' resolved outside a request scope.");
            }
            if ($this->scopes->has($scope, $canonical)) {
                return [true, $this->scopes->get($scope, $canonical)];
            }
        }

        return [false, null];
    }

    /**
     * Instantiates the service: fires the resolving event, resolves the
     * dependencies, invokes the factory under the initialization guard,
     * fires the resolved event and caches the result per lifetime.
     *
     * @param list<string> $dependencies dependency ids of the canonical definition
     */
    public function instantiate(
        string $canonical,
        ResolutionContext $ctx,
        ServiceDefinition $definition,
        array $dependencies,
        ?RequestScope $scope,
    ): mixed {
        $ctx->push($canonical, $definition->lifetime);
        $singleton = $definition->lifetime === ServiceLifetime::SINGLETON;
        if ($singleton) {
            $this->subtrees->enter($canonical);
        }

        try {
            $this->fireResolvingListeners($canonical, $dependencies);
            $terminalRan = false;
            $instance = $this->constructionPath($canonical, $ctx, $definition, $dependencies, $terminalRan);
            if ($instance === null) {
                throw new ServiceResolutionException($canonical, $terminalRan
                    ? 'factory returned null.'
                    : 'service middleware returned null without calling $next.');
            }
            $instance = $this->fireResolvedListeners($canonical, $instance);
            $this->cacheInstance($canonical, $definition, $scope, $instance);

            return $instance;
        } finally {
            if ($singleton) {
                $this->subtrees->exit($canonical);
            }
            $ctx->pop($canonical, $definition->lifetime);
        }
    }

    /**
     * Picks the construction path: synthetic machinery ids ("@..." —
     * decorator/contextual plumbing) bypass the middleware onion, everything
     * else runs through it when middleware is registered. $terminalRan is
     * set when the guarded factory was actually reached (null diagnostics).
     *
     * @param list<string> $dependencies dependency ids of the canonical definition
     */
    private function constructionPath(
        string $canonical,
        ResolutionContext $ctx,
        ServiceDefinition $definition,
        array $dependencies,
        bool &$terminalRan,
    ): mixed {
        if (!$this->middleware->hasMiddleware() || str_starts_with($canonical, '@')) {
            $terminalRan = true;

            return $this->invokeFactory($canonical, $ctx, $definition, $dependencies);
        }

        return $this->runMiddleware(
            $canonical,
            function () use (&$terminalRan, $canonical, $ctx, $definition, $dependencies): mixed {
                $terminalRan = true;

                return $this->invokeFactory($canonical, $ctx, $definition, $dependencies);
            },
        );
    }

    /**
     * @param list<string> $dependencies dependency ids of the canonical definition
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
     * v2.35.0: middleware onion around the guarded factory invocation.
     * Exceptions from middleware are wrapped like listener failures;
     * ServiceResolutionException passes through unwrapped.
     */
    private function runMiddleware(string $canonical, \Closure $terminal): mixed
    {
        try {
            return $this->middleware->run($canonical, $terminal);
        } catch (\Throwable $e) {
            if ($e instanceof ServiceResolutionException) {
                throw $e;
            }

            throw new ServiceResolutionException($canonical, 'service middleware failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @param list<string> $dependencies dependency ids of the canonical definition
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

            return;
        }
        if ($definition->lifetime === ServiceLifetime::REQUEST && $scope instanceof RequestScope) {
            $this->scopes->set($scope, $canonical, $instance);
        }
        // Transient (or request-scoped with no active scope): never cached.
    }
}
