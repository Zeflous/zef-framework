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
 * @phpstan-import-type RouteRecord from RouteCollection
 */
final class RouteRadixIndex
{
    /**
     * @var list<array{static:array<string,int>,dynamic:array<string,int>,routes:list<int>}>
     */
    private array $nodes = [
        ['static' => [], 'dynamic' => [], 'routes' => []],
    ];

    /**
     * Rebuilds the index from the (sorted) route records. Called once at
     * freeze() time, and on the fly for every match() while unfrozen.
     *
     * @param list<RouteRecord> $routes
     */
    public function compile(array $routes): void
    {
        $this->nodes = [['static' => [], 'dynamic' => [], 'routes' => []]];
        foreach ($routes as $index => $route) {
            $nodeIndex = 0;
            foreach ($route['segments'] as $segment) {
                if ($segment['dynamic']) {
                    $edgeKey = $segment['constraint'] ?? '';
                    $this->nodes[$nodeIndex]['dynamic'][$edgeKey] ??= $this->newRadixNode();
                    $nodeIndex = $this->nodes[$nodeIndex]['dynamic'][$edgeKey];

                    continue;
                }
                $edgeKey = $segment['value'];
                $this->nodes[$nodeIndex]['static'][$edgeKey] ??= $this->newRadixNode();
                $nodeIndex = $this->nodes[$nodeIndex]['static'][$edgeKey];
            }
            $this->nodes[$nodeIndex]['routes'][] = $index;
        }
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
            $staticChild = $this->nodes[$nodeIndex]['static'][$part] ?? null;
            if ($staticChild !== null) {
                $next[$staticChild] = true;
            }
            foreach ($this->nodes[$nodeIndex]['dynamic'] as $constraint => $dynamicChild) {
                if (
                    $applyConstraints
                    && $constraint !== ''
                    && !$constraints->test('_', $constraint, rawurldecode($part))
                ) {
                    continue;
                }
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
            foreach ($this->nodes[$nodeIndex]['routes'] as $routeIndex) {
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

    private function newRadixNode(): int
    {
        $index = count($this->nodes);
        $this->nodes[] = ['static' => [], 'dynamic' => [], 'routes' => []];

        return $index;
    }
}
