<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Route storage for the Router: registration bookkeeping (budget,
 * signature collisions, name bindings), lazy priority sorting, compiled
 * cache export/hydration and reverse-routing lookups.
 *
 * @phpstan-import-type Segment from RoutePatternParser
 *
 * @phpstan-type RouteRecord array{
 *     method: string, pattern: string, handler: string, module: ?string, priority: int, sequence: int,
 *     segments: list<Segment>, signature: string, staticCount: int, constrainedCount: int,
 *     name: ?string, middleware: list<string>,
 * }
 */
final class RouteCollection
{
    /** @var list<RouteRecord> */
    private array $routes = [];

    /** @var array<string,string> signature => pattern (for O(1) collision detection) */
    private array $signatureIndex = [];

    /** @var array<string,string> name => pattern (reverse routing, v2.8.0) */
    private array $nameIndex = [];

    private int $sequence = 0;
    private bool $sorted = true;
    private int $maxRoutesBudget = 10000;

    /**
     * Fails fast when the next registration would exceed the router
     * safety budget (checked by Router::add() before any validation).
     */
    public function assertCapacity(): void
    {
        if (count($this->routes) >= $this->maxRoutesBudget) {
            throw new InvalidConfigurationException(
                "Router safety budget exceeded: maximum {$this->maxRoutesBudget} route registrations allowed."
            );
        }
    }

    /**
     * Registers a fully-validated route record (pattern, method and
     * segment-name checks happen in Router::add() and the pattern
     * parser; the budget check happens in assertCapacity()).
     *
     * @param array{
     *     method: string, pattern: string, handler: string, module: ?string, priority: int,
     *     segments: list<Segment>, signature: string, name: ?string, middleware: list<string>,
     * } $record
     */
    public function add(array $record): void
    {
        $signature = $record['signature'];
        if (isset($this->signatureIndex[$signature])) {
            $method = $record['method'];
            $pattern = $record['pattern'];
            $collision = $this->signatureIndex[$signature];

            throw new \InvalidArgumentException(
                "Duplicate/unreachable route [{$method}] {$pattern}; it collides with {$collision}."
            );
        }

        $staticCount = 0;
        $constrainedCount = 0;
        foreach ($record['segments'] as $segment) {
            if (!$segment['dynamic']) {
                ++$staticCount;
            } elseif (($segment['constraint'] ?? null) !== null) {
                ++$constrainedCount;
            }
            // Dynamic segment without a constraint: neither counter applies.
        }

        $sequence = $this->sequence;
        ++$this->sequence;
        $this->routes[] = [
            'method' => $record['method'],
            'pattern' => $record['pattern'],
            'handler' => $record['handler'],
            'module' => $record['module'],
            'priority' => $record['priority'],
            'sequence' => $sequence,
            'segments' => $record['segments'],
            'signature' => $signature,
            'staticCount' => $staticCount,
            'constrainedCount' => $constrainedCount,
            'name' => $record['name'],
            'middleware' => $record['middleware'],
        ];

        $this->signatureIndex[$signature] = $record['pattern'];

        $name = $record['name'];
        if ($name !== null) {
            $trimmedName = trim($name);
            if (isset($this->nameIndex[$trimmedName])) {
                throw new \InvalidArgumentException(
                    "Duplicate route name '{$trimmedName}'; already bound to {$this->nameIndex[$trimmedName]}."
                );
            }
            $this->nameIndex[$trimmedName] = $record['pattern'];
        }
        $this->sorted = false;
    }

    /** @return list<RouteRecord> */
    public function sortedRoutes(): array
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
     * Pure-data snapshot for route caching (var_export-safe: only arrays,
     * strings, ints, bools and nulls — middleware entries are service IDs).
     *
     * @return array{
     *     routes: list<RouteRecord>, signatureIndex: array<string,string>,
     *     nameIndex: array<string,string>, sequence: int,
     * }
     */
    public function export(): array
    {
        $this->sortRoutes();

        return [
            'routes' => $this->routes,
            'signatureIndex' => $this->signatureIndex,
            'nameIndex' => $this->nameIndex,
            'sequence' => $this->sequence,
        ];
    }

    /**
     * Restores storage state from a compiled cache payload written by
     * export() (trusted data: routes were validated when they were first
     * registered). The result is pre-sorted — no per-route re-validation.
     *
     * @param array<string,mixed> $data
     */
    public function hydrateFromCompiled(array $data): void
    {
        /** @var list<mixed> $rawRoutes */
        $rawRoutes = $data['routes'] ?? [];

        /** @var list<RouteRecord> $records */
        $records = array_values($rawRoutes);
        $this->routes = $records;

        /** @var array<string,string> $signatureIndex */
        $signatureIndex = is_array($data['signatureIndex'] ?? null) ? $data['signatureIndex'] : [];
        $this->signatureIndex = $signatureIndex;

        /** @var array<string,string> $nameIndex */
        $nameIndex = is_array($data['nameIndex'] ?? null) ? $data['nameIndex'] : [];
        $this->nameIndex = $nameIndex;

        $rawSequence = $data['sequence'] ?? count($records);
        $this->sequence = is_numeric($rawSequence) ? (int) $rawSequence : count($records);

        $rawBudget = $data['maxRoutesBudget'] ?? count($records);
        $this->maxRoutesBudget = max(1, is_numeric($rawBudget) ? (int) $rawBudget : count($records));

        $this->sorted = true;
    }

    public function setMaxRoutesBudget(int $max): void
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('Route budget must be >= 1.');
        }
        if (count($this->routes) > $max) {
            throw new \InvalidArgumentException('Route budget cannot be lower than current route count.');
        }
        $this->maxRoutesBudget = $max;
    }

    /**
     * Unvalidated budget override for policy application and compiled
     * hydration (mirrors the pre-refactor direct field writes).
     */
    public function applyBudget(int $max): void
    {
        $this->maxRoutesBudget = $max;
    }

    public function getMaxRoutesBudget(): int
    {
        return $this->maxRoutesBudget;
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
}
