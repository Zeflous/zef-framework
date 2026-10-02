<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.35.0 — Application layer (in-process orchestration)
 * Roadmap "Service middleware/interceptors": a priority-ordered onion
 * around service construction.
 */

namespace Zef\Framework\Container;

/**
 * Priority-ordered middleware pipeline wrapping the service construction
 * step (cache-miss instantiation path only — consistent with the
 * resolving/resolved events, which fire on actual instantiation).
 *
 * Middleware signature: fn(string $serviceId, Closure $next): mixed.
 * - Call $next() to run the default construction (deps + factory under the
 *   initialization guard).
 * - Returning without calling $next() short-circuits construction: the
 *   returned value becomes the service instance (still passes through the
 *   resolved listeners and per-lifetime caching).
 * - Higher priority runs first (outermost); ties keep registration order.
 *
 * The pipeline is sealed by validateAndFreeze() so a frozen container has a
 * deterministic, immutable resolution pipeline.
 */
final class ServiceMiddlewarePipeline
{
    private const string SEALED_MESSAGE = 'Service middleware pipeline is sealed.';

    /** @var list<array{middleware:callable, priority:int, seq:int}> */
    private array $entries = [];

    /** @var null|list<array{middleware:callable, priority:int, seq:int}> */
    private ?array $sortedCache = null;

    private bool $sealed = false;

    public function add(callable $middleware, int $priority = 0): void
    {
        if ($this->sealed) {
            throw new \LogicException(self::SEALED_MESSAGE);
        }
        $this->entries[] = ['middleware' => $middleware, 'priority' => $priority, 'seq' => count($this->entries)];
        $this->sortedCache = null;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    public function hasMiddleware(): bool
    {
        return $this->entries !== [];
    }

    /** @return int number of registered middleware */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Runs the onion: outermost (highest priority) middleware is invoked
     * first; $terminal performs the default construction. No-op fast path
     * when no middleware is registered.
     */
    public function run(string $serviceId, \Closure $terminal): mixed
    {
        $sorted = $this->sortedCache ??= $this->sorted();

        $next = $terminal;
        // Iterate innermost-first: lowest priority becomes the inner layer.
        for ($i = count($sorted) - 1; $i >= 0; --$i) {
            $middleware = $sorted[$i]['middleware'];
            $next = static fn (): mixed => $middleware($serviceId, $next);
        }

        return $next();
    }

    /**
     * @return list<array{middleware:callable, priority:int, seq:int}>
     *         priority DESC (outermost first), registration order preserved on ties
     */
    private function sorted(): array
    {
        $sorted = $this->entries;
        usort($sorted, static function (array $a, array $b): int {
            $byPriority = $b['priority'] <=> $a['priority'];
            if ($byPriority !== 0) {
                return $byPriority;
            }

            return $a['seq'] <=> $b['seq'];
        });

        return $sorted;
    }
}

// @internal
