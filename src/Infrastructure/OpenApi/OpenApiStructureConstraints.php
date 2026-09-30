<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Structural constraints of the OpenAPI runtime gate: the array and
 * object keyword families (the composition family lives in
 * {@see OpenApiCompositionConstraints}). Extracted from
 * {@see OpenApiSchemaChecker} for the class-size and method-count budgets
 * (php:S2042 / php:S1448); behaviour, messages, pointer accumulation and
 * depth accounting are carried over verbatim.
 *
 * Stateless by construction: the recursive core is reached through the
 * checker's public {@see OpenApiSchemaChecker::checkAt()} entry, so no
 * instance or cycle is needed.
 */
final class OpenApiStructureConstraints
{
    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function arrayIssues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        array $schema,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return [];
        }

        return [
            ...self::arrayBoundIssues($value, $schema, $pointer),
            ...self::itemsIssues($checker, $value, $schema, $coerce, $pointer, $depth),
        ];
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function objectIssues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        array $schema,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }

        return [
            ...self::requiredIssues($value, $schema, $pointer),
            ...self::propertyIssues($checker, $value, $schema, $coerce, $pointer, $depth),
            ...self::propertyCountIssues($value, $schema, $pointer),
        ];
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function arrayBoundIssues(array $value, array $schema, string $pointer): array
    {
        $issues = [];
        $minItems = $schema['minItems'] ?? null;
        if (is_int($minItems) && count($value) < $minItems) {
            $issues[] = ['pointer' => $pointer, 'message' => "array has fewer than minItems {$minItems}"];
        }
        $maxItems = $schema['maxItems'] ?? null;
        if (is_int($maxItems) && count($value) > $maxItems) {
            $issues[] = ['pointer' => $pointer, 'message' => "array has more than maxItems {$maxItems}"];
        }
        if (($schema['uniqueItems'] ?? null) === true) {
            $serialized = array_map(serialize(...), $value);
            if (count(array_unique($serialized)) !== count($serialized)) {
                $issues[] = ['pointer' => $pointer, 'message' => 'array items are not unique'];
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function itemsIssues(
        OpenApiSchemaChecker $checker,
        array $value,
        array $schema,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        $items = $schema['items'] ?? null;
        if ($items === null) {
            return [];
        }
        $issues = [];
        foreach ($value as $index => $element) {
            $issues = [...$issues, ...$checker->checkAt(
                $element,
                $items,
                $coerce,
                $pointer . '/' . $index,
                $depth + 1,
            )];
        }

        return $issues;
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function requiredIssues(array $value, array $schema, string $pointer): array
    {
        $required = $schema['required'] ?? null;
        if (!is_array($required)) {
            return [];
        }
        $issues = [];
        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $value)) {
                $issues[] = ['pointer' => $pointer, 'message' => "missing required property '{$name}'"];
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function propertyIssues(
        OpenApiSchemaChecker $checker,
        array $value,
        array $schema,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        $issues = [];
        foreach ($value as $key => $itemValue) {
            $issues = [...$issues, ...self::keyIssues(
                $checker,
                $key,
                $itemValue,
                $properties,
                $additional,
                $coerce,
                $pointer,
                $depth,
            )];
        }

        return $issues;
    }

    /**
     * One object key against the declared properties and the
     * additionalProperties policy: the declared-property recursion, the
     * closed-policy issue, or nothing.
     *
     * @param array<mixed, mixed> $properties
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function keyIssues(
        OpenApiSchemaChecker $checker,
        mixed $key,
        mixed $itemValue,
        array $properties,
        mixed $additional,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        $key = is_int($key) ? (string) $key : $key;
        if (!is_string($key)) {
            return [];
        }
        $token = $pointer . '/' . self::escapePointerToken($key);
        if (array_key_exists($key, $properties)) {
            return $checker->checkAt($itemValue, $properties[$key], $coerce, $token, $depth + 1);
        }
        if ($additional === false) {
            return [['pointer' => $pointer, 'message' => "additional property '{$key}' is not allowed"]];
        }
        if (is_array($additional) && $additional !== []) {
            return $checker->checkAt($itemValue, $additional, $coerce, $token, $depth + 1);
        }

        return [];
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private static function propertyCountIssues(array $value, array $schema, string $pointer): array
    {
        $issues = [];
        $minProperties = $schema['minProperties'] ?? null;
        if (is_int($minProperties) && count($value) < $minProperties) {
            $issues[] = ['pointer' => $pointer, 'message' => "object has fewer than minProperties {$minProperties}"];
        }
        $maxProperties = $schema['maxProperties'] ?? null;
        if (is_int($maxProperties) && count($value) > $maxProperties) {
            $issues[] = ['pointer' => $pointer, 'message' => "object has more than maxProperties {$maxProperties}"];
        }

        return $issues;
    }

    /** JSON Pointer token escaping (RFC 6901): '~' -> '~0', '/' -> '~1'. */
    private static function escapePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
