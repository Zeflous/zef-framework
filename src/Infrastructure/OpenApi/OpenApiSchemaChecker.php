<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * JSON-Schema subset checker for the OpenAPI runtime gate (boundary B9).
 *
 * The enforced keyword set is EXACTLY the contract {@see Schema::toArray()}
 * emits (type, format, pattern, enum, minLength/maxLength, minimum/maximum,
 * items, minItems/maxItems, uniqueItems, properties, required,
 * additionalProperties, minProperties/maxProperties, nullable, $ref,
 * oneOf/anyOf/allOf) — the parity matrix documents this as the gate's
 * schema horizon: anything the document generator cannot emit is either
 * tolerated leniently (annotations, unknown formats, unknown type strings)
 * or fails closed with a deterministic issue.
 *
 * This class owns the RECURSIVE core (value traversal, $ref resolution,
 * array/object/composition structure); scalar constraints and the type
 * system live in {@see OpenApiScalarConstraints} and
 * {@see OpenApiSchemaTypes} (class-size budget, php:S2042 — the split is
 * non-recursive by construction, so no cycle is introduced).
 *
 * Two validation modes share one engine:
 * - string transport (query/header/cookie parameters): scalar values are
 *   coerced to the schema type first ('42' + type integer -> 42);
 * - decoded JSON bodies: types are checked strictly — a JSON string is
 *   never a JSON number (no coercion).
 */
final class OpenApiSchemaChecker
{
    /** Data-nesting bound: legit self-referencing schemas terminate on data depth, not schema. */
    public const int MAX_DEPTH = 64;

    private const string REF_PATTERN = '~^#/components/schemas/([^/]+)$~';

    /**
     * @param array<mixed, mixed> $schemas components.schemas (name => schema array)
     */
    public function __construct(
        private array $schemas,
    ) {}

    /**
     * @param mixed $schema the schema object as serialized in the document
     *
     * @return list<array{pointer: string, message: string}>
     */
    public function check(mixed $value, mixed $schema, bool $coerce): array
    {
        return $this->checkValue($value, $schema, $coerce, '', 0);
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private function checkValue(mixed $value, mixed $schema, bool $coerce, string $pointer, int $depth): array
    {
        $early = $this->earlyIssues($value, $schema, $pointer, $depth);
        if ($early !== null) {
            return $early;
        }

        /** @var array<mixed, mixed> $schema */
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            return $this->checkRef($value, $schema, $ref, $coerce, $pointer, $depth);
        }

        return $this->constraintIssues($value, $schema, $coerce, $pointer, $depth);
    }

    /**
     * The keyword families, on the coerced value. A type failure
     * short-circuits: constraint keywords would only add noise on a value
     * of the wrong shape.
     *
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function constraintIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        $typedValue = $coerce && is_string($value)
            ? OpenApiSchemaTypes::coerceString($value, $schema)
            : $value;
        $typeIssues = $this->typeIssues($typedValue, $schema['type'] ?? null, $pointer);
        if ($typeIssues !== []) {
            return $typeIssues;
        }

        return [
            ...OpenApiScalarConstraints::enumIssues($typedValue, $schema['enum'] ?? null, $pointer),
            ...OpenApiScalarConstraints::stringIssues($typedValue, $schema, $pointer),
            ...OpenApiScalarConstraints::numericIssues($typedValue, $schema, $pointer),
            ...$this->arrayIssues($typedValue, $schema, $coerce, $pointer, $depth),
            ...$this->objectIssues($typedValue, $schema, $coerce, $pointer, $depth),
            ...$this->compositionIssues($typedValue, $schema, $coerce, $pointer, $depth),
        ];
    }

    /**
     * Depth guard, non-object schemas and the nullable union — the three
     * early outcomes, null when validation should proceed.
     *
     * @return null|list<array{pointer: string, message: string}>
     */
    private function earlyIssues(mixed $value, mixed $schema, string $pointer, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return [['pointer' => $pointer, 'message' => 'schema nesting exceeds ' . self::MAX_DEPTH . ' levels']];
        }

        return match (true) {
            !is_array($schema) => [],
            // Nullable is honoured BEFORE the $ref branch: the document
            // generator emits {$ref, nullable: true} for nullable references
            // (OpenAPI 3.0-style union-with-null), so a null value must be
            // admitted by the sibling flag even when the referenced schema
            // itself only allows the base type.
            $value === null && ($schema['nullable'] ?? null) === true => [],
            default => null,
        };
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private function typeIssues(mixed $value, mixed $type, string $pointer): array
    {
        if (OpenApiSchemaTypes::typeMatches($value, $type)) {
            return [];
        }

        $message = 'expected ' . OpenApiSchemaTypes::typeLabel($type)
            . ', got ' . OpenApiSchemaTypes::valueTypeLabel($value);

        return [['pointer' => $pointer, 'message' => $message]];
    }

    /**
     * $ref semantics (JSON Schema 2020-12): the reference target applies,
     * then any sibling keywords apply as well. Builder output never emits
     * siblings, hand-written documents may.
     *
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function checkRef(
        mixed $value,
        array $schema,
        string $ref,
        bool $coerce,
        string $pointer,
        int $depth,
    ): array {
        $resolved = $this->resolveRef($ref);
        if ($resolved === null) {
            // Defensive: the boot-time spec walk already refuses unresolvable
            // refs (B12), so this is only reachable through direct checker use.
            return [['pointer' => $pointer, 'message' => "unresolvable \$ref '{$ref}'"]];
        }

        $issues = $this->checkValue($value, $resolved, $coerce, $pointer, $depth + 1);
        $siblings = $schema;
        unset($siblings['$ref']);
        if ($siblings !== []) {
            return [...$issues, ...$this->checkValue($value, $siblings, $coerce, $pointer, $depth + 1)];
        }

        return $issues;
    }

    /**
     * @return null|array<mixed, mixed>
     */
    private function resolveRef(string $ref): ?array
    {
        if (preg_match(self::REF_PATTERN, $ref, $matches) !== 1) {
            return null;
        }
        $resolved = $this->schemas[$matches[1]] ?? null;

        return is_array($resolved) ? $resolved : null;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function arrayIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return [];
        }
        $issues = $this->arrayBoundIssues($value, $schema, $pointer);

        return [...$issues, ...$this->itemsIssues($value, $schema, $coerce, $pointer, $depth)];
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function arrayBoundIssues(array $value, array $schema, string $pointer): array
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
    private function itemsIssues(array $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        $items = $schema['items'] ?? null;
        if ($items === null) {
            return [];
        }
        $issues = [];
        foreach ($value as $index => $element) {
            $issues = [...$issues, ...$this->checkValue(
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
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function objectIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }
        $issues = [
            ...$this->requiredIssues($value, $schema, $pointer),
            ...$this->propertyIssues($value, $schema, $coerce, $pointer, $depth),
        ];

        return [...$issues, ...$this->propertyCountIssues($value, $schema, $pointer)];
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function requiredIssues(array $value, array $schema, string $pointer): array
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
    private function propertyIssues(array $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        $issues = [];

        /** @var int|string $key */
        foreach ($value as $key => $itemValue) {
            $issues = [...$issues, ...$this->keyIssues(
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
     * additionalProperties policy.
     *
     * @param array<mixed, mixed> $properties
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function keyIssues(
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
        $token = $pointer . '/' . $this->escapePointerToken($key);
        if (array_key_exists($key, $properties)) {
            return $this->checkValue($itemValue, $properties[$key], $coerce, $token, $depth + 1);
        }
        if ($additional === false) {
            return [['pointer' => $pointer, 'message' => "additional property '{$key}' is not allowed"]];
        }
        if (is_array($additional) && $additional !== []) {
            return $this->checkValue($itemValue, $additional, $coerce, $token, $depth + 1);
        }

        return [];
    }

    /**
     * @param array<mixed>        $value
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function propertyCountIssues(array $value, array $schema, string $pointer): array
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

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<array{pointer: string, message: string}>
     */
    private function compositionIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        return [
            ...$this->oneOfIssues($value, $schema['oneOf'] ?? null, $coerce, $pointer, $depth),
            ...$this->anyOfIssues($value, $schema['anyOf'] ?? null, $coerce, $pointer, $depth),
            ...$this->allOfIssues($value, $schema['allOf'] ?? null, $coerce, $pointer, $depth),
        ];
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private function oneOfIssues(mixed $value, mixed $oneOf, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($oneOf) || $oneOf === []) {
            return [];
        }
        $matched = 0;
        foreach ($oneOf as $branch) {
            if (is_array($branch) && $this->checkValue($value, $branch, $coerce, $pointer, $depth + 1) === []) {
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
    private function anyOfIssues(mixed $value, mixed $anyOf, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($anyOf) || $anyOf === []) {
            return [];
        }
        foreach ($anyOf as $branch) {
            if (is_array($branch) && $this->checkValue($value, $branch, $coerce, $pointer, $depth + 1) === []) {
                return [];
            }
        }

        return [['pointer' => $pointer, 'message' => 'value matches none of the anyOf branches']];
    }

    /**
     * @return list<array{pointer: string, message: string}>
     */
    private function allOfIssues(mixed $value, mixed $allOf, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($allOf) || $allOf === []) {
            return [];
        }
        $issues = [];
        foreach ($allOf as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $issues = [...$issues, ...$this->checkValue($value, $branch, $coerce, $pointer, $depth + 1)];
        }

        return $issues;
    }

    /** JSON Pointer token escaping (RFC 6901): '~' -> '~0', '/' -> '~1'. */
    private function escapePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
