<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * Immutable-after-freeze compiled radix index over the registered route
 * records (extracted from Router): static and constraint-keyed dynamic
 * edges yield the candidate route indices for a request path.
 *
 * @phpstan-type RouteRecord array{
 *     method: string, pattern: string, handler: string, module: ?string, priority: int, sequence: int,
 *     segments: list<array{dynamic:true,name:string,constraint?:string|null}|array{dynamic:false,value:string}>,
 *     signature: string, staticCount: int, constrainedCount: int,
 *     name: ?string, middleware: list<string>,
 * }
 *
 * NOTE: this alias is declared LOCALLY (a verbatim copy of the RouteCollection
 * declaration) instead of `@phpstan-import-type` — deptrac's docblock analyser
 * resolves cross-file alias imports as pseudo class-refs that surface as
 * "uncovered" (see the pre-campaign SpecArray precedent: declare-and-use
 * locally). Keep the copy in sync with RouteCollection's declaration.
 */
final class RouteRadixIndex
{
    /** @var list<RadixNode> */
    private array $nodes = [];

    /**
     * v2.36.0: the route-collection revision this index was last compiled
     * from. An unfrozen match() can then skip the rebuild while nothing
     * has been registered since (the previous behaviour rebuilt on EVERY
     * unfrozen match, an O(routes x segments) cost per request).
     */
    private int $compiledRevision = -1;

    /**
     * Rebuilds the index from the (sorted) route records. Called once at
     * freeze() time, and on the fly for every match() while unfrozen.
     *
     * @param list<RouteRecord> $routes
     */
    public function compile(array $routes): void
    {
        $this->nodes = [new RadixNode()];
        foreach ($routes as $index => $route) {
            $nodeIndex = 0;
            foreach ($route['segments'] as $segment) {
                if ($segment['dynamic']) {
                    $nodeIndex = $this->descend($nodeIndex, $segment['constraint'] ?? '', true);

                    continue;
                }
                $nodeIndex = $this->descend($nodeIndex, $segment['value'], false);
            }
            $this->nodes[$nodeIndex]->routes[] = $index;
        }
    }

    /**
     * v2.36.0 revision-aware compile: rebuilds only when the collection
     * revision differs from the compiled one. `compile()` stays the
     * unconditional primitive (freeze() and fromCompiledArray() call it
     * directly); this is the idempotent fast path for unfrozen match().
     *
     * @param list<RouteRecord> $routes
     */
    public function compileIfStale(array $routes, int $revision): void
    {
        if ($this->compiledRevision === $revision) {
            return;
        }
        $this->compile($routes);
        $this->compiledRevision = $revision;
    }

    /**
     * Merged radixCandidates / radixMatchingCandidates (moved from
     * Router): walks the index along the request path. When
     * $applyConstraints is true, dynamic edges are pruned by constraint.
     *
     * @return list<int>
     */
    public function candidates(string $path, bool $applyConstraints, RouteConstraintValidator $constraints): array
    {
        $parts = RoutePatternParser::splitPath($path);
        $frontier = [0];
        foreach ($parts as $part) {
            $frontier = $this->advance($frontier, $part, $applyConstraints, $constraints);
            if ($frontier === []) {
                // @infection-ignore-all ReturnRemoval — ekuivalen: frontier kosong membuat loop berikutnya tidak
                // berjalan; hasil akhir tetap []
                return [];
            }
        }

        return $this->collectRouteCandidates($frontier);
    }

    /**
     * @param list<int> $frontier
     *
     * @return list<int>
     */
    private function advance(
        array $frontier,
        string $part,
        bool $applyConstraints,
        RouteConstraintValidator $constraints,
    ): array {
        $next = [];
        foreach ($frontier as $nodeIndex) {
            $node = $this->nodes[$nodeIndex];
            $staticChild = $node->static[$part] ?? null;
            if ($staticChild !== null) {
                $next[$staticChild] = true;
            }
            foreach ($node->dynamic as $constraint => $dynamicChild) {
                if (
                    $applyConstraints
                    && $constraint !== ''
                    && !$constraints->test('_', $constraint, rawurldecode($part))
                ) {
                    continue;
                }
                // @infection-ignore-all TrueValue — ekuivalen: $next hanya dibaca lewat array_keys(); nilai tidak
                // relevan
                $next[$dynamicChild] = true;
            }
        }

        return array_map(intval(...), array_keys($next));
    }

    /**
     * @param list<int> $frontier
     *
     * @return list<int>
     */
    private function collectRouteCandidates(array $frontier): array
    {
        $candidates = [];
        foreach ($frontier as $nodeIndex) {
            foreach ($this->nodes[$nodeIndex]->routes as $routeIndex) {
                $candidates[$routeIndex] = true;
            }
        }
        if ($candidates === []) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: $candidates kosong menghasilkan
            // array_map(array_keys([])) = [] yang identik
            return [];
        }
        $candidates = array_map(intval(...), array_keys($candidates));
        sort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    /**
     * Follows (creating when absent) the $edgeKey edge — static or
     * constraint-keyed dynamic — from the node at $nodeIndex and returns
     * the child node index.
     */
    private function descend(int $nodeIndex, string $edgeKey, bool $dynamic): int
    {
        $node = $this->nodes[$nodeIndex];
        $edges = $dynamic ? $node->dynamic : $node->static;
        if (isset($edges[$edgeKey])) {
            return $edges[$edgeKey];
        }
        $child = count($this->nodes);
        $this->nodes[] = new RadixNode();
        if ($dynamic) {
            $node->dynamic[$edgeKey] = $child;
        } else {
            $node->static[$edgeKey] = $child;
        }

        return $child;
    }
}
