<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

/**
 * v2.10.0: nested group attribute stack (prefix/name/middleware/priority)
 * for the Router. Each entry carries the attributes MERGED with all
 * enclosing groups, so the innermost entry is always the effective one.
 *
 * @phpstan-type GroupAttributes array{prefix:string,name:string,middleware:list<string>,priority:?int}
 */
final class RouteGroupStack
{
    private const array EMPTY_ATTRIBUTES = ['prefix' => '', 'name' => '', 'middleware' => [], 'priority' => null];

    /** @var list<GroupAttributes> */
    private array $stack = [];

    /**
     * Merges an enclosing group's prefix into a route pattern and its
     * name prefix into the route name, so parsing, signatures and
     * collision detection all see the final wire form (moved from
     * Router::add()).
     *
     * @return array{pattern:string,name:?string}
     */
    public function applyTo(string $pattern, ?string $name): array
    {
        if ($this->stack === []) {
            return ['pattern' => $pattern, 'name' => $name];
        }
        $top = $this->stack[count($this->stack) - 1];
        if ($top['prefix'] !== '') {
            $pattern = $top['prefix'] . $pattern;
        }
        if ($name !== null && $top['name'] !== '') {
            $name = $top['name'] . $name;
        }

        return ['pattern' => $pattern, 'name' => $name];
    }

    /**
     * Effective group attributes for a route registered right now: the
     * innermost group already carries the merged parent attributes —
     * take the top only.
     *
     * @return array{middleware:list<string>,priority:?int}
     */
    public function currentAttributes(): array
    {
        if ($this->stack === []) {
            return ['middleware' => [], 'priority' => null];
        }
        $top = $this->stack[count($this->stack) - 1];

        return ['middleware' => $top['middleware'], 'priority' => $top['priority']];
    }

    /**
     * Validates raw group attributes from Router::group() (prefix, name
     * prefix, middleware list, priority) and pushes the merged entry.
     *
     * Attributes: 'prefix' (string starting with '/'), 'name' (route-name
     * prefix), 'middleware' (list<string> service IDs), 'priority' (int
     * added to each route's own priority). Nested groups merge attributes.
     *
     * @param array{prefix?:string,name?:string,middleware?:list<string>,priority?:int} $attributes
     */
    public function pushAttributes(array $attributes): void
    {
        $prefix = $attributes['prefix'] ?? '';
        if (
            !is_string($prefix)
            || ($prefix !== '' && ($prefix[0] !== '/' || str_ends_with($prefix, '/')))
        ) {
            throw new \InvalidArgumentException(
                "Route group prefix must start with '/' and not end with '/' (got '{$prefix}')."
            );
        }
        $namePrefix = $attributes['name'] ?? '';
        if (!is_string($namePrefix)) {
            throw new \InvalidArgumentException('Route group name prefix must be a string.');
        }
        $middleware = $attributes['middleware'] ?? [];
        if (!is_array($middleware)) {
            throw new \InvalidArgumentException('Route group middleware must be a list of service IDs.');
        }
        $middlewareList = [];
        foreach ($middleware as $mw) {
            if (!is_string($mw) || $mw === '') {
                throw new \InvalidArgumentException('Route group middleware entries must be non-empty service IDs.');
            }
            $middlewareList[] = $mw;
        }
        $priority = $attributes['priority'] ?? null;
        if ($priority !== null && !is_int($priority)) {
            throw new \InvalidArgumentException('Route group priority must be an int or null.');
        }

        $this->push($prefix, $namePrefix, $middlewareList, $priority);
    }

    /**
     * Pushes a validated group onto the stack, merging it with the
     * enclosing group (nested groups concatenate prefixes, name prefixes
     * and middleware; priority adds up).
     *
     * @param list<string> $middleware
     */
    public function push(string $prefix, string $namePrefix, array $middleware, ?int $priority): void
    {
        $parent = $this->stack === []
            ? self::EMPTY_ATTRIBUTES
            : $this->stack[count($this->stack) - 1];
        $this->stack[] = [
            'prefix' => $parent['prefix'] . $prefix,
            'name' => $parent['name'] . $namePrefix,
            'middleware' => array_merge($parent['middleware'], $middleware),
            'priority' => $priority === null ? $parent['priority'] : ($parent['priority'] ?? 0) + $priority,
        ];
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }
}
