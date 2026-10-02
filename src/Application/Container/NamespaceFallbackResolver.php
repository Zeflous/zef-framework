<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Namespace-fallback resolution extracted from Container (php:S2042): the
 * longest-prefix fallback registry, its per-ID singleton cache, and the
 * lifetime-aware invocation of fallback factories. Byte-identical move of
 * the Container logic — no behavioural changes.
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Policy\NamespaceScopePolicy;

final class NamespaceFallbackResolver
{
    /**
     * @var array<string,array{factory:callable,lifetime:string}> normalized prefix => fallback
     */
    private array $namespaceFallbacks = [];

    /**
     * @var array<string,mixed> per-ID cache for singleton namespace fallbacks
     */
    private array $fallbackInstances = [];

    /** @var array<int,int> fiber key => open fallback resolutions (depth budget, audit #344) */
    private array $activeResolutions = [];

    private readonly int $maxDepth;

    /**
     * v2.36.0 (audit #344/#345/#346): fallback construction is now guarded
     * like registry construction — same-id re-entry fails fast via the
     * shared InitializationGuard, unbounded recursion through generated ids
     * hits a depth budget, TRANSIENT fallbacks cannot be captured by an
     * open singleton subtree, singleton fallbacks participate in the
     * tracker, and the service-middleware onion wraps fallback
     * construction. Guard and tracker are required collaborators (the
     * container is the composition root — php:S2830).
     */
    public function __construct(
        private readonly InitializationGuard $guard,
        private readonly SingletonSubtreeTracker $subtrees,
        private readonly ?ServiceMiddlewarePipeline $middleware = null,
        int $maxDepth = 256,
    ) {
        $this->maxDepth = max(1, $maxDepth);
    }

    public function register(
        string $prefix,
        callable $factory,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void {
        $normalizedPrefix = NamespaceScopePolicy::normalize($prefix);
        // Re-registering a prefix replaces its factory; it does not consume
        // an additional slot in the bounded fallback registry.
        if (!array_key_exists($normalizedPrefix, $this->namespaceFallbacks) && count($this->namespaceFallbacks) >= 64) {
            throw new \OverflowException('Container namespace-fallback budget exceeded (64).');
        }
        if ($lifetime === ServiceLifetime::REQUEST) {
            throw new InvalidConfigurationException(
                'Namespace fallback lifetime cannot be REQUEST (fallback IDs are not scoped).',
            );
        }
        ServiceLifetime::assert($lifetime);
        $this->namespaceFallbacks[$normalizedPrefix] = [
            'factory' => $factory,
            'lifetime' => $lifetime,
        ];
    }

    /** True when at least one registered fallback prefix covers $id. */
    public function appliesTo(string $id): bool
    {
        return $this->namespaceFallbacks !== [] && $this->fallbackFor($id) !== null;
    }

    /**
     * Resolves $id through its longest-prefix fallback (lazily caching
     * SINGLETON results per requested ID).
     */
    public function resolve(ContainerInterface $container, string $id): mixed
    {
        $fallback = $this->fallbackFor($id);
        if ($fallback === null) {
            // Caller guards with appliesTo(); kept defensive for direct use.
            throw new ServiceResolutionException($id, 'no namespace fallback covers the requested id.');
        }
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON && array_key_exists($id, $this->fallbackInstances)) {
            return $this->fallbackInstances[$id];
        }
        $this->assertConstructionSafety($fallback, $id);

        $key = SingletonSubtreeTracker::fiberKey();
        $this->activeResolutions[$key] = ($this->activeResolutions[$key] ?? 0) + 1;

        try {
            $instance = $this->construct($container, $id, $fallback['factory'], $fallback['lifetime']);
        } finally {
            $depth = $this->activeResolutions[$key] ?? 1;
            if ($depth <= 1) {
                unset($this->activeResolutions[$key]);
            } else {
                $this->activeResolutions[$key] = $depth - 1;
            }
        }

        if ($instance === null) {
            throw new ServiceResolutionException($id, 'namespace fallback factory returned null.');
        }
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON) {
            $this->fallbackInstances[$id] = $instance;
        }

        return $instance;
    }

    /** Clears cached fallback singletons (mirrors Container::reset()). */
    public function clearSingletons(): void
    {
        $this->fallbackInstances = [];
    }

    /**
     * Pre-construction safety gate (audit #344/#345): the depth budget
     * covers mutually-recursive fallback prefixes (the InitializationGuard
     * only catches SAME-id re-entry), and a TRANSIENT fallback pulled while
     * a singleton subtree is open would be silently captured by the
     * singleton under construction — the fallback path never traverses
     * resolveInContext(), so the v2.35.0 per-context guard cannot see it.
     *
     * @param array{factory:callable,lifetime:string} $fallback
     */
    private function assertConstructionSafety(array $fallback, string $id): void
    {
        $key = SingletonSubtreeTracker::fiberKey();
        if (($this->activeResolutions[$key] ?? 0) >= $this->maxDepth) {
            throw new InvalidConfigurationException('Dependency resolution depth exceeds configured safety budget.');
        }
        if ($fallback['lifetime'] !== ServiceLifetime::SINGLETON && $this->subtrees->isOpen()) {
            $owner = $this->subtrees->current() ?? $id;

            throw new ServiceResolutionException(
                $id,
                sprintf(
                    "implicit lifetime capture: singleton '%s' resolves %s fallback service '%s'. %s",
                    $owner,
                    $fallback['lifetime'],
                    $id,
                    'Declare it as a singleton dependency, or resolve it within the construction scope.',
                ),
            );
        }
    }

    /**
     * Runs the construction: singleton fallbacks open a tracker entry (so
     * registry pulls made from inside them are capture-checked by
     * ContainerResolver), the shared InitializationGuard fails fast on
     * same-id re-entry, and the service-middleware onion wraps the whole
     * thing (audit #346 — fallback construction is construction).
     */
    private function construct(ContainerInterface $container, string $id, callable $factory, string $lifetime): mixed
    {
        $singleton = $lifetime === ServiceLifetime::SINGLETON;
        if ($singleton) {
            $this->subtrees->enter($id);
        }

        try {
            return $this->guardedConstruction($container, $id, $factory);
        } finally {
            if ($singleton) {
                $this->subtrees->exit($id);
            }
        }
    }

    private function guardedConstruction(ContainerInterface $container, string $id, callable $factory): mixed
    {
        $guarded = fn (): mixed => $this->guard->synchronized($id, fn (): mixed => $factory($container, $id));
        $run = $this->middleware?->hasMiddleware() === true
            ? fn (): mixed => $this->middleware->run($id, $guarded)
            : $guarded;

        try {
            return $run();
        } catch (ServiceResolutionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ServiceResolutionException($id, 'namespace fallback factory failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Longest-prefix fallback lookup.
     *
     * @return null|array{factory: callable, lifetime: string}
     */
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
}
