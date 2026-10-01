<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Adapters/Router/RouteCollection.php (7 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\RouteCollection;
use Zef\Framework\Router\RoutePatternParser;

/**
 * @internal
 */
final class RouteCollectionMutantKillTest extends TestCase
{
    /** @return array<string,mixed> */
    private function record(string $method, string $pattern, int $priority, ?string $name = null): array
    {
        $segments = RoutePatternParser::parsePattern($pattern);

        return [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => 'h',
            'module' => null,
            'priority' => $priority,
            'segments' => $segments,
            'signature' => RoutePatternParser::canonicalSignature($method, $segments),
            'name' => $name,
            'middleware' => [],
        ];
    }

    /** sortedRoutes() sorts by priority DESC (kills MethodCallRemoval:171). */
    public function testSortedRoutesSortsByPriorityDesc(): void
    {
        $c = new RouteCollection();
        $c->add($this->record('GET', '/a', 1));
        $c->add($this->record('GET', '/b', 5));

        $sorted = $c->sortedRoutes();
        self::assertSame(5, $sorted[0]['priority'], 'higher priority sorts first');
        self::assertSame(1, $sorted[1]['priority']);
    }

    /** export() carries the running sequence (kills ArrayItem:177). */
    public function testExportCarriesSequence(): void
    {
        $c = new RouteCollection();
        $c->add($this->record('GET', '/a', 1));
        $c->add($this->record('GET', '/b', 1));

        self::assertSame(2, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() reindexes the route list (kills UnwrapArrayValues:194). */
    public function testHydrateReindexesRoutes(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled(['routes' => [
            3 => $this->record('GET', '/a', 1),
            7 => $this->record('GET', '/b', 1),
        ]]);

        self::assertSame([0, 1], array_keys($c->sortedRoutes()), 'hydrated routes are reindexed from 0');
    }

    /** hydrateFromCompiled() honours the provided sequence (kills Coalesce:205). */
    public function testHydrateUsesProvidedSequence(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => [$this->record('GET', '/a', 1)],
            'sequence' => 5,
        ]);

        self::assertSame(5, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() casts a numeric-string sequence (kills CastInt:209). */
    public function testHydrateCastsNumericStringSequence(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => [$this->record('GET', '/a', 1)],
            'sequence' => '7',
        ]);

        self::assertSame(7, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() marks the collection sorted (kills TrueValue:211). */
    public function testHydrateMarksSorted(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled(['routes' => [
            $this->record('GET', '/a', 1),
            $this->record('GET', '/b', 5),
        ]]);

        $sorted = $c->sortedRoutes();
        self::assertSame(1, $sorted[0]['priority'], 'stored order is preserved (no re-sort after hydrate)');
    }
}
