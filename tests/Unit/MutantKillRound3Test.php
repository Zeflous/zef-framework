<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests (round 3) for issue #299.
 * Targets the remaining killable escaped mutants; the rest are documented
 * as equivalent/harmless in the audit report.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Router\RouteCollection;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\RouteRadixIndex;
use Zef\Framework\Validation\DependencyGraphValidator;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 */
final class MutantKillRound3Test extends TestCase
{
    /** A fractional numeric-string budget is cast to int (kills CastInt:209). */
    public function testHydrateCastsFractionalBudgetToInt(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => $this->records('/a'),
            'maxRoutesBudget' => '1.9',
        ]);

        // (int) '1.9' === 1 -> capacity 1 -> the single route already fills it.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Router safety budget exceeded');
        $c->assertCapacity();
    }

    /** The cross-module key separator prevents (from,to) collisions (kills Concat/ConcatOperandRemoval:118). */
    public function testCrossModuleKeySeparatorPreventsCollision(): void
    {
        // With the '->' separator the two edges are distinct keys, each count 1.
        // Without it both collapse to 'ABC' and the second trips the limit.
        new DependencyGraphValidator()->validate(
            ['A' => 1, 'AB' => 1, 'BC' => 1, 'C' => 1],
            [],
            ['A' => ['BC'], 'AB' => ['C']],
            ['A' => 'M1', 'AB' => 'M2', 'BC' => 'M3', 'C' => 'M4'],
            ['A' => 'transient', 'AB' => 'transient', 'BC' => 'transient', 'C' => 'transient'],
            1,
        );
        self::addToAssertionCount(1);
    }

    /** matchRoute() continues past a dynamic segment to the following static one (kills Continue_:169). */
    public function testMatchRouteContinuesPastDynamicSegment(): void
    {
        $segments = RoutePatternParser::parsePattern('/u/{name}/x');
        $params = RoutePatternParser::matchRoute($segments, '/u/john/x', new RouteConstraintValidator());
        self::assertIsArray($params, 'a dynamic segment must not abort the loop');
        self::assertSame('john', $params['name']);

        // A following static segment that does NOT match must fail the whole match.
        self::assertFalse(
            RoutePatternParser::matchRoute($segments, '/u/john/y', new RouteConstraintValidator()),
            'the static segment after a dynamic one must still be compared',
        );
    }

    /** with() rejects an empty rule key (kills Throw_:87). */
    public function testWithRejectsEmptyRuleKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog rule keys must be 1..64 byte strings.');
        MessageCatalog::empty()->with('id', ['' => 't']);
    }

    /** with() rejects an overlong template (kills Throw_:90). */
    public function testWithRejectsOverlongTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Catalog template for 'id/r' must be 1..512 bytes.");
        MessageCatalog::empty()->with('id', ['r' => str_repeat('x', 513)]);
    }

    /** localeChain() de-duplicates and always ends with the wildcard (kills UnwrapArrayUnique/UnwrapArrayValues:136). */
    public function testLocaleChainShape(): void
    {
        self::assertSame(['id_id', 'id', '*'], MessageCatalog::localeChain('id_ID'));
        self::assertSame(['id', '*'], MessageCatalog::localeChain('id'));
        self::assertSame(['*'], MessageCatalog::localeChain('*'));
        self::assertSame(['*'], MessageCatalog::localeChain('BAD LOCALE!'));
    }

    /** A custom constraint resolves to a bool on the happy path (kills CatchBlockRemoval:88). */
    public function testCustomConstraintHappyPath(): void
    {
        $v = new RouteConstraintValidator();
        $v->addCustom('even', '/^\d*[02468]$/');
        self::assertTrue($v->test('p', 'even', '42'));
        self::assertFalse($v->test('p', 'even', '43'));
    }

    /** Every built-in constraint type resolves (kills MatchArmRemoval:130). */
    public function testAllBuiltInConstraintTypesResolve(): void
    {
        $v = new RouteConstraintValidator();
        self::assertTrue($v->test('p', 'int', '1'));
        self::assertTrue($v->test('p', 'uint', '1'));
        self::assertTrue($v->test('p', 'alpha', 'a'));
        self::assertTrue($v->test('p', 'slug', 'a'));
        self::assertTrue($v->test('p', 'uuid', '12345678-1234-1234-1234-123456789012'));
        self::assertTrue($v->test('p', 'hex', 'a'));
    }

    /** A dead-end radix walk returns an empty list (kills ReturnRemoval:75/129). */
    public function testRadixDeadEndsReturnEmpty(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/b')); // @phpstan-ignore argument.type (open-shape record vs RouteRecord alias)

        self::assertSame([], $radix->candidates('/a/b/c', true, new RouteConstraintValidator()));
        self::assertSame([], $radix->candidates('/x/y', true, new RouteConstraintValidator()));
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
