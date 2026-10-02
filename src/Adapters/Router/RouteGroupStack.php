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
 * multi-tenancy / binding / negotiation attributes — `host` (subdomain
 * pattern; nesting merges under the parent suffix), `bindings`
 * (param => binder service id) and `accepts` (representation list). All
 * three are inherited and merged exactly like prefix/name/middleware.
 * Entries are {@see GroupAttributes} value objects. Localized routes are
 * composed by Router::localized() through an ordinary prefix group.
 */
final class RouteGroupStack
{
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
        if ($top->prefix !== '') {
            $pattern = $top->prefix . $pattern;
        }
        if ($name !== null && $top->namePrefix !== '') {
            $name = $top->namePrefix . $name;
        }

        return ['pattern' => $pattern, 'name' => $name, 'host' => $top->host];
    }

    /**
     * Effective group attributes for a route registered right now: the
     * innermost group already carries the merged parent attributes —
     * take the top only.
     *
     * @return array{
     *     middleware:list<string>, priority:?int, host:string,
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
                'bindings' => [],
                'accepts' => [],
            ];
        }
        $top = $this->stack[count($this->stack) - 1];

        return [
            'middleware' => $top->middleware,
            'priority' => $top->priority,
            'host' => $top->host,
            'bindings' => $top->bindings,
            'accepts' => $top->accepts,
        ];
    }

    /**
     * Validates raw group attributes from Router::group() and pushes the
     * merged entry. Nested groups merge attributes (and may fail closed on
     * contradictory host claims).
     *
     * @param array{
     *     prefix?:string,name?:string,middleware?:list<string>,priority?:int,
     *     host?:string,bindings?:array<string,string>,accepts?:list<string>,
     * } $attributes
     */
    public function pushAttributes(array $attributes): void
    {
        $parent = $this->stack === [] ? GroupAttributes::empty() : $this->stack[count($this->stack) - 1];
        $this->push(new GroupAttributes(
            prefix: $this->prefix($attributes['prefix'] ?? ''),
            namePrefix: $this->string($attributes['name'] ?? '', 'Route group name prefix must be a string.'),
            middleware: $this->middleware($attributes['middleware'] ?? []),
            priority: $this->priority($attributes['priority'] ?? null),
            host: $this->host($attributes['host'] ?? ''),
            bindings: $this->bindings($attributes['bindings'] ?? []),
            accepts: $this->accepts($attributes['accepts'] ?? []),
        ), $parent);
    }

    /**
     * Pushes a validated group entry, merging it with the enclosing one.
     */
    public function push(GroupAttributes $group, ?GroupAttributes $parent = null): void
    {
        $parent ??= $this->stack === [] ? GroupAttributes::empty() : $this->stack[count($this->stack) - 1];
        $this->stack[] = $parent->mergedWith($group, $this->mergeHost($parent->host, $group->host));
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    private function prefix(mixed $prefix): string
    {
        if (
            !is_string($prefix)
            || ($prefix !== '' && ($prefix[0] !== '/' || str_ends_with($prefix, '/')))
        ) {
            throw new \InvalidArgumentException(
                "Route group prefix must start with '/' and not end with '/' (got '" . $this->asString($prefix) . "')."
            );
        }

        return $prefix;
    }

    private function string(mixed $value, string $message): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    /** @return list<string> */
    private function middleware(mixed $middleware): array
    {
        if (!is_array($middleware)) {
            throw new \InvalidArgumentException('Route group middleware must be a list of service IDs.');
        }
        $list = [];
        foreach ($middleware as $mw) {
            if (!is_string($mw) || $mw === '') {
                throw new \InvalidArgumentException('Route group middleware entries must be non-empty service IDs.');
            }
            $list[] = $mw;
        }

        return $list;
    }

    private function priority(mixed $priority): ?int
    {
        if ($priority !== null && !is_int($priority)) {
            throw new \InvalidArgumentException('Route group priority must be an int or null.');
        }

        return $priority;
    }

    private function host(mixed $host): string
    {
        if (!is_string($host)) {
            throw new \InvalidArgumentException('Route group host must be a string pattern.');
        }
        if ($host !== '') {
            HostPatternMatches::assertValidPattern($host);
        }

        return $host;
    }

    /** @return array<string,string> */
    private function bindings(mixed $bindings): array
    {
        if (!is_array($bindings)) {
            throw new \InvalidArgumentException('Route group bindings must map parameter names to binder service IDs.');
        }
        $list = [];
        foreach ($bindings as $param => $binder) {
            if (!is_string($param) || $param === '' || !is_string($binder) || $binder === '') {
                throw new \InvalidArgumentException(
                    'Route group bindings must map non-empty parameter names to non-empty binder service IDs.',
                );
            }
            $list[$param] = $binder;
        }

        return $list;
    }

    /** @return list<string> */
    private function accepts(mixed $accepts): array
    {
        if (!is_array($accepts)) {
            throw new \InvalidArgumentException('Route group accepts must be a list of media types.');
        }
        $list = [];
        foreach ($accepts as $representation) {
            if (!is_string($representation) || $representation === '' || !str_contains($representation, '/')) {
                throw new \InvalidArgumentException(
                    'Route group accepts entries must be non-empty media types (type/subtype).',
                );
            }
            $list[] = $representation;
        }

        return $list;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * Merges a child host pattern under the enclosing one.
     *
     * - parent unset  -> the child becomes the effective host (locked);
     * - child equals the parent -> reuse the parent;
     * - child is a strict suffix of the parent -> the child still applies,
     *   its own label count governs how many host labels must match (so a
     *   later `example.com` under `{tenant}.example.com` stays a valid,
     *   narrower claim);
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
        if (!$this->isHostSuffix($childHost, $parentHost)) {
            throw new \InvalidArgumentException(
                "Conflicting route host inside an enclosing host group: '{$parentHost}' cannot contain '{$childHost}'.",
            );
        }

        return $childHost;
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
