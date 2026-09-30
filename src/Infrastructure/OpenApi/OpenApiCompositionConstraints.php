<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The composition keyword family of the OpenAPI runtime gate: oneOf
 * (exactly one branch), anyOf (at least one) and allOf (every branch).
 * Extracted from {@see OpenApiStructureConstraints} for the class-size
 * budget (php:S2042); behaviour, messages and depth accounting are
 * carried over verbatim. The recursion runs through the checker's public
 * {@see OpenApiSchemaChecker::checkAt()} entry, so the class stays
 * stateless with no construction cycle.
 */
final class OpenApiCompositionConstraints
{
    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    public static function issues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        array $schema,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        return [
            ...self::oneOfIssues($checker, $value, $schema['oneOf'] ?? null, $coerce, $pointer, $depth),
            ...self::anyOfIssues($checker, $value, $schema['anyOf'] ?? null, $coerce, $pointer, $depth),
            ...self::allOfIssues($checker, $value, $schema['allOf'] ?? null, $coerce, $pointer, $depth),
        ];
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private static function oneOfIssues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        mixed $oneOf,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        if (!is_array($oneOf) || $oneOf === []) {
            return [];
        }
        $matched = 0;
        foreach ($oneOf as $branch) {
            if (is_array($branch) && $checker->checkAt($value, $branch, $coerce, $pointer, $depth + 1) === []) {
                ++$matched;
            }
        }

        return match ($matched) {
            1 => [],
            0 => [['pointer' => $pointer, 'message' => 'value matches none of the oneOf branches']],
            default => [['pointer' => $pointer, 'message' => 'value matches more than one oneOf branch']],
        };
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private static function anyOfIssues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        mixed $anyOf,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        if (!is_array($anyOf) || $anyOf === []) {
            return [];
        }
        foreach ($anyOf as $branch) {
            if (is_array($branch) && $checker->checkAt($value, $branch, $coerce, $pointer, $depth + 1) === []) {
                return [];
            }
        }

        return [['pointer' => $pointer, 'message' => 'value matches none of the anyOf branches']];
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private static function allOfIssues(
        OpenApiSchemaChecker $checker,
        mixed $value,
        mixed $allOf,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        if (!is_array($allOf) || $allOf === []) {
            return [];
        }
        $issues = [];
        foreach ($allOf as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $issues = [...$issues, ...$checker->checkAt($value, $branch, $coerce, $pointer, $depth + 1)];
        }

        return $issues;
    }
}
