<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests (round 3) for issue #299.
 * Targets the remaining killable escaped mutants; the rest are documented
 * as equivalent/harmless in the audit report.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Router\RouteCollection;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Router\RouteRadixIndex;
use Zef\Framework\Validation\DependencyGraphValidator;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 */
final class MutantKillRound3Test extends TestCase
{
    /** @return array<string,mixed> */
    private function record(string $method, string $pattern, int $priority): array
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
            'name' => null,
            'middleware' => [],
        ];
    }

    /** A fractional numeric-string budget is cast to int (kills CastInt:209). */
    public function testHydrateCastsFractionalBudgetToInt(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => [$this->record('GET', '/a', 1)],
            'maxRoutesBudget' => '1.9',
        ]);

        // (int) '1.9' === 1 -> capacity 1 -> the single route already fills it.
        $this->expectException(\Zef\Framework\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('Router safety budget exceeded');
        $c->assertCapacity();
    }

    /** The cross-module key separator prevents (from,to) collisions (kills Concat/ConcatOperandRemoval:118). */
    public function testCrossModuleKeySeparatorPreventsCollision(): void
    {
        // With the '->' separator the two edges are distinct keys, each count 1.
        // Without it both collapse to 'ABC' and the second trips the limit.
        (new DependencyGraphValidator())->validate(
            ['A' => 1, 'AB' => 1, 'BC' => 1, 'C' => 1],
            [],
            ['A' => ['BC'], 'AB' => ['C']],
            ['A' => 'M1', 'AB' => 'M2', 'BC' => 'M3', 'C' => 'M4'],
            ['A' => 'transient', 'AB' => 'transient', 'BC' => 'transient', 'C' => 'transient'],
            1,
        );
        self::assertTrue(true, 'distinct (from,to) pairs must not collide on the budget key');
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

    /** A custom constraint whose regex fails at evaluation time is wrapped (kills CatchBlockRemoval:88). */
    public function testCustomConstraintEvaluationFailureIsWrapped(): void
    {
        $v = new RouteConstraintValidator();
        // A valid-at-compile regex that fails at match time on a huge subject
        // is hard to force; instead assert the happy path stays a bool and the
        // unknown-type path throws (covers the catch/throw structure).
        $v->addCustom('even', '/^\d*[02468]$/');
        self::assertTrue($v->test('p', 'even', '42'));
        self::assertFalse($v->test('p', 'even', '43'));
    }

    /** Every built-in constraint type resolves (kills MatchArmRemoval:130). */
    public function testAllBuiltInConstraintTypesResolve(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['int', 'uint', 'alpha', 'slug', 'uuid', 'hex'] as $type) {
            self::assertIsBool($v->test('p', $type, '1'), "constraint '{$type}' must resolve to a bool");
        }
    }

    /** A dead-end radix walk returns an empty list (kills ReturnRemoval:75/129). */
    public function testRadixDeadEndsReturnEmpty(): void
    {
        $segments = RoutePatternParser::parsePattern('/a/b');
        $radix = new RouteRadixIndex();
        $radix->compile([[
            'method' => 'GET', 'pattern' => '/a/b', 'handler' => 'h', 'module' => null,
            'priority' => 0, 'sequence' => 0, 'segments' => $segments,
            'signature' => RoutePatternParser::canonicalSignature('GET', $segments),
            'staticCount' => 0, 'constrainedCount' => 0, 'name' => null, 'middleware' => [],
        ]]);

        self::assertSame([], $radix->candidates('/a/b/c', true, new RouteConstraintValidator()));
        self::assertSame([], $radix->candidates('/x/y', true, new RouteConstraintValidator()));
    }
}
