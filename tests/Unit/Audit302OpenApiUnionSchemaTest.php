<?php

declare(strict_types=1);

/*
 * Audit #302 regression: union-typed properties must serialize as a
 * TYPELESS oneOf composition. The old output {"type":"object","oneOf":
 * [...]} was unsatisfiable (no value is simultaneously an object and a
 * string/integer) and the runtime gate short-circuited on the type check
 * before ever evaluating the composition — rejecting every valid payload
 * of a union-typed endpoint with 400.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\OpenApiSchemaChecker;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaGenerator;
use Zef\Framework\OpenApi\SchemaType;

/**
 * @internal
 */
final class Audit302OpenApiUnionSchemaTest extends TestCase
{
    public function testUnionPropertySerializesTypeless(): void
    {
        $generator = new SchemaGenerator();
        $schema = $generator->generateFromClass(Audit302UnionDto::class);

        $flexible = $schema->properties['flexible'];
        self::assertNotNull($flexible->oneOf);
        self::assertCount(2, $flexible->oneOf);
        self::assertNull($flexible->type, 'a pure composition carries no type');

        $array = $flexible->toArray();
        self::assertArrayNotHasKey('type', $array, 'type:object + oneOf of scalars is unsatisfiable');
        self::assertSame(
            [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            $array['oneOf'] ?? null,
        );
    }

    public function testNullableUnionStaysTypeless(): void
    {
        $generator = new SchemaGenerator();
        $schema = $generator->generateFromClass(Audit302UnionDto::class);

        $maybe = $schema->properties['maybe'];
        $array = $maybe->toArray();

        self::assertArrayNotHasKey('type', $array);
        self::assertTrue($array['nullable'] ?? false, 'nullable survives as a sibling flag');
        self::assertArrayHasKey('oneOf', $array);
    }

    public function testTypedSchemasStillEmitType(): void
    {
        $array = new Schema(type: SchemaType::String, minLength: 1)->toArray();

        self::assertSame('string', $array['type'] ?? null, 'ordinary typed schemas are unchanged');
        self::assertSame(1, $array['minLength'] ?? null);
    }

    public function testRuntimeGateAcceptsValidUnionPayloads(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $schema = new SchemaGenerator()
            ->generateFromClass(Audit302UnionDto::class)
            ->properties['flexible']
            ->toArray()
        ;

        self::assertSame([], $checker->check('hello', $schema, false), 'a string satisfies oneOf [string, integer]');
        self::assertSame([], $checker->check(42, $schema, false), 'an integer satisfies oneOf [string, integer]');
    }

    public function testRuntimeGateStillRejectsNonMembers(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $schema = new SchemaGenerator()
            ->generateFromClass(Audit302UnionDto::class)
            ->properties['flexible']
            ->toArray()
        ;

        self::assertNotSame([], $checker->check(true, $schema, false), 'a boolean satisfies no oneOf member');
        self::assertNotSame([], $checker->check(['x'], $schema, false), 'an array satisfies no oneOf member');
    }
}

/**
 * @internal
 */
final class Audit302UnionDto
{
    public int|string $flexible;

    public int|string|null $maybe = null;
}
