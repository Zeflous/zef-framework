<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * @internal
 *
 * Synthesizes the deterministic minimal example value a Postman request
 * body shows for a JSON-Schema array (moved out of the
 * PostmanCollectionExporter so the exporter stays within the class-size
 * budget; behaviour carried over unchanged)
 */
final class PostmanSchemaExamples
{
    /**
     * Build a deterministic minimal example value from a schema array.
     *
     * @param array<array-key, mixed> $schema
     */
    public static function exampleFromSchema(array $schema): mixed
    {
        $literal = self::literalExample($schema);
        if ($literal !== null) {
            return $literal;
        }
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'object';

        return match ($type) {
            'object' => self::objectExample($schema),
            'array' => self::arrayExample($schema),
            'integer', 'number' => isset($schema['minimum']) && is_numeric($schema['minimum'])
                ? $schema['minimum']
                : 1,
            'boolean' => true,
            default => self::stringExample($schema),
        };
    }

    /**
     * Explicit example/default/first-enum literal, or null when the schema
     * has none (null-valued entries count as absent, mirroring isset()).
     *
     * @param array<array-key, mixed> $schema
     */
    private static function literalExample(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }
        if (isset($schema['default'])) {
            return $schema['default'];
        }
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && isset($enum[0])) {
            return $enum[0];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function objectExample(array $schema): array|\stdClass
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $out = [];
        foreach ($properties as $name => $property) {
            if (is_string($name) && is_array($property)) {
                $out[$name] = self::exampleFromSchema($property);
            }
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<mixed>
     */
    private static function arrayExample(array $schema): array
    {
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

        return [self::exampleFromSchema($items)];
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private static function stringExample(array $schema): string
    {
        return ($schema['format'] ?? null) === 'uuid'
            ? '00000000-0000-4000-8000-000000000000'
            : 'string';
    }
}
