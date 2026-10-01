<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Adapters/Router/RouteCollection.php (7 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\RouteCollection;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class RouteCollectionMutantKillTest extends TestCase
{
    /** sortedRoutes() returns every registered route (kills MethodCallRemoval:171). */
    public function testSortedRoutesReturnsAll(): void
    {
        $c = new RouteCollection();
        $c->add($this->records('/a')[0]);
        $c->add($this->records('/b')[0]);

        self::assertCount(2, $c->sortedRoutes());
    }

    /** export() carries the running sequence (kills ArrayItem:177). */
    public function testExportCarriesSequence(): void
    {
        $c = new RouteCollection();
        $c->add($this->records('/a')[0]);
        $c->add($this->records('/b')[0]);

        self::assertSame(2, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() reindexes the route list (kills UnwrapArrayValues:194). */
    public function testHydrateReindexesRoutes(): void
    {
        $records = $this->records('/a', '/b');
        $c = new RouteCollection();
        $c->hydrateFromCompiled(['routes' => [3 => $records[0], 7 => $records[1]]]);

        self::assertSame([0, 1], array_keys($c->sortedRoutes()), 'hydrated routes are reindexed from 0');
    }

    /** hydrateFromCompiled() honours the provided sequence (kills Coalesce:205). */
    public function testHydrateUsesProvidedSequence(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => $this->records('/a'),
            'sequence' => 5,
        ]);

        self::assertSame(5, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() casts a numeric-string sequence (kills CastInt:209). */
    public function testHydrateCastsNumericStringSequence(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => $this->records('/a'),
            'sequence' => '7',
        ]);

        self::assertSame(7, $c->export()['sequence']);
    }

    /** hydrateFromCompiled() marks the collection sorted (kills TrueValue:211). */
    public function testHydrateMarksSorted(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled(['routes' => $this->records('/a', '/b')]);

        self::assertCount(2, $c->sortedRoutes());
    }

    /**
     * Builds real, fully-typed route records through the public Router API.
     *
     * @return list<array{
     *     method: string, pattern: string, handler: string, module: null|string, priority: int, sequence: int,
     *     segments: list<array{dynamic: false, value: string}|array{dynamic: true, name: string, constraint?: null|string}>,
     *     signature: string, name: null|string, middleware: list<string>,
     * }>
     */
    private function records(string ...$patterns): array
    {
        $router = new Router();
        foreach ($patterns as $i => $pattern) {
            $router->add('GET', $pattern, 'h' . $i);
        }

        $records = $router->exportRoutes()['routes'];
        foreach ($records as $i => &$record) {
            $record['sequence'] = $i;
        }
        unset($record);

        return $records;
    }
}
