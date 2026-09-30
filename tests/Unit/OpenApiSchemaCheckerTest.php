<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.33.0 — OpenAPI runtime gate: the JSON-Schema subset
 * checker (boundary B9's engine, plus the coercion semantics of B4/B5/B6).
 *
 * Hand-written schema arrays (not builder value objects) deliberately:
 * the checker is the garbage-tolerance boundary for hand-edited documents,
 * so the defensive branches (invalid pattern, over-long pattern, malformed
 * composition branches, unresolvable refs) are legitimately reachable here.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\OpenApiSchemaChecker;

/**
 * @internal
 */
final class OpenApiSchemaCheckerTest extends TestCase
{
    public function testUnconstrainedSchemaAcceptsAnything(): void
    {
        self::assertSame([], $this->issues(null, []));
        self::assertSame([], $this->issues('anything', []));
        self::assertSame([], $this->issues(42, ['format' => 'unknown-format']));
    }

    public function testNullOnlyPassesWhenNullable(): void
    {
        self::assertSame([], $this->issues(null, ['type' => 'string', 'nullable' => true]));
        $issues = $this->issues(null, ['type' => 'string']);
        self::assertSame([['pointer' => '', 'message' => 'expected string, got null']], $issues);
    }

    public function testScalarTypeChecks(): void
    {
        self::assertSame([], $this->issues('x', ['type' => 'string']));
        self::assertSame([['pointer' => '', 'message' => 'expected string, got integer']], $this->issues(5, ['type' => 'string']));
        self::assertSame([], $this->issues(42, ['type' => 'integer']));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected integer, got number']],
            $this->issues(4.5, ['type' => 'integer']),
        );
        self::assertSame([], $this->issues(4.5, ['type' => 'number']));
        self::assertSame([], $this->issues(42, ['type' => 'number']));
        self::assertSame([], $this->issues(true, ['type' => 'boolean']));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected boolean, got string']],
            $this->issues('true', ['type' => 'boolean']),
        );
    }

    public function testArrayTypeChecks(): void
    {
        self::assertSame([], $this->issues(['a'], ['type' => 'array', 'items' => ['type' => 'string']]));
        self::assertSame([], $this->issues([], ['type' => 'array', 'items' => ['type' => 'string']]));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected array, got object']],
            $this->issues(['k' => 'v'], ['type' => 'array', 'items' => ['type' => 'string']]),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'expected object, got array']],
            $this->issues(['a'], ['type' => 'object']),
        );
        self::assertSame([], $this->issues(['k' => 'v'], ['type' => 'object']));
        // The JSON [] / {} ambiguity: an empty array satisfies both shapes.
        self::assertSame([], $this->issues([], ['type' => 'object']));
    }

    public function testTypeArrayUnion(): void
    {
        $schema = ['type' => ['string', 'integer']];
        self::assertSame([], $this->issues('x', $schema));
        self::assertSame([], $this->issues(5, $schema));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected string|integer, got number']],
            $this->issues(5.5, $schema),
        );
    }

    public function testUnknownTypeStringIsUnconstrained(): void
    {
        self::assertSame([], $this->issues(5, ['type' => 'exotic']));
    }

    public function testEnumMembership(): void
    {
        self::assertSame([], $this->issues('a', ['enum' => ['a', 'b']]));
        self::assertSame(
            [['pointer' => '', 'message' => 'value is not one of the enumerated values']],
            $this->issues('c', ['enum' => ['a', 'b']]),
        );
        self::assertSame([], $this->issues(42, ['enum' => [42]]));
    }

    public function testPatternEnforcement(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];
        self::assertSame([], $this->issues('abc', $schema));
        self::assertSame(
            [['pointer' => '', 'message' => 'value does not match the required pattern']],
            $this->issues('ABC', $schema),
        );
    }

    public function testInvalidPatternFailsClosed(): void
    {
        self::assertSame(
            [['pointer' => '', 'message' => 'schema pattern is invalid']],
            $this->issues('x', ['type' => 'string', 'pattern' => '(']),
        );
    }

    public function testPatternLengthPolicy(): void
    {
        self::assertSame(
            [['pointer' => '', 'message' => 'schema pattern exceeds the 2048-character policy']],
            $this->issues('x', ['type' => 'string', 'pattern' => str_repeat('a', 2049)]),
        );
    }

    public function testKnownFormats(): void
    {
        self::assertSame([], $this->issues('u@example.com', ['type' => 'string', 'format' => 'email']));
        self::assertSame(
            [['pointer' => '', 'message' => 'value is not a valid email']],
            $this->issues('not-an-email', ['type' => 'string', 'format' => 'email']),
        );
        self::assertSame([], $this->issues('123e4567-e89b-12d3-a456-426614174000', ['type' => 'string', 'format' => 'uuid']));
        self::assertSame(
            [['pointer' => '', 'message' => 'value is not a valid uuid']],
            $this->issues('not-a-uuid', ['type' => 'string', 'format' => 'uuid']),
        );
        self::assertSame([], $this->issues('2026-09-30T10:00:00Z', ['type' => 'string', 'format' => 'date-time']));
        self::assertSame([], $this->issues('2026-09-30T10:00:00.123+02:00', ['type' => 'string', 'format' => 'date-time']));
        self::assertSame(
            [['pointer' => '', 'message' => 'value is not a valid date-time']],
            $this->issues('30-09-2026', ['type' => 'string', 'format' => 'date-time']),
        );
    }

    public function testUnknownFormatIsOpenSet(): void
    {
        self::assertSame([], $this->issues('anything', ['type' => 'string', 'format' => 'custom']));
    }

    public function testStringLengthBoundsAreMultibyte(): void
    {
        self::assertSame([], $this->issues('é', ['type' => 'string', 'minLength' => 1]));
        self::assertSame(
            [['pointer' => '', 'message' => 'string is shorter than minLength 2']],
            $this->issues('a', ['type' => 'string', 'minLength' => 2]),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'string is longer than maxLength 3']],
            $this->issues('abcd', ['type' => 'string', 'maxLength' => 3]),
        );
    }

    public function testNumericBounds(): void
    {
        self::assertSame(
            [['pointer' => '', 'message' => 'value is below the minimum 1']],
            $this->issues(0, ['type' => 'integer', 'minimum' => 1]),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'value is above the maximum 10']],
            $this->issues(11, ['type' => 'integer', 'maximum' => 10]),
        );
        self::assertSame([], $this->issues(5, ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]));
    }

    public function testArrayBoundsItemsAndUniqueItems(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 3, 'uniqueItems' => true];
        self::assertSame([], $this->issues(['a', 'b'], $schema));
        self::assertSame(
            [['pointer' => '', 'message' => 'array has fewer than minItems 2']],
            $this->issues(['a'], $schema),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'array has more than maxItems 3']],
            $this->issues(['a', 'b', 'c', 'd'], $schema),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'array items are not unique']],
            $this->issues(['a', 'a'], $schema),
        );
        self::assertSame(
            [['pointer' => '/1', 'message' => 'expected string, got integer']],
            $this->issues(['a', 5], $schema),
        );
    }

    public function testPointerAccumulatesThroughNesting(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                ],
            ],
        ];
        $issues = $this->issues(['user' => ['email' => 'nope']], $schema);
        self::assertSame([['pointer' => '/user/email', 'message' => 'value is not a valid email']], $issues);
    }

    public function testPointerTokenEscaping(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a/b~c' => ['type' => 'integer']]];
        $issues = $this->issues(['a/b~c' => 'x'], $schema);
        self::assertSame([['pointer' => '/a~1b~0c', 'message' => 'expected integer, got string']], $issues);
    }

    public function testRequiredProperties(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['a', 'b'],
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
        ];
        self::assertSame([], $this->issues(['a' => 'x', 'b' => 1], $schema));
        $issues = $this->issues(['a' => 'x'], $schema);
        self::assertSame([['pointer' => '', 'message' => "missing required property 'b'"]], $issues);
    }

    public function testAdditionalPropertiesFalse(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false];
        self::assertSame([], $this->issues(['a' => 1], $schema));
        $issues = $this->issues(['a' => 1, 'b' => 2], $schema);
        self::assertSame([['pointer' => '', 'message' => "additional property 'b' is not allowed"]], $issues);
    }

    public function testAdditionalPropertiesSchemaValidatesTheRest(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']];
        self::assertSame([], $this->issues(['a' => 1, 'extra' => 'ok'], $schema));
        self::assertSame(
            [['pointer' => '/extra', 'message' => 'expected string, got integer']],
            $this->issues(['a' => 1, 'extra' => 5], $schema),
        );
    }

    public function testPropertyCountBounds(): void
    {
        self::assertSame(
            [['pointer' => '', 'message' => 'object has fewer than minProperties 2']],
            $this->issues(['a' => 1], ['type' => 'object', 'minProperties' => 2]),
        );
        self::assertSame(
            [['pointer' => '', 'message' => 'object has more than maxProperties 1']],
            $this->issues(['a' => 1, 'b' => 2], ['type' => 'object', 'maxProperties' => 1]),
        );
    }

    public function testRefResolution(): void
    {
        $schemas = ['User' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]];
        $schema = ['$ref' => '#/components/schemas/User'];
        self::assertSame([], $this->issues(['id' => 1], $schema, false, $schemas));
        $issues = $this->issues(['id' => 'x'], $schema, false, $schemas);
        self::assertSame([['pointer' => '/id', 'message' => 'expected integer, got string']], $issues);
    }

    public function testRefSiblingsAlsoApply(): void
    {
        $schemas = ['Label' => ['type' => 'string']];
        self::assertSame([], $this->issues('x', ['$ref' => '#/components/schemas/Label'], false, $schemas));
        self::assertSame([], $this->issues(null, ['$ref' => '#/components/schemas/Label', 'nullable' => true], false, $schemas));
        $issues = $this->issues('toolong', ['$ref' => '#/components/schemas/Label', 'maxLength' => 3], false, $schemas);
        self::assertSame([['pointer' => '', 'message' => 'string is longer than maxLength 3']], $issues);
    }

    public function testUnresolvableAndMalformedRefs(): void
    {
        $message = "unresolvable \$ref '#/components/schemas/Missing'";
        self::assertSame(
            [['pointer' => '', 'message' => $message]],
            $this->issues('x', ['$ref' => '#/components/schemas/Missing'], false, []),
        );
        self::assertSame(
            [['pointer' => '', 'message' => "unresolvable \$ref 'https://example.com/schema'"]],
            $this->issues('x', ['$ref' => 'https://example.com/schema'], false, []),
        );
    }

    public function testSelfReferencingSchemaIsCycleSafeAndDepthBounded(): void
    {
        $schemas = [
            'Node' => [
                'type' => 'object',
                'properties' => ['child' => ['$ref' => '#/components/schemas/Node']],
            ],
        ];
        $schema = ['$ref' => '#/components/schemas/Node'];

        $shallow = [];
        for ($i = 0; $i < 10; ++$i) {
            $shallow = ['child' => $shallow];
        }
        self::assertSame([], $this->issues($shallow, $schema, false, $schemas));

        $deep = [];
        for ($i = 0; $i < 70; ++$i) {
            $deep = ['child' => $deep];
        }
        $issues = $this->issues($deep, $schema, false, $schemas);
        self::assertCount(1, $issues);
        self::assertSame('schema nesting exceeds 64 levels', $issues[0]['message']);
    }

    public function testOneOfRequiresExactlyOneBranch(): void
    {
        $schema = ['oneOf' => [['type' => 'string'], ['type' => 'integer']]];
        self::assertSame([], $this->issues('x', $schema));
        self::assertSame([], $this->issues(5, $schema));
        self::assertSame(
            [['pointer' => '', 'message' => 'value matches none of the oneOf branches']],
            $this->issues(5.5, $schema),
        );

        $overlapping = ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'integer']]];
        self::assertSame(
            [['pointer' => '', 'message' => 'value matches more than one oneOf branch']],
            $this->issues(5, $overlapping),
        );
    }

    public function testAnyOfRequiresAtLeastOneBranch(): void
    {
        $schema = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];
        self::assertSame([], $this->issues('x', $schema));
        self::assertSame(
            [['pointer' => '', 'message' => 'value matches none of the anyOf branches']],
            $this->issues(5.5, $schema),
        );
    }

    public function testAllOfCollectsEveryBranchIssue(): void
    {
        $schema = ['allOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'string', 'maxLength' => 3]]];
        self::assertSame([], $this->issues('ab', $schema));
        $issues = $this->issues('abcd', $schema);
        self::assertSame([['pointer' => '', 'message' => 'string is longer than maxLength 3']], $issues);
    }

    public function testMalformedCompositionBranchesAreSkipped(): void
    {
        $schema = ['oneOf' => ['not-an-object']];
        self::assertSame(
            [['pointer' => '', 'message' => 'value matches none of the oneOf branches']],
            $this->issues('x', $schema),
        );
    }

    public function testStringTransportCoercion(): void
    {
        self::assertSame([], $this->issues('42', ['type' => 'integer'], true));
        self::assertSame([], $this->issues('-42', ['type' => 'integer'], true));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected integer, got string']],
            $this->issues('4.5', ['type' => 'integer'], true),
        );
        self::assertSame([], $this->issues('42', ['type' => 'number'], true));
        self::assertSame([], $this->issues('4.5', ['type' => 'number'], true));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected number, got string']],
            $this->issues('abc', ['type' => 'number'], true),
        );
        self::assertSame([], $this->issues('true', ['type' => 'boolean'], true));
        self::assertSame([], $this->issues('0', ['type' => 'boolean'], true));
        self::assertSame(
            [['pointer' => '', 'message' => 'expected boolean, got string']],
            $this->issues('yes', ['type' => 'boolean'], true),
        );
    }

    public function testCoercionFeedsEnumAndBounds(): void
    {
        self::assertSame([], $this->issues('42', ['type' => 'integer', 'enum' => [42]], true));
        self::assertSame(
            [['pointer' => '', 'message' => 'value is below the minimum 43']],
            $this->issues('42', ['type' => 'integer', 'minimum' => 43], true),
        );
        // No type declared: no coercion happens, the string stays a string.
        self::assertSame([], $this->issues('42', ['enum' => ['42']], true));
    }

    /**
     * @param array<mixed, mixed> $schema
     * @param array<string, mixed> $schemas
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function issues(mixed $value, array $schema, bool $coerce = false, array $schemas = []): array
    {
        return new OpenApiSchemaChecker($schemas)->check($value, $schema, $coerce);
    }
}
