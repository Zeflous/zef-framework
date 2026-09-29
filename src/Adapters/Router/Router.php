<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Validation\HttpMethodValidator;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * v2.10.0 route registry: registration, grouping, freezing, compiled
 * caching and request matching. The mechanical internals live in
 * dedicated collaborators — RoutePatternParser (segment codec),
 * RouteGroupStack (attribute nesting), RouteCollection (storage) and
 * RouteMatcher + RouteRadixIndex (matching); this class is the stable
 * public facade with the unchanged v2.7.0 surface.
 *
 * @phpstan-import-type RouteRecord from RouteCollection
 */
final class Router
{
    private const string MSG_FROZEN = 'Router is frozen.';

    private ?RouteMatcher $matcher = null;
    private ?string $fallbackHandler = null;
    private bool $frozen = false;

    public function __construct(
        private readonly RouteConstraintValidator $constraints = new RouteConstraintValidator(),
        ?ArchitecturePolicy $policy = null,
        private readonly RouteCollection $collection = new RouteCollection(),
        private readonly RouteGroupStack $groupStack = new RouteGroupStack(),
        private readonly RouteRadixIndex $radix = new RouteRadixIndex(),
    ) {
        if ($policy instanceof ArchitecturePolicy) {
            $this->collection->applyBudget($policy->maxRouteRegistrations);
        }
    }

    public function setMaxRoutesBudget(int $max): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::MSG_FROZEN);
        }
        $this->collection->setMaxRoutesBudget($max);
    }

    public function getMaxRoutesBudget(): int
    {
        return $this->collection->getMaxRoutesBudget();
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
            throw new \LogicException(self::MSG_FROZEN);
        }
        $this->collection->assertCapacity();
        $method = strtoupper(trim($method));
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new \InvalidArgumentException("Route path '{$pattern}' must begin with '/'.");
        }
        HttpMethodValidator::assert($method);

        // v2.10.0: merge enclosing group prefix into the pattern and the
        // name prefix into the name, so parsing/signatures/collisions all
        // see the final wire form.
        ['pattern' => $pattern, 'name' => $name] = $this->groupStack->applyTo($pattern, $name);
        $segments = RoutePatternParser::parsePattern($pattern);
        RoutePatternParser::assertUniqueParams($segments, $this->constraints);

        // v2.10.0: apply group attributes. The innermost group already
        // carries the merged parent attributes — take the top only.
        $group = $this->groupStack->currentAttributes();
        $this->collection->add([
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handlerService,
            'module' => $module,
            'priority' => $priority + ($group['priority'] ?? 0),
            'segments' => $segments,
            'signature' => RoutePatternParser::canonicalSignature($method, $segments),
            'name' => $name,
            'middleware' => $group['middleware'],
        ]);
    }

    /**
     * v2.10.0: register routes under shared attributes (validated and
     * merged by {@see RouteGroupStack::pushAttributes()}).
     *
     * @param array{prefix?:string,name?:string,middleware?:list<string>,priority?:int} $attributes
     */
    public function group(array $attributes, callable $routes): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::MSG_FROZEN);
        }

        $this->groupStack->pushAttributes($attributes);

        try {
            $routes($this);
        } finally {
            $this->groupStack->pop();
        }
    }

    public function addConstraint(string $name, string $regex): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::MSG_FROZEN);
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
        $this->radix->compile($this->collection->sortedRoutes());
        $this->frozen = true;
    }

    public function getRoutes(): array
    {
        return $this->collection->sortedRoutes();
    }

    /**
     * Reverse routing: pattern previously bound to a route name.
     *
     * @throws \InvalidArgumentException when the name is unknown
     */
    public function patternFor(string $name): string
    {
        return $this->collection->patternFor($name);
    }

    public function hasRouteName(string $name): bool
    {
        return $this->collection->hasRouteName($name);
    }

    /** @return array<string,string> name => pattern */
    public function routeNames(): array
    {
        return $this->collection->routeNames();
    }

    /**
     * @return array{handler:string,module:?string,params:array<string,string>,pattern:string}
     */
    public function match(string $method, string $path): array
    {
        return $this->matcher()->match($method, $path, $this->frozen);
    }

    // ---------------------------------------------------------------------
    // v2.10.0 — Fallback routes & compiled route caching (additive).
    // ---------------------------------------------------------------------

    /** Register a custom 404 handler service ID for matchOrFallback(). */
    public function fallback(string $handlerService): void
    {
        if ($this->frozen) {
            throw new \LogicException(self::MSG_FROZEN);
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
        return $this->matcher()->matchOrFallback($method, $path, $this->frozen, $this->fallbackHandler);
    }

    /**
     * Pure-data snapshot for route caching (var_export-safe: only arrays,
     * strings, ints, bools and nulls — middleware entries are service IDs).
     *
     * @return array{
     *     routes: list<RouteRecord>, signatureIndex: array<string,string>,
     *     nameIndex: array<string,string>, sequence: int, constraints: array<string,string>,
     *     fallback: ?string, maxRoutesBudget: int,
     * }
     */
    public function exportRoutes(): array
    {
        $data = $this->collection->export();
        $data['constraints'] = $this->constraints->customConstraints();
        $data['fallback'] = $this->fallbackHandler;
        $data['maxRoutesBudget'] = $this->collection->getMaxRoutesBudget();

        return $data;
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

        /** @var array<string,mixed> $payload */
        $payload = $data;
        $router->collection->hydrateFromCompiled($payload);
        foreach (($data['constraints'] ?? []) as $name => $regex) {
            if (is_string($name) && is_string($regex)) {
                $router->constraints->addCustom($name, $regex);
            }
        }
        $router->freeze(); // rebuilds the radix index once

        return $router;
    }

    private function matcher(): RouteMatcher
    {
        if ($this->matcher === null) {
            $this->matcher = new RouteMatcher($this->collection, $this->constraints, $this->radix);
        }

        return $this->matcher;
    }
}
