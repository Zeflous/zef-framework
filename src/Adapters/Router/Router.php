<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Validation\HttpMethodValidator;
use Zef\Framework\Validation\RouteConstraintValidator;

final class Router
{
    /**
     * @var list<array{method:string,pattern:string,handler:string,module:?string,priority:int,sequence:int,segments:list<array{dynamic:bool,name?:string,constraint?:null|string,value?:string}>,signature:string,staticCount:int,constrainedCount:int,name:?string}>
     */
    private array $routes = [];

    /**
     * @var array<string,string> signature => pattern (for O(1) collision detection)
     */
    private array $signatureIndex = [];

    /**
     * @var array<string,string> name => pattern (reverse routing, v2.8.0)
     */
    private array $nameIndex = [];
    private int $sequence = 0;
    private bool $frozen = false;
    private bool $sorted = true;
    private int $maxRoutesBudget = 10000;
    private ?string $fallbackHandler = null;

    /**
     * v2.10.0: nested group attribute stack (prefix/name/middleware/priority).
     */
    private array $groupStack = [];

    /**
     * Immutable-after-freeze compiled radix index.
     *
     * @var list<array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>}>
     */
    private array $radix = [
        ['static' => [], 'dynamic' => [], 'routes' => []],
    ];

    public function __construct(
        private readonly RouteConstraintValidator $constraints = new RouteConstraintValidator(),
        ?ArchitecturePolicy $policy = null,
    ) {
        if ($policy instanceof ArchitecturePolicy) {
            $this->maxRoutesBudget = $policy->maxRouteRegistrations;
        }
    }

    public function setMaxRoutesBudget(int $max): void
    {
        if ($this->frozen) {
            throw new \LogicException('Router is frozen.');
        }
        if ($max < 1) {
            throw new \InvalidArgumentException('Route budget must be >= 1.');
        }
        if (count($this->routes) > $max) {
            throw new \InvalidArgumentException('Route budget cannot be lower than current route count.');
        }
        $this->maxRoutesBudget = $max;
    }

    public function getMaxRoutesBudget(): int
    {
        return $this->maxRoutesBudget;
    }

    /** v2.10.0: freeze-state introspection (mirrors Container::isFrozen()). */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function add(
        string $method,
        string $pattern,
        string $handlerService,
        ?string $module = null,
        int $priority = 0,
        ?string $name = null,
    ): void {
        if ($this->frozen) {
            throw new \LogicException('Router is frozen.');
        }
        if (count($this->routes) >= $this->maxRoutesBudget) {
            throw new InvalidConfigurationException("Router safety budget exceeded: maximum {$this->maxRoutesBudget} route registrations allowed.");
        }
        $method = strtoupper(trim($method));
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new \InvalidArgumentException("Route path '{$pattern}' must begin with '/'.");
        }
        HttpMethodValidator::assert($method);

        // v2.10.0: merge enclosing group prefix into the pattern and the name
        // prefix into the name, so parsing/signatures/collisions all see the
        // final wire form.
        if ($this->groupStack !== []) {
            $top = $this->groupStack[count($this->groupStack) - 1];
            if ($top['prefix'] !== '') {
                $pattern = $top['prefix'] . $pattern;
            }
            if ($name !== null && $top['name'] !== '') {
                $name = $top['name'] . $name;
            }
        }

        $segments = $this->parsePattern($pattern);
        $names = [];
        foreach ($segments as $segment) {
            if (!$segment['dynamic']) {
                continue;
            }
            if (isset($names[$segment['name']])) {
                throw new \InvalidArgumentException("Duplicate route parameter '{$segment['name']}'.");
            }
            $names[$segment['name']] = true;
            if ($segment['constraint'] !== null) {
                $this->constraints->assertKnown($segment['constraint']);
            }
        }

        $signature = $this->canonicalSignature($method, $segments);

        // Bug fix #15: O(1) collision detection via signatureIndex map.
        if (isset($this->signatureIndex[$signature])) {
            throw new \InvalidArgumentException("Duplicate/unreachable route [{$method}] {$pattern}; it collides with {$this->signatureIndex[$signature]}.");
        }

        $staticCount = 0;
        $constrainedCount = 0;
        foreach ($segments as $segment) {
            if (!$segment['dynamic']) {
                ++$staticCount;
            } elseif ($segment['constraint'] !== null) {
                ++$constrainedCount;
            }
        }

        // v2.10.0: apply group attributes. The innermost group already
        // carries the merged parent attributes — take the top only.
        if ($this->groupStack !== []) {
            $top = $this->groupStack[count($this->groupStack) - 1];
            $middleware = $top['middleware'];
            $groupPriority = $top['priority'];
        } else {
            $middleware = [];
            $groupPriority = null;
        }

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handlerService,
            'module' => $module,
            'priority' => $priority + ($groupPriority ?? 0),
            'sequence' => $this->sequence++,
            'segments' => $segments,
            'signature' => $signature,
            'staticCount' => $staticCount,
            'constrainedCount' => $constrainedCount,
            'name' => $name,
            'middleware' => $middleware,
        ];

        $this->signatureIndex[$signature] = $pattern;
        if ($name !== null) {
            $trimmedName = trim($name);
            if (isset($this->nameIndex[$trimmedName])) {
                throw new \InvalidArgumentException("Duplicate route name '{$trimmedName}'; already bound to {$this->nameIndex[$trimmedName]}.");
            }
            $this->nameIndex[$trimmedName] = $pattern;
        }
        $this->sorted = false;
    }

    /**
     * v2.10.0: register routes under shared attributes.
     *
     * Attributes: 'prefix' (string starting with '/'), 'name' (route-name
     * prefix), 'middleware' (list<string> service IDs), 'priority' (int added
     * to each route's own priority). Nested groups merge attributes.
     *
     * @param array{prefix?:string,name?:string,middleware?:list<string>,priority?:int} $attributes
     */
    public function group(array $attributes, callable $routes): void
    {
        if ($this->frozen) {
            throw new \LogicException('Router is frozen.');
        }
        $prefix = $attributes['prefix'] ?? '';
        if (!is_string($prefix) || ($prefix !== '' && ($prefix[0] !== '/' || str_ends_with($prefix, '/')))) {
            throw new \InvalidArgumentException("Route group prefix must start with '/' and not end with '/' (got '{$prefix}').");
        }
        $namePrefix = $attributes['name'] ?? '';
        if (!is_string($namePrefix)) {
            throw new \InvalidArgumentException('Route group name prefix must be a string.');
        }
        $middleware = $attributes['middleware'] ?? [];
        if (!is_array($middleware)) {
            throw new \InvalidArgumentException('Route group middleware must be a list of service IDs.');
        }
        foreach ($middleware as $mw) {
            if (!is_string($mw) || $mw === '') {
                throw new \InvalidArgumentException('Route group middleware entries must be non-empty service IDs.');
            }
        }
        $priority = $attributes['priority'] ?? null;
        if ($priority !== null && !is_int($priority)) {
            throw new \InvalidArgumentException('Route group priority must be an int or null.');
        }

        // Merge with the enclosing group (nested groups concatenate).
        $parent = $this->groupStack === []
            ? ['prefix' => '', 'name' => '', 'middleware' => [], 'priority' => null]
            : $this->groupStack[count($this->groupStack) - 1];
        $this->groupStack[] = [
            'prefix' => $parent['prefix'] . $prefix,
            'name' => $parent['name'] . $namePrefix,
            'middleware' => array_merge($parent['middleware'], $middleware),
            'priority' => $priority === null ? $parent['priority'] : ($parent['priority'] ?? 0) + $priority,
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    public function addConstraint(string $name, string $regex): void
    {
        if ($this->frozen) {
            throw new \LogicException('Router is frozen.');
        }
        $this->constraints->addCustom($name, $regex);
    }

    /**
     * Shared constraint validator (exposed for reverse routing tooling
     * such as UrlGenerator, so custom constraints resolve consistently).
     */
    public function constraintValidator(): RouteConstraintValidator
    {
        return $this->constraints;
    }

    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }
        $this->sortRoutes();
        $this->compileRadix();
        $this->frozen = true;
    }

    public function getRoutes(): array
    {
        $this->sortRoutes();

        return $this->routes;
    }

    /**
     * Reverse routing: pattern previously bound to a route name.
     *
     * @throws \InvalidArgumentException when the name is unknown
     */
    public function patternFor(string $name): string
    {
        $name = trim($name);

        return $this->nameIndex[$name]
            ?? throw new \InvalidArgumentException("Unknown route name '{$name}'.");
    }

    public function hasRouteName(string $name): bool
    {
        return isset($this->nameIndex[trim($name)]);
    }

    /** @return array<string,string> name => pattern */
    public function routeNames(): array
    {
        return $this->nameIndex;
    }

    /**
     * @return array{handler:string,module:?string,params:array<string,string>,pattern:string}
     */
    public function match(string $method, string $path): array
    {
        $this->sortRoutes();
        $method = strtoupper(trim($method));
        $effectiveMethods = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];

        // Fast path: constraint-aware radix traversal.
        $constraintCandidates = $this->radixCandidates($path, true);
        foreach ($effectiveMethods as $effectiveMethod) {
            foreach ($constraintCandidates as $index) {
                $route = $this->routes[$index];
                if ($route['method'] !== $effectiveMethod) {
                    continue;
                }
                $params = $this->matchRoute($route['segments'], $path);
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

        // Slow/failure path: structural candidates for 400/405 semantics.
        $constraintFailure = null;
        $allowed = [];
        $candidates = $this->radixCandidates($path, false);
        foreach ($candidates as $index) {
            $route = $this->routes[$index];
            $allowed[$route['method']] = true;
            if ($route['method'] === 'GET') {
                $allowed['HEAD'] = true;
            }
            foreach ($effectiveMethods as $effectiveMethod) {
                if ($route['method'] !== $effectiveMethod) {
                    continue;
                }
                $params = $this->matchRoute($route['segments'], $path);
                if ($params instanceof RouteConstraintException) {
                    $constraintFailure ??= $params;
                }
            }
        }

        if ($constraintFailure instanceof RouteConstraintException) {
            throw $constraintFailure;
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedException($method, $path, array_keys($allowed));
        }

        throw new RouteNotFoundException($method, $path);
    }

    // ---------------------------------------------------------------------
    // v2.10.0 — Fallback routes & compiled route caching (additive).
    // ---------------------------------------------------------------------

    /** Register a custom 404 handler service ID for matchOrFallback(). */
    public function fallback(string $handlerService): void
    {
        if ($this->frozen) {
            throw new \LogicException('Router is frozen.');
        }
        if ($handlerService === '') {
            throw new \InvalidArgumentException('Fallback handler service ID must not be empty.');
        }
        $this->fallbackHandler = $handlerService;
    }

    public function hasFallback(): bool
    {
        return $this->fallbackHandler !== null;
    }

    /**
     * Like match(), but an unmatched path (RouteNotFoundException) resolves
     * to the registered fallback handler instead of throwing. Method-not-
     * allowed (405) and constraint (400) semantics are preserved — the
     * fallback only covers "no route matched this path at all".
     *
     * @return array{handler:string,module:?string,params:array<string,string>,pattern:string,fallback:bool}
     */
    public function matchOrFallback(string $method, string $path): array
    {
        try {
            $hit = $this->match($method, $path);
            $hit['fallback'] = false;

            return $hit;
        } catch (RouteNotFoundException $notFound) {
            if ($this->fallbackHandler === null) {
                throw $notFound;
            }

            return [
                'handler' => $this->fallbackHandler,
                'module' => null,
                'params' => [],
                'pattern' => '*fallback*',
                'fallback' => true,
            ];
        }
    }

    /**
     * Pure-data snapshot for route caching (var_export-safe: only arrays,
     * strings, ints, bools and nulls — middleware entries are service IDs).
     *
     * @return array{routes:list<array<string,mixed>>,signatureIndex:array<string,string>,nameIndex:array<string,string>,sequence:int,constraints:array<string,string>,fallback:?string,maxRoutesBudget:int}
     */
    public function exportRoutes(): array
    {
        $this->sortRoutes();

        return [
            'routes' => $this->routes,
            'signatureIndex' => $this->signatureIndex,
            'nameIndex' => $this->nameIndex,
            'sequence' => $this->sequence,
            'constraints' => $this->constraints->customConstraints(),
            'fallback' => $this->fallbackHandler,
            'maxRoutesBudget' => $this->maxRoutesBudget,
        ];
    }

    /**
     * Restore a router from exportRoutes() output (typically loaded from a
     * compiled cache file). The result is pre-sorted and immediately frozen:
     * the radix index is built once, with zero per-route validation (routes
     * were validated when they were first registered).
     */
    public static function fromCompiledArray(array $data): Router
    {
        $routes = $data['routes'] ?? null;
        if (!is_array($routes)) {
            throw new \InvalidArgumentException('Compiled route data is missing the routes list.');
        }
        $router = new Router();
        $router->maxRoutesBudget = max(1, (int) ($data['maxRoutesBudget'] ?? count($routes)));
        foreach (($data['constraints'] ?? []) as $name => $regex) {
            if (is_string($name) && is_string($regex)) {
                $router->constraints->addCustom($name, $regex);
            }
        }
        $router->routes = array_values($routes);
        $signatureIndex = $data['signatureIndex'] ?? null;
        $router->signatureIndex = is_array($signatureIndex) ? $signatureIndex : [];
        $nameIndex = $data['nameIndex'] ?? null;
        $router->nameIndex = is_array($nameIndex) ? $nameIndex : [];
        $router->sequence = (int) ($data['sequence'] ?? count($routes));
        $fallback = $data['fallback'] ?? null;
        $router->fallbackHandler = is_string($fallback) && $fallback !== '' ? $fallback : null;
        $router->sorted = true;
        $router->freeze(); // rebuilds the radix index once

        return $router;
    }

    private function sortRoutes(): void
    {
        if ($this->sorted) {
            return;
        }
        usort(
            $this->routes,
            static function (array $a, array $b): int {
                foreach (['priority', 'staticCount', 'constrainedCount'] as $field) {
                    $cmp = $b[$field] <=> $a[$field];
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                return $a['sequence'] <=> $b['sequence'];
            },
        );
        $this->sorted = true;
    }

    private function compileRadix(): void
    {
        $this->radix = [['static' => [], 'dynamic' => [], 'routes' => []]];
        foreach ($this->routes as $index => $route) {
            $nodeIndex = 0;
            foreach ($route['segments'] as $segment) {
                if ($segment['dynamic']) {
                    $edgeKey = $segment['constraint'] ?? '';
                    $this->radix[$nodeIndex]['dynamic'][$edgeKey] ??= $this->newRadixNode();
                    $nodeIndex = $this->radix[$nodeIndex]['dynamic'][$edgeKey];

                    continue;
                }
                $edgeKey = $segment['value'];
                $this->radix[$nodeIndex]['static'][$edgeKey] ??= $this->newRadixNode();
                $nodeIndex = $this->radix[$nodeIndex]['static'][$edgeKey];
            }
            $this->radix[$nodeIndex]['routes'][] = $index;
        }
    }

    private function newRadixNode(): int
    {
        $index = count($this->radix);
        $this->radix[] = ['static' => [], 'dynamic' => [], 'routes' => []];

        return $index;
    }

    /**
     * Merged radixCandidates / radixMatchingCandidates into one method.
     * When $applyConstraints=true, dynamic edges are pruned by constraint.
     *
     * @return list<int>
     */
    private function radixCandidates(string $path, bool $applyConstraints): array
    {
        if (!$this->frozen) {
            $this->compileRadix();
        }
        $parts = $this->splitPath($path);
        $frontier = [0];
        foreach ($parts as $part) {
            $next = [];
            foreach ($frontier as $nodeIndex) {
                $staticChild = $this->radix[$nodeIndex]['static'][$part] ?? null;
                if ($staticChild !== null) {
                    $next[$staticChild] = true;
                }
                foreach ($this->radix[$nodeIndex]['dynamic'] as $constraint => $dynamicChild) {
                    if (
                        $applyConstraints
                        && $constraint !== ''
                        && !$this->constraints->test('_', $constraint, rawurldecode($part))
                    ) {
                        continue;
                    }
                    $next[$dynamicChild] = true;
                }
            }
            if ($next === []) {
                return [];
            }
            $frontier = array_map(intval(...), array_keys($next));
        }
        $candidates = [];
        foreach ($frontier as $nodeIndex) {
            foreach ($this->radix[$nodeIndex]['routes'] as $routeIndex) {
                $candidates[$routeIndex] = true;
            }
        }
        if ($candidates === []) {
            return [];
        }
        $candidates = array_map(intval(...), array_keys($candidates));
        sort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    /** @return list<string> */
    private function splitPath(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        return explode('/', trim($path, '/'));
    }

    private function parsePattern(string $pattern): array
    {
        $parts = $pattern === '/' ? [] : explode('/', trim($pattern, '/'));

        return array_map(
            static function (string $segment): array {
                if (
                    preg_match(
                        '/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}$/',
                        $segment,
                        $matches,
                    ) === 1
                ) {
                    return [
                        'dynamic' => true,
                        'name' => $matches[1],
                        'constraint' => $matches[2] ?? null,
                    ];
                }
                if (str_contains($segment, '{') || str_contains($segment, '}')) {
                    throw new \InvalidArgumentException("Invalid route segment '{$segment}'.");
                }

                return ['dynamic' => false, 'value' => $segment];
            },
            $parts,
        );
    }

    private function canonicalSignature(string $method, array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $segment['dynamic']
                ? '*' . ($segment['constraint'] ?? '')
                : $segment['value'];
        }

        return $method . '|/' . implode('/', $parts);
    }

    private function matchRoute(array $segments, string $path): array|false|RouteConstraintException
    {
        $pathParts = $this->splitPath($path);
        if (count($segments) !== count($pathParts)) {
            return false;
        }
        $params = [];
        foreach ($segments as $index => $segment) {
            $value = $pathParts[$index] ?? '';
            if ($segment['dynamic']) {
                // ZEF-DEEP-04: a dynamic segment carries a percent-encoded
                // value — decode it BEFORE the constraint test and BEFORE it
                // reaches the handler attributes, so `GET /users/%31%32%33`
                // satisfies `{id:int}` and `GET /users/john%20doe` yields
                // "john doe". rawurldecode() (not urldecode()) keeps "+" a
                // literal plus: "+" only means space in query strings. This
                // restores the round-trip symmetry with UrlGenerator, which
                // rawurlencode()s every dynamic value. Static segments keep
                // comparing against the raw path text below.
                $decoded = rawurldecode($value);
                if (
                    $segment['constraint'] !== null
                    && !$this->constraints->test($segment['name'], $segment['constraint'], $decoded)
                ) {
                    return new RouteConstraintException(
                        $segment['name'],
                        $segment['constraint'],
                        $decoded,
                    );
                }
                $params[$segment['name']] = $decoded;

                continue;
            }
            if ($segment['value'] !== $value) {
                return false;
            }
        }

        return $params;
    }
}
