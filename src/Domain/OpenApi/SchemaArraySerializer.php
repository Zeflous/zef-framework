<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * @internal
 *
 * Recursive array serialization for {@see Schema} (moved out of the value
 * object so the Schema class itself stays within the class-size budget).
 * Null/unset constraints are omitted so the emitted document stays minimal
 * and deterministic.
 */
final class SchemaArraySerializer
{
    /**
     * @return array{
     *     '$ref': string,
     *     nullable?: true,
     * }|array{
     *     type: string,
     *     format?: string,
     *     description?: string,
     *     title?: string,
     *     nullable?: true,
     *     readOnly?: true,
     *     writeOnly?: true,
     *     deprecated?: true,
     *     minLength?: int,
     *     maxLength?: int,
     *     pattern?: string,
     *     minimum?: float|int,
     *     maximum?: float|int,
     *     minItems?: int,
     *     maxItems?: int,
     *     uniqueItems?: true,
     *     minProperties?: int,
     *     maxProperties?: int,
     *     required?: list<string>,
     *     properties?: array<string, array<string, mixed>>,
     *     items?: array<string, mixed>,
     *     oneOf?: list<array<string, mixed>>,
     *     anyOf?: list<array<string, mixed>>,
     *     allOf?: list<array<string, mixed>>,
     *     additionalProperties?: array<string, mixed>|bool,
     *     default?: mixed,
     *     example?: mixed,
     *     enum?: list<bool|float|int|string>,
     *     '$ref'?: string,
     * }
     */
    public static function toArray(Schema $schema): array
    {
        if ($schema->ref !== null) {
            return self::refArray($schema, $schema->ref);
        }

        $out = ['type' => $schema->type->value];
        self::appendMetadata($schema, $out);
        self::appendBounds($schema, $out);
        self::appendMembers($schema, $out);

        return $out;
    }

    /**
     * @return array{'$ref': string, nullable?: true}
     */
    private static function refArray(Schema $schema, string $ref): array
    {
        $out = ['$ref' => $ref];
        if ($schema->nullable === true) {
            $out['nullable'] = true;
        }

        return $out;
    }

    /**
     * @param array{
     *     type: string,
     *     format?: string,
     *     description?: string,
     *     title?: string,
     *     nullable?: true,
     *     readOnly?: true,
     *     writeOnly?: true,
     *     deprecated?: true,
     * } $out
     */
    private static function appendMetadata(Schema $schema, array &$out): void
    {
        if ($schema->format !== null) {
            $out['format'] = $schema->format;
        }
        if ($schema->description !== null) {
            $out['description'] = $schema->description;
        }
        if ($schema->title !== null) {
            $out['title'] = $schema->title;
        }
        if ($schema->nullable === true) {
            $out['nullable'] = true;
        }
        if ($schema->readOnly) {
            $out['readOnly'] = true;
        }
        if ($schema->writeOnly) {
            $out['writeOnly'] = true;
        }
        if ($schema->deprecated === true) {
            $out['deprecated'] = true;
        }
    }

    /**
     * @param array{
     *     type: string,
     *     format?: string,
     *     description?: string,
     *     title?: string,
     *     nullable?: true,
     *     readOnly?: true,
     *     writeOnly?: true,
     *     deprecated?: true,
     *     minLength?: int,
     *     maxLength?: int,
     *     minimum?: float|int,
     *     maximum?: float|int,
     *     minItems?: int,
     *     maxItems?: int,
     *     minProperties?: int,
     *     maxProperties?: int,
     *     pattern?: string,
     *     uniqueItems?: true,
     * } $out
     */
    private static function appendBounds(Schema $schema, array &$out): void
    {
        if ($schema->minLength !== null) {
            $out['minLength'] = $schema->minLength;
        }
        if ($schema->maxLength !== null) {
            $out['maxLength'] = $schema->maxLength;
        }
        if ($schema->minimum !== null) {
            $out['minimum'] = $schema->minimum;
        }
        if ($schema->maximum !== null) {
            $out['maximum'] = $schema->maximum;
        }
        if ($schema->minItems !== null) {
            $out['minItems'] = $schema->minItems;
        }
        if ($schema->maxItems !== null) {
            $out['maxItems'] = $schema->maxItems;
        }
        if ($schema->minProperties !== null) {
            $out['minProperties'] = $schema->minProperties;
        }
        if ($schema->maxProperties !== null) {
            $out['maxProperties'] = $schema->maxProperties;
        }
        if ($schema->pattern !== null) {
            $out['pattern'] = $schema->pattern;
        }
        if ($schema->uniqueItems === true) {
            $out['uniqueItems'] = true;
        }
    }

    /**
     * @param array{
     *     type: string,
     *     format?: string,
     *     description?: string,
     *     title?: string,
     *     nullable?: true,
     *     readOnly?: true,
     *     writeOnly?: true,
     *     deprecated?: true,
     *     minLength?: int,
     *     maxLength?: int,
     *     minimum?: float|int,
     *     maximum?: float|int,
     *     minItems?: int,
     *     maxItems?: int,
     *     minProperties?: int,
     *     maxProperties?: int,
     *     pattern?: string,
     *     uniqueItems?: true,
     *     required?: list<string>,
     *     properties?: array<string, array<string, mixed>>,
     *     items?: array<string, mixed>,
     *     oneOf?: list<array<string, mixed>>,
     *     anyOf?: list<array<string, mixed>>,
     *     allOf?: list<array<string, mixed>>,
     *     additionalProperties?: array<string, mixed>|bool,
     *     default?: mixed,
     *     example?: mixed,
     *     enum?: list<bool|float|int|string>,
     * } $out
     */
    private static function appendMembers(Schema $schema, array &$out): void
    {
        if ($schema->required !== []) {
            $out['required'] = $schema->required;
        }
        if ($schema->properties !== []) {
            $out['properties'] = array_map(
                static fn (Schema $child): array => $child->toArray(),
                $schema->properties,
            );
        }
        if ($schema->items instanceof Schema) {
            $out['items'] = $schema->items->toArray();
        }
        if ($schema->oneOf !== null) {
            $out['oneOf'] = array_map(
                static fn (Schema $child): array => $child->toArray(),
                $schema->oneOf,
            );
        }
        if ($schema->anyOf !== null) {
            $out['anyOf'] = array_map(
                static fn (Schema $child): array => $child->toArray(),
                $schema->anyOf,
            );
        }
        if ($schema->allOf !== null) {
            $out['allOf'] = array_map(
                static fn (Schema $child): array => $child->toArray(),
                $schema->allOf,
            );
        }
        $additionalProperties = match (true) {
            $schema->additionalProperties instanceof Schema => $schema->additionalProperties->toArray(),
            $schema->additionalPropertiesAllowed !== null => $schema->additionalPropertiesAllowed,
            default => null,
        };
        if ($additionalProperties !== null) {
            $out['additionalProperties'] = $additionalProperties;
        }

        if ($schema->default !== null) {
            $out['default'] = $schema->default;
        }
        if ($schema->example !== null) {
            $out['example'] = $schema->example;
        }
        if ($schema->enum !== null) {
            $out['enum'] = $schema->enum;
        }
    }
}
