<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * Request matching over a RouteCollection (extracted from Router):
 * the constraint-aware fast path, then the structural slow path that
 * preserves 405/400 semantics before falling back to 404.
 *
 * @phpstan-import-type RouteRecord from RouteCollection
 */
final class RouteMatcher
{
    public function __construct(
        private readonly RouteCollection $collection,
        private readonly RouteConstraintValidator $constraints,
        private readonly RouteRadixIndex $radix,
    ) {}

    /**
     * @return array{handler:string,module:?string,params:array<string,string>,pattern:string}
     */
    public function match(string $method, string $path, bool $frozen): array
    {
        $routes = $this->collection->sortedRoutes();
        $method = strtoupper(trim($method));
        $effectiveMethods = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        if (!$frozen) {
            $this->radix->compile($routes);
        }

        $hit = $this->constraintAwareHit($routes, $effectiveMethods, $path);
        if ($hit !== null) {
            return $hit;
        }

        throw $this->failureException($routes, $effectiveMethods, $method, $path);
    }

    /**
     * Like match(), but an unmatched path (RouteNotFoundException)
     * resolves to the registered fallback handler instead of throwing.
     * Method-not-allowed (405) and constraint (400) semantics are
     * preserved — the fallback only covers "no route matched this path".
     *
     * @return array{handler:string,module:?string,params:array<string,string>,pattern:string,fallback:bool}
     */
    public function matchOrFallback(string $method, string $path, bool $frozen, ?string $fallbackHandler): array
    {
        try {
            $hit = $this->match($method, $path, $frozen);
            $hit['fallback'] = false;

            return $hit;
        } catch (RouteNotFoundException $notFound) {
            if ($fallbackHandler === null) {
                throw $notFound;
            }

            return [
                'handler' => $fallbackHandler,
                'module' => null,
                'params' => [],
                'pattern' => '*fallback*',
                'fallback' => true,
            ];
        }
    }

    /**
     * Fast path: constraint-aware radix traversal (moved from Router).
     *
     * @param list<RouteRecord> $routes
     * @param list<string> $effectiveMethods
     *
     * @return null|array{handler:string,module:?string,params:array<string,string>,pattern:string}
     */
    private function constraintAwareHit(array $routes, array $effectiveMethods, string $path): ?array
    {
        $candidates = $this->radix->candidates($path, true, $this->constraints);
        foreach ($effectiveMethods as $effectiveMethod) {
            foreach ($candidates as $index) {
                $route = $routes[$index];
                if ($route['method'] !== $effectiveMethod) {
                    continue;
                }
                $params = RoutePatternParser::matchRoute($route['segments'], $path, $this->constraints);
                if ($params === false || $params instanceof RouteConstraintException) {
                    continue;
                }

                return [
                    'handler' => $route['handler'],
                    'module' => $route['module'],
                    'params' => $params,
                    'pattern' => $route['pattern'],
                ];
            }
        }

        return null;
    }

    /**
     * Slow/failure path: structural candidates for 400/405 semantics
     * (moved from Router).
     *
     * @param list<RouteRecord> $routes
     * @param list<string> $effectiveMethods
     */
    private function failureException(
        array $routes,
        array $effectiveMethods,
        string $method,
        string $path,
    ): MethodNotAllowedException|RouteConstraintException|RouteNotFoundException {
        $constraintFailure = null;
        $allowed = [];
        $candidates = $this->radix->candidates($path, false, $this->constraints);
        foreach ($candidates as $index) {
            $route = $routes[$index];
            $allowed[$route['method']] = true;
            if ($route['method'] === 'GET') {
                $allowed['HEAD'] = true;
            }
            $constraintFailure ??= $this->constraintFailure(
                $route,
                $effectiveMethods,
                $path,
            );
        }
        if ($constraintFailure instanceof RouteConstraintException) {
            return $constraintFailure;
        }
        if ($allowed !== []) {
            return new MethodNotAllowedException($method, $path, array_keys($allowed));
        }

        return new RouteNotFoundException($method, $path);
    }

    /**
     * @param RouteRecord $route
     * @param list<string> $effectiveMethods
     */
    private function constraintFailure(
        array $route,
        array $effectiveMethods,
        string $path,
    ): ?RouteConstraintException {
        $failure = null;
        foreach ($effectiveMethods as $effectiveMethod) {
            if ($route['method'] !== $effectiveMethod) {
                continue;
            }
            $params = RoutePatternParser::matchRoute($route['segments'], $path, $this->constraints);
            if ($params instanceof RouteConstraintException) {
                $failure = $params;
            }
        }

        return $failure;
    }
}
