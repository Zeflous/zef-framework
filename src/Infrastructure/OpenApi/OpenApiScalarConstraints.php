<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Scalar-value constraints of the OpenAPI runtime gate: enum membership
 * plus the string and number keyword families. Extracted from
 * {@see OpenApiSchemaChecker} for the class-size budget (php:S2042);
 * behaviour, messages and their deterministic order are carried over
 * verbatim. Non-recursive by construction, so the split introduces no
 * checker cycle.
 */
final class OpenApiScalarConstraints
{
    /** House ReDoS policy, mirrored from the Schema value object. */
    private const int MAX_PATTERN_LENGTH = 2048;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/';

    /**
     * @return list<array{pointer: string, message: string}>
     */
    public static function enumIssues(mixed $value, mixed $enum, string $pointer): array
    {
        if (!is_array($enum) || $enum === [] || in_array($value, $enum, true)) {
            return [];
        }

        return [['pointer' => $pointer, 'message' => 'value is not one of the enumerated values']];
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function stringIssues(mixed $value, array $schema, string $pointer): array
    {
        if (!is_string($value)) {
            return [];
        }

        return [
            ...self::lengthIssues($value, $schema, $pointer),
            ...self::patternIssues($value, $schema, $pointer),
            ...self::formatIssues($value, $schema, $pointer),
        ];
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function numericIssues(mixed $value, array $schema, string $pointer): array
    {
        if (!is_int($value) && !is_float($value)) {
            return [];
        }
        $issues = [];
        $minimum = $schema['minimum'] ?? null;
        // Bounds may be floats — hand-written "minimum": 0.5 must be enforced
        // just like integer bounds (issue #315).
        if ((is_int($minimum) || is_float($minimum)) && $value < $minimum) {
            $issues[] = ['pointer' => $pointer, 'message' => "value is below the minimum {$minimum}"];
        }
        $maximum = $schema['maximum'] ?? null;
        if ((is_int($maximum) || is_float($maximum)) && $value > $maximum) {
            $issues[] = ['pointer' => $pointer, 'message' => "value is above the maximum {$maximum}"];
        }

        return $issues;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function lengthIssues(string $value, array $schema, string $pointer): array
    {
        $issues = [];
        $minLength = $schema['minLength'] ?? null;
        if (is_int($minLength) && mb_strlen($value) < $minLength) {
            $issues[] = ['pointer' => $pointer, 'message' => "string is shorter than minLength {$minLength}"];
        }
        $maxLength = $schema['maxLength'] ?? null;
        if (is_int($maxLength) && mb_strlen($value) > $maxLength) {
            $issues[] = ['pointer' => $pointer, 'message' => "string is longer than maxLength {$maxLength}"];
        }

        return $issues;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function patternIssues(string $value, array $schema, string $pointer): array
    {
        $pattern = $schema['pattern'] ?? null;
        if (!is_string($pattern) || $pattern === '') {
            return [];
        }
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return [['pointer' => $pointer, 'message' => 'schema pattern exceeds the 2048-character policy']];
        }

        return self::matchIssues($pattern, $value, $pointer);
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private static function matchIssues(string $pattern, string $value, string $pointer): array
    {
        $result = self::patternResult($pattern, $value);

        return match ($result) {
            0 => [['pointer' => $pointer, 'message' => 'value does not match the required pattern']],
            false => [['pointer' => $pointer, 'message' => 'schema pattern is invalid']],
            default => [],
        };
    }

    /**
     * JSON-Schema/OpenAPI document patterns are delimiter-less (the
     * document generator strips PHP delimiters when bridging the
     * validation engine), so the pattern is re-delimited before PCRE
     * sees it. A hand-written pattern that still fails to compile —
     * broken syntax, or a literal '~' colliding with the delimiter —
     * fails closed as an invalid-pattern issue instead of raising a
     * PHP warning: the scoped error handler keeps the suite's
     * fail-on-warning policy intact (no '@' suppression, php:S2002).
     */
    private static function patternResult(string $pattern, string $value): false|int
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('~(' . $pattern . ')~', $value);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function formatIssues(string $value, array $schema, string $pointer): array
    {
        $format = $schema['format'] ?? null;
        if (!is_string($format) || $format === '') {
            return [];
        }
        $ok = match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match(self::UUID_PATTERN, $value) === 1,
            'date-time' => preg_match(self::DATE_TIME_PATTERN, $value) === 1,
            default => true,
        };

        return $ok ? [] : [['pointer' => $pointer, 'message' => "value is not a valid {$format}"]];
    }
}
