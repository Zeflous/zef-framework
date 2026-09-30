<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The OpenAPI runtime gate's recursive schema-check core: value traversal,
 * the depth guard, $ref resolution and the keyword-family delegation.
 * Scalar constraints live in {@see OpenApiScalarConstraints}, the type
 * system in {@see OpenApiSchemaTypes} and the array/object/composition
 * structure in {@see OpenApiStructureConstraints} (class-size and
 * method-count budgets, php:S2042 / php:S1448).
 *
 * The enforced keyword set is EXACTLY the contract {@see Schema::toArray()}
 * emits (the parity matrix's schema horizon); anything the document
 * generator cannot emit is either tolerated leniently (annotations,
 * unknown formats, unknown type strings) or fails closed with a
 * deterministic issue.
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
        return $this->checkAt($value, $schema, $coerce, '', 0);
    }

    /**
     * The recursion entry the structural constraint families use.
     *
     * @return list<array{pointer: string, message: string}>
     */
    public function checkAt(mixed $value, mixed $schema, bool $coerce, string $pointer, int $depth): array
    {
        $early = $this->earlyIssues($value, $schema, $pointer, $depth);
        if ($early !== null) {
            return $early;
        }

        // Non-object schemas impose nothing (the ternary also narrows the
        // type for the ref check and every keyword family below, without a
        // docblock guard the inline-doc sniff would reject).
        $schema = is_array($schema) ? $schema : [];
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
            ...OpenApiStructureConstraints::arrayIssues($this, $typedValue, $schema, $coerce, $pointer, $depth),
            ...OpenApiStructureConstraints::objectIssues($this, $typedValue, $schema, $coerce, $pointer, $depth),
            ...OpenApiCompositionConstraints::issues($this, $typedValue, $schema, $coerce, $pointer, $depth),
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
            // Nullable is honoured BEFORE the $ref branch: the document
            // generator emits {$ref, nullable: true} for nullable references
            // (OpenAPI 3.0-style union-with-null), so a null value must be
            // admitted by the sibling flag even when the referenced schema
            // itself only allows the base type.
            $value === null && is_array($schema) && ($schema['nullable'] ?? null) === true => [],
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

        $issues = $this->checkAt($value, $resolved, $coerce, $pointer, $depth + 1);
        $siblings = $schema;
        unset($siblings['$ref']);
        if ($siblings !== []) {
            return [...$issues, ...$this->checkAt($value, $siblings, $coerce, $pointer, $depth + 1)];
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
}
