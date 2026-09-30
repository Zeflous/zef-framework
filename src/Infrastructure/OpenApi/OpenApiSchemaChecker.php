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
 * Two validation modes share one engine:
 * - string transport (query/header/cookie parameters): scalar values are
 *   coerced to the schema type first ('42' + type integer -> 42);
 * - decoded JSON bodies: types are checked strictly — a JSON string is
 *   never a JSON number (no coercion).
 *
 * @phpstan-type CheckerIssue array{pointer: string, message: string}
 */
final class OpenApiSchemaChecker
{
    /** Data-nesting bound: legit self-referencing schemas terminate on data depth, not schema. */
    private const int MAX_DEPTH = 64;

    /** House ReDoS policy, mirrored from the Schema value object. */
    private const int MAX_PATTERN_LENGTH = 2048;

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/';

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
     * @return list<CheckerIssue>
     */
    public function check(mixed $value, mixed $schema, bool $coerce): array
    {
        return $this->checkValue($value, $schema, $coerce, '', 0);
    }

    /**
     * @return list<CheckerIssue>
     */
    private function checkValue(mixed $value, mixed $schema, bool $coerce, string $pointer, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [['pointer' => $pointer, 'message' => 'schema nesting exceeds ' . self::MAX_DEPTH . ' levels']];
        }
        if (!is_array($schema)) {
            // Absent or non-object schema = no constraints to enforce.
            return [];
        }

        // Nullable is honoured BEFORE the $ref branch: the document
        // generator emits {$ref, nullable: true} for nullable references
        // (OpenAPI 3.0-style union-with-null), so a null value must be
        // admitted by the sibling flag even when the referenced schema
        // itself only allows the base type.
        if ($value === null && ($schema['nullable'] ?? null) === true) {
            return [];
        }

        $ref = $schema['$ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            return $this->checkRef($value, $schema, $ref, $coerce, $pointer, $depth);
        }

        if ($coerce && is_string($value)) {
            $value = $this->coerceString($value, $schema);
        }

        $type = $schema['type'] ?? null;
        if (!$this->typeMatches($value, $type)) {
            return [[
                'pointer' => $pointer,
                'message' => 'expected ' . $this->typeLabel($type) . ', got ' . $this->valueTypeLabel($value),
            ]];
        }

        $issues = [];
        $issues = [...$issues, ...$this->enumIssues($value, $schema['enum'] ?? null, $pointer)];
        $issues = [...$issues, ...$this->stringIssues($value, $schema, $pointer)];
        $issues = [...$issues, ...$this->numericIssues($value, $schema, $pointer)];
        $issues = [...$issues, ...$this->arrayIssues($value, $schema, $coerce, $pointer, $depth)];
        $issues = [...$issues, ...$this->objectIssues($value, $schema, $coerce, $pointer, $depth)];

        return [...$issues, ...$this->compositionIssues($value, $schema, $coerce, $pointer, $depth)];
    }

    /**
     * $ref semantics (JSON Schema 2020-12): the reference target applies,
     * then any sibling keywords apply as well. Builder output never emits
     * siblings, hand-written documents may.
     *
     * @param array<mixed, mixed> $schema
     *
     * @return list<CheckerIssue>
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
     */
    private function coerceString(string $value, array $schema): bool|float|int|string
    {
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            return $value;
        }

        return match ($type) {
            'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            'number' => is_numeric($value) ? +$value : $value,
            'boolean' => $this->coerceBool($value),
            default => $value,
        };
    }

    private function coerceBool(string $value): bool|string
    {
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0') {
            return false;
        }

        return $value;
    }

    private function typeMatches(mixed $value, mixed $type): bool
    {
        if ($type === null || (is_array($type) && $type === [])) {
            return true;
        }
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            // An empty PHP array satisfies both 'array' and 'object' — the
            // JSON encoding of [] vs {} is lost in associative decoding.
            if (match ($candidate) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && ($value === [] || array_is_list($value)),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                'null' => $value === null,
                default => true,
            }) {
                return true;
            }
        }

        return false;
    }

    private function typeLabel(mixed $type): string
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

    private function valueTypeLabel(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_array($value) => $value === [] ? 'empty array' : (array_is_list($value) ? 'array' : 'object'),
            $value === null => 'null',
            default => 'unknown',
        };
    }

    /**
     * @return list<CheckerIssue>
     */
    private function enumIssues(mixed $value, mixed $enum, string $pointer): array
    {
        if (!is_array($enum) || $enum === [] || in_array($value, $enum, true)) {
            return [];
        }

        return [['pointer' => $pointer, 'message' => 'value is not one of the enumerated values']];
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
    private function patternResult(string $pattern, string $value): false|int
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
     * @return list<CheckerIssue>
     */
    private function stringIssues(mixed $value, array $schema, string $pointer): array
    {
        if (!is_string($value)) {
            return [];
        }
        $issues = [];

        $minLength = $schema['minLength'] ?? null;
        if (is_int($minLength) && mb_strlen($value) < $minLength) {
            $issues[] = ['pointer' => $pointer, 'message' => "string is shorter than minLength {$minLength}"];
        }
        $maxLength = $schema['maxLength'] ?? null;
        if (is_int($maxLength) && mb_strlen($value) > $maxLength) {
            $issues[] = ['pointer' => $pointer, 'message' => "string is longer than maxLength {$maxLength}"];
        }

        $pattern = $schema['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
                $issues[] = ['pointer' => $pointer, 'message' => 'schema pattern exceeds the 2048-character policy'];
            } else {
                $result = $this->patternResult($pattern, $value);
                if ($result === 0) {
                    $issues[] = ['pointer' => $pointer, 'message' => 'value does not match the required pattern'];
                } elseif ($result === false) {
                    // Reachable through hand-written documents: the boot-time
                    // structural validator does not compile patterns.
                    $issues[] = ['pointer' => $pointer, 'message' => 'schema pattern is invalid'];
                }
            }
        }

        $format = $schema['format'] ?? null;
        if (is_string($format) && $format !== '') {
            $ok = match ($format) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'uuid' => preg_match(self::UUID_PATTERN, $value) === 1,
                'date-time' => preg_match(self::DATE_TIME_PATTERN, $value) === 1,
                default => true,
            };
            if (!$ok) {
                $issues[] = ['pointer' => $pointer, 'message' => "value is not a valid {$format}"];
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<CheckerIssue>
     */
    private function numericIssues(mixed $value, array $schema, string $pointer): array
    {
        if (!is_int($value) && !is_float($value)) {
            return [];
        }
        $issues = [];

        $minimum = $schema['minimum'] ?? null;
        if (is_int($minimum) && $value < $minimum) {
            $issues[] = ['pointer' => $pointer, 'message' => "value is below the minimum {$minimum}"];
        }
        $maximum = $schema['maximum'] ?? null;
        if (is_int($maximum) && $value > $maximum) {
            $issues[] = ['pointer' => $pointer, 'message' => "value is above the maximum {$maximum}"];
        }

        return $issues;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<CheckerIssue>
     */
    private function arrayIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return [];
        }
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

        $items = $schema['items'] ?? null;
        if ($items !== null) {
            foreach ($value as $index => $element) {
                $issues = [...$issues, ...$this->checkValue(
                    $element,
                    $items,
                    $coerce,
                    $pointer . '/' . $index,
                    $depth + 1,
                )];
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed, mixed> $schema
     *
     * @return list<CheckerIssue>
     */
    private function objectIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }
        $issues = [];

        $required = $schema['required'] ?? null;
        if (is_array($required)) {
            foreach ($required as $name) {
                if (is_string($name) && !array_key_exists($name, $value)) {
                    $issues[] = ['pointer' => $pointer, 'message' => "missing required property '{$name}'"];
                }
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        foreach ($value as $key => $itemValue) {
            if (array_key_exists($key, $properties)) {
                $issues = [...$issues, ...$this->checkValue(
                    $itemValue,
                    $properties[$key],
                    $coerce,
                    $pointer . '/' . $this->escapePointerToken((string) $key),
                    $depth + 1,
                )];

                continue;
            }
            if ($additional === false) {
                $issues[] = [
                    'pointer' => $pointer,
                    'message' => "additional property '{$key}' is not allowed",
                ];

                continue;
            }
            if (is_array($additional) && $additional !== []) {
                $issues = [...$issues, ...$this->checkValue(
                    $itemValue,
                    $additional,
                    $coerce,
                    $pointer . '/' . $this->escapePointerToken((string) $key),
                    $depth + 1,
                )];
            }
        }

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
     * @return list<CheckerIssue>
     */
    private function compositionIssues(mixed $value, array $schema, bool $coerce, string $pointer, int $depth): array
    {
        $issues = [];

        $oneOf = $schema['oneOf'] ?? null;
        if (is_array($oneOf) && $oneOf !== []) {
            $matched = 0;
            foreach ($oneOf as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if ($this->checkValue($value, $branch, $coerce, $pointer, $depth + 1) === []) {
                    ++$matched;
                }
            }
            if ($matched !== 1) {
                $issues[] = [
                    'pointer' => $pointer,
                    'message' => $matched === 0
                        ? 'value matches none of the oneOf branches'
                        : 'value matches more than one oneOf branch',
                ];
            }
        }

        $anyOf = $schema['anyOf'] ?? null;
        if (is_array($anyOf) && $anyOf !== []) {
            $matched = false;
            foreach ($anyOf as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if ($this->checkValue($value, $branch, $coerce, $pointer, $depth + 1) === []) {
                    $matched = true;

                    break;
                }
            }
            if (!$matched) {
                $issues[] = ['pointer' => $pointer, 'message' => 'value matches none of the anyOf branches'];
            }
        }

        $allOf = $schema['allOf'] ?? null;
        if (is_array($allOf) && $allOf !== []) {
            foreach ($allOf as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $issues = [...$issues, ...$this->checkValue($value, $branch, $coerce, $pointer, $depth + 1)];
            }
        }

        return $issues;
    }

    /** JSON Pointer token escaping (RFC 6901): '~' -> '~0', '/' -> '~1'. */
    private function escapePointerToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
