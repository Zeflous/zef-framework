<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The schema-type system of the OpenAPI runtime gate: shape matching,
 * labels and string-transport coercion. Extracted from
 * {@see OpenApiSchemaChecker} for the class-size budget (php:S2042);
 * behaviour and messages are carried over verbatim.
 *
 * An empty PHP array satisfies both the 'array' and 'object' shapes —
 * the JSON encoding of [] vs {} is lost in associative decoding, so the
 * ambiguity is resolved leniently in both directions.
 */
final class OpenApiSchemaTypes
{
    private const string INTEGER_PATTERN = '/^-?\d+$/';

    public static function typeMatches(mixed $value, mixed $type): bool
    {
        if ($type === null || (is_array($type) && $type === [])) {
            return true;
        }
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if (self::matches($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function typeLabel(mixed $type): string
    {
        if (is_string($type)) {
            return $type;
        }
        if (is_array($type)) {
            $labels = [];
            foreach ($type as $candidate) {
                $labels[] = is_string($candidate) ? $candidate : 'unknown';
            }

            return $labels === [] ? 'any type' : implode('|', $labels);
        }

        return 'any type';
    }

    public static function valueTypeLabel(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            is_array($value) && $value === [] => 'empty array',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => 'unknown',
        };
    }

    /**
     * String-transport coercion (query/header/cookie parameters): scalar
     * strings are converted to the schema's declared type when the text
     * is an exact literal of that type; anything else stays a string and
     * fails the type check with the precise message.
     */
    /**
     * @param array<mixed, mixed> $schema
     */
    public static function coerceString(string $value, array $schema): bool|float|int|string
    {
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => preg_match(self::INTEGER_PATTERN, $value) === 1 ? (int) $value : $value,
            'number' => is_numeric($value) ? +$value : $value,
            'boolean' => self::coerceBool($value),
            default => $value,
        };
    }

    /** One candidate type name against one value. */
    private static function matches(mixed $value, string $candidate): bool
    {
        return match ($candidate) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'null' => $value === null,
            default => true,
        };
    }

    private static function coerceBool(string $value): bool|string
    {
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0') {
            return false;
        }

        return $value;
    }
}
