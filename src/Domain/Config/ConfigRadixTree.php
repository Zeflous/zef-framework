<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.1 (pattern queries over the configuration
 * tree: wildcard match, subtree iteration and longest template match, backed
 * by a segment radix index built once from the merged values).
 */

namespace Zef\Framework\Config;

/**
 * Segment radix index over a nested configuration tree — the query engine
 * behind {@see Config::query()}, {@see Config::subtree()} and
 * {@see Config::longestMatch()}.
 *
 * The index is built ONCE from the merged nested values (constructor) and is
 * immutable afterwards. Exact lookups stay on the hash-map path
 * ({@see DottedPaths}); the trie only accelerates pattern queries.
 *
 * Two complementary query semantics:
 * - `match()` is PATTERN-driven: a `*` pattern segment matches any single
 *   stored segment at that position, so `database.connections.*.host` fans
 *   out over every connection.
 * - `longestMatch()` is DATA-driven: stored `*` segments act as templates
 *   (`services.*.timeout = 30`) and the most specific template for a concrete
 *   key wins — fewest `*` segments first, then lexicographic key order.
 *
 * Storage mirrors {@see DottedPaths::leafPaths()} semantics exactly: any
 * non-empty array is traversed (lists included — their integer keys become
 * segments), while scalars, nulls and empty arrays are terminal leaves.
 *
 * @internal data structure; consumed through Config's query methods
 */
final readonly class ConfigRadixTree
{
    private ConfigRadixNode $root;

    /**
     * @param array<array-key,mixed> $values nested configuration tree
     */
    public function __construct(array $values)
    {
        $this->root = self::buildRoot($values);
    }

    /**
     * All paths matching the anchored wildcard pattern, path => value at the
     * matched path (leaf scalars, empty arrays, lists and subtree arrays
     * alike). `*` matches exactly ONE segment; it must be the whole segment
     * (`a.*.b`, never `a.x*.b`). Results are sorted by path for determinism.
     *
     * Examples:
     * - `database.connections.*.host` → every connection's host scalar
     * - `cache.*.driver` → every cache driver scalar
     * - `*` → every top-level path with its value
     *
     * @return array<string,mixed>
     */
    public function match(string $pattern): array
    {
        $segments = $this->parseSegments($pattern);
        if ($segments === []) {
            throw new \InvalidArgumentException('Config query pattern must not be empty.');
        }
        $out = [];
        $this->matchFrom($this->root, $segments, [], $out);
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Every leaf under the literal prefix, relative path => leaf value.
     * Lists unfold through their integer segments, so `subtree('list')` of
     * `['alpha', 'beta']` yields `['0' => 'alpha', '1' => 'beta']`. An empty
     * prefix returns the whole tree in relative form; a prefix that names a
     * leaf yields `[]` (nothing lives under it).
     *
     * @return array<string,mixed>
     */
    public function subtree(string $prefix): array
    {
        $node = $this->root;
        foreach ($this->parseSegments($prefix) as $segment) {
            if ($segment === '*') {
                throw new \InvalidArgumentException(
                    "Config subtree prefix '{$prefix}' must not contain wildcard segments."
                );
            }
            $next = $node->children[$segment] ?? null;
            if ($next === null) {
                return [];
            }
            $node = $next;
        }
        $out = [];
        $this->collectLeaves($node, [], $out);
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Resolve a concrete key through stored `*` templates: `services.*.timeout`
     * answers for `services.payment.timeout`, and a fully literal stored key
     * always beats a template covering the same position. Among templates with
     * the same specificity (equal `*` count) the lexicographically smallest
     * path wins. Returns null when nothing matches.
     */
    public function longestMatch(string $key): mixed
    {
        $segments = $this->parseSegments($key);
        if ($segments === []) {
            throw new \InvalidArgumentException('Config longest-match key must not be empty.');
        }
        $best = null;
        $this->longestFrom($this->root, $segments, 0, '', $best);

        return $best === null ? null : $best['value'];
    }

    /**
     * Builds the trie root (php:S2830): the node assembly lives in a named
     * factory so the constructor only assigns derived state.
     *
     * @param array<array-key,mixed> $values
     */
    private static function buildRoot(array $values): ConfigRadixNode
    {
        $children = [];
        foreach ($values as $key => $value) {
            $children[(string) $key] = is_array($value) && $value !== []
                ? self::buildNode($value)
                : new ConfigRadixNode(true, $value, []);
        }

        return new ConfigRadixNode(false, null, $children);
    }

    /**
     * @param array<array-key,mixed> $values
     */
    private static function buildNode(array $values): ConfigRadixNode
    {
        $children = [];
        foreach ($values as $key => $value) {
            $children[(string) $key] = is_array($value) && $value !== []
                ? self::buildNode($value)
                : new ConfigRadixNode(true, $value, []);
        }

        return new ConfigRadixNode(true, $values, $children);
    }

    /**
     * @param list<string> $segments
     * @param list<string> $path
     * @param array<string,mixed> $out
     */
    private function matchFrom(ConfigRadixNode $node, array $segments, array $path, array &$out): void
    {
        if ($segments === []) {
            if ($node->has) {
                $out[implode('.', $path)] = $node->value;
            }

            return;
        }
        $head = $segments[0];
        $tail = array_slice($segments, 1);
        if ($head === '*') {
            foreach ($node->children as $segment => $child) {
                $path[] = $segment;
                $this->matchFrom($child, $tail, $path, $out);
                array_pop($path);
            }

            return;
        }
        $child = $node->children[$head] ?? null;
        if ($child === null) {
            return;
        }
        $path[] = $head;
        $this->matchFrom($child, $tail, $path, $out);
    }

    /**
     * @param list<string> $path
     * @param array<string,mixed> $out
     */
    private function collectLeaves(ConfigRadixNode $node, array $path, array &$out): void
    {
        if ($node->children === []) {
            if ($path !== []) {
                $out[implode('.', $path)] = $node->value;
            }

            return;
        }
        foreach ($node->children as $segment => $child) {
            $path[] = $segment;
            $this->collectLeaves($child, $path, $out);
            array_pop($path);
        }
    }

    /**
     * @param list<string> $segments
     * @param null|array{wildcards:int,path:string,value:mixed} $best
     */
    private function longestFrom(
        ConfigRadixNode $node,
        array $segments,
        int $wildcards,
        string $path,
        ?array &$best,
    ): void {
        if ($segments === []) {
            if (!$node->has) {
                return;
            }
            if ($best === null
                || $wildcards < $best['wildcards']
                || ($wildcards === $best['wildcards'] && $path < $best['path'])) {
                $best = ['wildcards' => $wildcards, 'path' => $path, 'value' => $node->value];
            }

            return;
        }
        $head = $segments[0];
        $tail = array_slice($segments, 1);
        // Literal branch first (more specific), then the stored `*` template.
        $branches = [$head, '*'];
        if ($head === '*') {
            $branches = [$head];
        }
        foreach ($branches as $branch) {
            $child = $node->children[$branch] ?? null;
            if ($child === null) {
                continue;
            }
            $this->longestFrom(
                $child,
                $tail,
                $wildcards + ($branch === '*' ? 1 : 0),
                $path === '' ? $branch : $path . '.' . $branch,
                $best,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function parseSegments(string $path): array
    {
        if ($path === '') {
            return [];
        }
        $segments = explode('.', $path);
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException("Config path '{$path}' contains an empty segment.");
            }
        }

        return $segments;
    }
}
