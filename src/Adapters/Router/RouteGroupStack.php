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
 * v2.36.0 (router feature-expansion): the stack also carries the
 * multi-tenancy / localization / binding / negotiation attributes —
 * `host` (subdomain pattern, locked once set; nesting merges the child
 * labels under the parent suffix), `localePrefix`, `bindings` (param =>
 * binder service id) and `accepts` (representation list). All four are
 * inherited and merged exactly like prefix/name/middleware.
 *
 * @phpstan-type GroupAttributes array{
 *     prefix:string, name:string, middleware:list<string>, priority:?int,
 *     host:string, localePrefix:string, bindings:array<string,string>, accepts:list<string>,
 * }
 */
final class RouteGroupStack
{
    private const array EMPTY_ATTRIBUTES = [
        'prefix' => '',
        'name' => '',
        'middleware' => [],
        'priority' => null,
        'host' => '',
        'localePrefix' => '',
        'bindings' => [],
        'accepts' => [],
    ];

    /** @var list<GroupAttributes> */
    private array $stack = [];

    /** @var array<string,list<string>> host pattern => captured wildcard names */
    private array $lockedHosts = [];

    /**
     * Merges an enclosing group's prefix into a route pattern and its
     * name prefix into the route name, so parsing, signatures and
     * collision detection all see the final wire form (moved from
     * Router::add()).
     *
     * @return array{pattern:string,name:?string,host:string}
     */
    public function applyTo(string $pattern, ?string $name): array
    {
        if ($this->stack === []) {
            return ['pattern' => $pattern, 'name' => $name, 'host' => ''];
        }
        $top = $this->stack[count($this->stack) - 1];
        if ($top['prefix'] !== '') {
            $pattern = $top['prefix'] . $pattern;
        }
        if ($name !== null && $top['name'] !== '') {
            $name = $top['name'] . $name;
        }

        return ['pattern' => $pattern, 'name' => $name, 'host' => $top['host']];
    }

    /**
     * Effective group attributes for a route registered right now: the
     * innermost group already carries the merged parent attributes —
     * take the top only.
     *
     * @return array{
     *     middleware:list<string>, priority:?int, host:string, localePrefix:string,
     *     bindings:array<string,string>, accepts:list<string>,
     * }
     */
    public function currentAttributes(): array
    {
        if ($this->stack === []) {
            return [
                'middleware' => [],
                'priority' => null,
                'host' => '',
                'localePrefix' => '',
                'bindings' => [],
                'accepts' => [],
            ];
        }
        $top = $this->stack[count($this->stack) - 1];

        return [
            'middleware' => $top['middleware'],
            'priority' => $top['priority'],
            'host' => $top['host'],
            'localePrefix' => $top['localePrefix'],
            'bindings' => $top['bindings'],
            'accepts' => $top['accepts'],
        ];
    }

    /**
     * Validates raw group attributes from Router::group() (prefix, name
     * prefix, middleware list, priority, host, locale, bindings, accepts)
     * and pushes the merged entry.
     *
     * Attributes: 'prefix' (string starting with '/'), 'name' (route-name
     * prefix), 'middleware' (list<string> service IDs), 'priority' (int
     * added to each route's own priority), 'host' (subdomain pattern),
     * 'locale' (locale-prefix string), 'bindings' (param => binder service
     * id), 'accepts' (list<string> representations). Nested groups merge
     * attributes.
     *
     * @param array{
     *     prefix?:string,name?:string,middleware?:list<string>,priority?:int,
     *     host?:string,locale?:string,bindings?:array<string,string>,accepts?:list<string>,
     * } $attributes
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

        $host = $attributes['host'] ?? '';
        if (!is_string($host)) {
            throw new \InvalidArgumentException('Route group host must be a string pattern.');
        }
        if ($host !== '') {
            HostPatternMatches::assertValidPattern($host);
        }

        $localePrefix = $attributes['locale'] ?? '';
        if (!is_string($localePrefix)) {
            throw new \InvalidArgumentException('Route group locale must be a string.');
        }
        if ($localePrefix !== '' && preg_match('/^[A-Za-z]{1,8}(?:[_-][A-Za-z0-9]{1,8})?$/', $localePrefix) !== 1) {
            throw new \InvalidArgumentException(
                "Route group locale must be a well-formed language tag (got '{$localePrefix}').",
            );
        }

        $bindings = $attributes['bindings'] ?? [];
        if (!is_array($bindings)) {
            throw new \InvalidArgumentException('Route group bindings must map parameter names to binder service IDs.');
        }
        $bindingList = [];
        foreach ($bindings as $param => $binder) {
            if (!is_string($param) || $param === '' || !is_string($binder) || $binder === '') {
                throw new \InvalidArgumentException(
                    'Route group bindings must map non-empty parameter names to non-empty binder service IDs.',
                );
            }
            $bindingList[$param] = $binder;
        }

        $accepts = $attributes['accepts'] ?? [];
        if (!is_array($accepts)) {
            throw new \InvalidArgumentException('Route group accepts must be a list of media types.');
        }
        $acceptList = [];
        foreach ($accepts as $representation) {
            if (!is_string($representation) || $representation === '' || !str_contains($representation, '/')) {
                throw new \InvalidArgumentException(
                    'Route group accepts entries must be non-empty media types (type/subtype).',
                );
            }
            $acceptList[] = $representation;
        }

        $this->push($prefix, $namePrefix, $middlewareList, $priority, $host, $localePrefix, $bindingList, $acceptList);
    }

    /**
     * Pushes a validated group onto the stack, merging it with the
     * enclosing group (nested groups concatenate prefixes, name prefixes,
     * middleware, accept lists and bindings; priority adds up; a host
     * pattern locks once set and later groups merge under its suffix).
     *
     * @param list<string>          $middleware
     * @param array<string,string>  $bindings
     * @param list<string>          $accepts
     */
    public function push(
        string $prefix,
        string $namePrefix,
        array $middleware,
        ?int $priority,
        string $host = '',
        string $localePrefix = '',
        array $bindings = [],
        array $accepts = [],
    ): void {
        $parent = $this->stack === []
            ? self::EMPTY_ATTRIBUTES
            : $this->stack[count($this->stack) - 1];
        $this->stack[] = [
            'prefix' => $parent['prefix'] . $prefix,
            'name' => $parent['name'] . $namePrefix,
            'middleware' => array_merge($parent['middleware'], $middleware),
            'priority' => $priority === null ? $parent['priority'] : ($parent['priority'] ?? 0) + $priority,
            'host' => $this->mergeHost($parent['host'], $host),
            'localePrefix' => $localePrefix === '' ? $parent['localePrefix'] : $localePrefix,
            'bindings' => array_merge($parent['bindings'], $bindings),
            'accepts' => array_merge($parent['accepts'], $accepts),
        ];
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * Merges a child host pattern under the enclosing one.
     *
     * - parent unset  -> the child becomes the effective host (locked);
     * - child is a strict suffix of the parent (its labels end with the
     *   parent labels) -> the child still applies, its own label count
     *   governs how many host labels must match (so a later `example.com`
     *   under `{tenant}.example.com` stays a valid, narrower claim);
     * - a non-suffix child is a contradictory host claim and fails closed.
     */
    private function mergeHost(string $parentHost, string $childHost): string
    {
        if ($childHost === '') {
            return $parentHost;
        }
        if ($parentHost === '') {
            $this->lockHost($childHost);

            return $childHost;
        }
        if ($childHost === $parentHost) {
            return $parentHost;
        }
        if ($this->isHostSuffix($childHost, $parentHost)) {
            return $childHost;
        }

        throw new \InvalidArgumentException(
            "Conflicting route host inside an enclosing host group: '{$parentHost}' cannot contain '{$childHost}'.",
        );
    }

    /** True when $child's labels end with $parent's labels (label boundary). */
    private function isHostSuffix(string $child, string $parent): bool
    {
        $childLabels = explode('.', strtolower($child));
        $parentLabels = explode('.', strtolower($parent));
        if (count($parentLabels) > count($childLabels)) {
            return false;
        }

        return array_slice($childLabels, -count($parentLabels)) === $parentLabels;
    }

    /**
     * Records a host claim's wildcard names so two sibling groups over the
     * same pattern cannot bind the same wildcard to different meanings.
     */
    private function lockHost(string $host): void
    {
        $wildcards = HostPatternMatches::wildcardNames($host);
        if (isset($this->lockedHosts[$host]) && $this->lockedHosts[$host] !== $wildcards) {
            throw new \InvalidArgumentException(
                "Route host '{$host}' is declared with conflicting wildcard parameters.",
            );
        }
        $this->lockedHosts[$host] = $wildcards;
    }
}
