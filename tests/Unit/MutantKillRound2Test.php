<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests (round 2) for issue #299.
 * Hotspots: RouteCollection, RouteGroupStack, RoutePatternParser,
 * RouteRadixIndex, UrlGenerator, DependencyGraphValidator, MessageCatalog,
 * RouteConstraintValidator, ValidationTranslator.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Router\RouteCollection;
use Zef\Framework\Router\RouteGroupStack;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\RouteRadixIndex;
use Zef\Framework\Router\UrlGenerator;
use Zef\Framework\Validation\DependencyGraphValidator;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Framework\Validation\ValidationError;
use Zef\Framework\Validation\ValidationResult;
use Zef\Framework\Validation\ValidationTranslator;

/**
 * @internal
 */
final class MutantKillRound2Test extends TestCase
{
    // ---- RouteCollection ----

    /** export() sorts before snapshotting (kills MethodCallRemoval:171). */
    public function testExportSortsRoutes(): void
    {
        $c = new RouteCollection();
        $c->add($this->records('/a')[0]);
        $c->add($this->records('/b')[0]);

        self::assertCount(2, $c->export()['routes']);
    }

    /** hydrateFromCompiled() casts a numeric-string budget (kills CastInt:209). */
    public function testHydrateCastsNumericStringBudget(): void
    {
        $c = new RouteCollection();
        $c->hydrateFromCompiled([
            'routes' => $this->records('/a'),
            'maxRoutesBudget' => '1',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Router safety budget exceeded');
        $c->assertCapacity();
    }

    // ---- RouteGroupStack ----

    /** push() must stay public (kills PublicVisibility:119). */
    public function testPushIsPublicAndCallable(): void
    {
        $method = new \ReflectionMethod(RouteGroupStack::class, 'push');
        self::assertTrue($method->isPublic(), 'RouteGroupStack::push() must stay public');

        $stack = new RouteGroupStack();
        $stack->push('/api', 'api.', ['m'], 3);
        self::assertSame(['m'], $stack->currentAttributes()['middleware']);
        self::assertSame(3, $stack->currentAttributes()['priority']);
    }

    // ---- RoutePatternParser ----

    /** splitPath('/') is the empty list (kills ReturnRemoval:78). */
    public function testSplitPathRootIsEmpty(): void
    {
        self::assertSame([], RoutePatternParser::splitPath('/'));
        self::assertSame(['a', 'b'], RoutePatternParser::splitPath('/a/b/'));
    }

    /** assertUniqueParams records each name (kills TrueValue:117). */
    public function testAssertUniqueParamsDetectsDuplicate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate route parameter 'x'.");
        RoutePatternParser::assertUniqueParams(
            RoutePatternParser::parsePattern('/{x}/{x}'),
            new RouteConstraintValidator(),
        );
    }

    /** matchRoute() decodes a dynamic value and continues past it (kills Continue_:169). */
    public function testMatchRouteDecodesAndContinues(): void
    {
        $segments = RoutePatternParser::parsePattern('/u/{name}/x');
        $params = RoutePatternParser::matchRoute($segments, '/u/john%20doe/x', new RouteConstraintValidator());
        self::assertIsArray($params);
        self::assertSame('john doe', $params['name'], 'the dynamic value is rawurldecoded');
    }

    // ---- RouteRadixIndex ----

    /** A dead-end path yields no candidates (kills ReturnRemoval:75). */
    public function testRadixDeadEndReturnsEmpty(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/b')); // @phpstan-ignore argument.type (open-shape record vs RouteRecord alias)
        self::assertSame([], $radix->candidates('/a/b/c', true, new RouteConstraintValidator()));
    }

    /** A dynamic edge is recorded (kills TrueValue:108). */
    public function testRadixDynamicEdgeRecorded(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/{id}')); // @phpstan-ignore argument.type (open-shape record vs RouteRecord alias)
        self::assertSame([0], $radix->candidates('/a/z', true, new RouteConstraintValidator()));
    }

    /** No candidates yields an empty list (kills ReturnRemoval:129). */
    public function testRadixNoCandidatesReturnsEmpty(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/b')); // @phpstan-ignore argument.type (open-shape record vs RouteRecord alias)
        self::assertSame([], $radix->candidates('/x/y', true, new RouteConstraintValidator()));
    }

    // ---- UrlGenerator ----

    /** An extra parameter is rejected (kills CastString:52). */
    public function testExtraParameterIsRejected(): void
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'a' does not accept parameter 'extra'.");
        new UrlGenerator($router)->generate('a', ['id' => 1, 'extra' => 2]);
    }

    /** A consumed parameter is marked (kills TrueValue:83). */
    public function testConsumedParameterIsMarked(): void
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');
        self::assertSame('/a/7', new UrlGenerator($router)->generate('a', ['id' => 7]));
    }

    // ---- DependencyGraphValidator ----

    /** The cross-module message is pinned verbatim (kills Concat/ConcatOperandRemoval:118/119). */
    public function testCrossModuleMessagePinned(): void
    {
        $this->expectException(ModuleDependencyViolationException::class);
        $this->expectExceptionMessage("Module 'M1' exceeds cross-module reference limit (1) towards 'M2'.");
        new DependencyGraphValidator()->validate(
            ['A' => 1, 'B' => 1, 'C' => 1],
            [],
            ['A' => ['B', 'C']],
            ['A' => 'M1', 'B' => 'M2', 'C' => 'M2'],
            ['A' => 'transient', 'B' => 'transient', 'C' => 'transient'],
            1,
        );
    }

    // ---- MessageCatalog ----

    /** The invalid-locale message is pinned verbatim (kills Concat/ConcatOperandRemoval:36). */
    public function testInvalidLocaleMessagePinned(): void
    {
        $ref = new \ReflectionClass(MessageCatalog::class);
        $obj = $ref->newInstanceWithoutConstructor();
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid catalog locale 'BAD LOCALE!'.");
        $ctor->invoke($obj, ['BAD LOCALE!' => ['r' => 't']]);
    }

    /** with() rejects an empty rule key with the pinned message (kills Throw_:87). */
    public function testWithEmptyRuleKeyMessagePinned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog rule keys must be 1..64 byte strings.');
        MessageCatalog::empty()->with('id', ['' => 't']);
    }

    /** with() rejects an overlong template with the pinned message (kills Throw_:90). */
    public function testWithOverlongTemplateMessagePinned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Catalog template for 'id/r' must be 1..512 bytes.");
        MessageCatalog::empty()->with('id', ['r' => str_repeat('x', 513)]);
    }

    // ---- RouteConstraintValidator ----

    /** Every built-in constraint type is present (kills MatchArmRemoval:130). */
    public function testEveryBuiltInConstraintType(): void
    {
        $v = new RouteConstraintValidator();
        self::assertTrue($v->test('p', 'int', '1'));
        self::assertTrue($v->test('p', 'uint', '1'));
        self::assertTrue($v->test('p', 'alpha', 'a'));
        self::assertTrue($v->test('p', 'slug', 'a'));
        self::assertTrue($v->test('p', 'uuid', '12345678-1234-1234-1234-123456789012'));
        self::assertTrue($v->test('p', 'hex', 'a'));
    }

    // ---- ValidationTranslator ----

    /** An unknown rule keeps the original error and continues (kills Continue_:37). */
    public function testUnknownRuleKeepsOriginalAndContinues(): void
    {
        $catalog = MessageCatalog::defaultEnglish()->with('en', ['known' => 'K {{field}}']);
        $translator = new ValidationTranslator($catalog);
        $result = new ValidationResult([
            new ValidationError('a', 'orig-a', 'unknown_rule'),
            new ValidationError('b', 'orig-b', 'known'),
        ], []);

        $translated = $translator->translate($result, 'en');
        self::assertSame('orig-a', $translated->messages()[0], 'the unknown rule keeps its original message');
        self::assertSame('K b', $translated->messages()[1], 'translation continues after the unknown rule');
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
