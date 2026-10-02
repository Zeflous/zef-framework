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
 * v2.36.0 (router feature-expansion): the fast path is host-aware (a
 * route may be bound to a subdomain pattern) and the hit now carries the
 * matched route's `middleware`, `host`, `bindings` and `accepts` metadata
 * so the dispatcher can enforce per-route middleware and negotiation —
 * previously that data existed on the record but was dropped at match
 * time. The failure path is untouched: host mismatch is a plain miss,
 * never a 400.
 *
 * @phpstan-type RouteRecord array{
 *     method: string, pattern: string, handler: string, module: ?string, priority: int, sequence: int,
 *     segments: list<array{dynamic:true,name:string,constraint?:string|null}|array{dynamic:false,value:string}>,
 *     signature: string, staticCount: int, constrainedCount: int,
 *     name: ?string, middleware: list<string>, host: string,
 *     bindings: array<string,string>, accepts: list<string>,
 * }
 *
 * NOTE: this alias is declared LOCALLY (a verbatim copy of the RouteCollection
 * declaration) instead of `@phpstan-import-type` — deptrac's docblock analyser
 * resolves cross-file alias imports as pseudo class-refs that surface as
 * "uncovered" (see the pre-campaign SpecArray precedent: declare-and-use
 * locally). Keep the copy in sync with RouteCollection's declaration.
 */
final readonly class RouteMatcher
{
    public function __construct(
        private RouteCollection $collection,
        private RouteConstraintValidator $constraints,
        private RouteRadixIndex $radix,
    ) {}

    /**
     * @return array{
     *     handler:string,module:?string,params:array<string,string>,pattern:string,
     *     middleware:list<string>,host:string,bindings:array<string,string>,accepts:list<string>,
     * }
     */
    public function match(string $method, string $path, bool $frozen, string $host = ''): array
    {
        $routes = $this->collection->sortedRoutes();
        $method = strtoupper(trim($method));
        $effectiveMethods = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        if (!$frozen) {
            // v2.36.0: rebuild only when a route has been registered since
            // the last compile — an unfrozen match() no longer pays the
            // full rebuild on every call.
            $this->radix->compileIfStale($routes, $this->collection->revision());
        }

        $hit = $this->constraintAwareHit($routes, $effectiveMethods, $path, $host);
        if ($hit !== null) {
            return $hit;
        }

        throw $this->failureException($routes, $effectiveMethods, $method, $path, $host);
    }

    /**
     * Like match(), but an unmatched path (RouteNotFoundException)
     * resolves to the registered fallback handler instead of throwing.
     * Method-not-allowed (405) and constraint (400) semantics are
     * preserved — the fallback only covers "no route matched this path".
     *
     * @return array{
     *     handler:string,module:?string,params:array<string,string>,pattern:string,fallback:bool,
     *     middleware:list<string>,host:string,bindings:array<string,string>,accepts:list<string>,
     * }
     */
    public function matchOrFallback(
        string $method,
        string $path,
        bool $frozen,
        ?string $fallbackHandler,
        string $host = '',
    ): array
    {
        try {
            $hit = $this->match($method, $path, $frozen, $host);
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
                'middleware' => [],
                'host' => '',
                'bindings' => [],
                'accepts' => [],
            ];
        }
    }

    /**
     * Fast path: constraint-aware radix traversal (moved from Router).
     *
     * @param list<RouteRecord> $routes
     * @param list<string> $effectiveMethods
     *
     * @return null|array{
     *     handler:string,module:?string,params:array<string,string>,pattern:string,
     *     middleware:list<string>,host:string,bindings:array<string,string>,accepts:list<string>,
     * }
     */
    private function constraintAwareHit(array $routes, array $effectiveMethods, string $path, string $host): ?array
    {
        $candidates = $this->radix->candidates($path, true, $this->constraints);
        foreach ($effectiveMethods as $effectiveMethod) {
            foreach ($candidates as $index) {
                $route = $routes[$index];
                if ($route['method'] !== $effectiveMethod) {
                    continue;
                }
                $routeHost = is_string($route['host'] ?? null) ? $route['host'] : '';
                $params = RoutePatternParser::matchHost(
                    $route['segments'],
                    $path,
                    $this->constraints,
                    $routeHost,
                    $host,
                );
                if ($params === false || $params instanceof RouteConstraintException) {
                    continue;
                }

                return [
                    'handler' => $route['handler'],
                    'module' => $route['module'],
                    'params' => $params,
                    'pattern' => $route['pattern'],
                    'middleware' => $route['middleware'] ?? [],
                    'host' => $routeHost,
                    'bindings' => $route['bindings'] ?? [],
                    'accepts' => $route['accepts'] ?? [],
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
        string $host,
    ): MethodNotAllowedException|RouteConstraintException|RouteNotFoundException {
        $constraintFailure = null;
        $allowed = [];
        $candidates = $this->radix->candidates($path, false, $this->constraints);
        foreach ($candidates as $index) {
            $route = $routes[$index];
            // v2.36.0: a route bound to another host is not a candidate for
            // THIS host — it must not contribute to the 405 method list nor
            // raise a 400 constraint, otherwise a subdomain-only path would
            // answer 405 instead of 404 for the wrong host.
            $routeHost = is_string($route['host'] ?? null) ? $route['host'] : '';
            if ($routeHost !== '' && HostPatternMatches::match($routeHost, $host) === null) {
                continue;
            }
            // @infection-ignore-all TrueValue — ekuivalen: $allowed hanya dibaca lewat array_keys(); nilai tidak
            // relevan
            $allowed[$route['method']] = true;
            if ($route['method'] === 'GET') {
                // @infection-ignore-all TrueValue — ekuivalen: $allowed hanya dibaca lewat array_keys(); nilai tidak
                // relevan
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
