<?php

declare(strict_types=1);

// ZEF Framework — Application layer (OpenAPI documentation module).
// Extracted from SchemaGenerator during the sonar-zero campaign.

namespace Zef\Framework\OpenApi;

/**
 * @internal mutable accumulator for the OpenAPI constraints inferred from
 * one field's FieldRules chain. A fresh instance is built per field; the
 * LAST rule of a kind wins, mirroring the validation engine's semantics.
 */
final class FieldSchemaConstraints
{
    private ?string $kind = null;

    private ?string $format = null;

    private ?int $minLength = null;

    private ?int $maxLength = null;

    private ?int $minimum = null;

    private ?int $maximum = null;

    private ?string $pattern = null;

    /** @var null|list<int|string> */
    private ?array $enum = null;

    private bool $nullable = false;

    /**
     * @param array{rule: string, params: array<string, mixed>, nullable: bool} $definition
     */
    public function apply(array $definition): void
    {
        $this->nullable = $this->nullable || $definition['nullable'];
        $params = $definition['params'];

        switch ($definition['rule']) {
            case 'type':
                $kind = $params['kind'] ?? null;

                if (is_string($kind)) {
                    $this->kind = $kind;
                }

                break;

            case 'min_length':
                $min = $params['min'] ?? null;

                if (is_int($min)) {
                    $this->minLength = $min;
                }

                break;

            case 'max_length':
                $max = $params['max'] ?? null;

                if (is_int($max)) {
                    $this->maxLength = $max;
                }

                break;

            case 'min':
                $this->minimum = $this->asIntLike($params['bound'] ?? null) ?? $this->minimum;

                break;

            case 'max':
                $this->maximum = $this->asIntLike($params['bound'] ?? null) ?? $this->maximum;

                break;

            case 'email':
            case 'uuid':
                $format = $params['format'] ?? null;

                if (is_string($format)) {
                    $this->format = $format;
                }

                break;

            case 'in':
                $this->applyAllowedValues($params['allowed'] ?? null);

                break;

            case 'pattern':
                $regex = $params['regex'] ?? null;

                if (is_string($regex)) {
                    $this->pattern = $this->stripPatternDelimiters($regex);
                }

                break;

            default:
                // Rules without an OpenAPI constraint mapping (required,
                // confirmed, custom closures, ...) contribute nothing here.
                break;
        }
    }

    public function toSchema(): Schema
    {
        return new Schema(
            type: match ($this->kind) {
                'integer' => SchemaType::Integer,
                'numeric' => SchemaType::Number,
                default => SchemaType::String,
            },
            format: $this->format,
            nullable: $this->nullable ? true : null,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            minimum: $this->minimum,
            maximum: $this->maximum,
            enum: $this->enum !== null ? array_values($this->enum) : null,
        );
    }

    private function applyAllowedValues(mixed $allowed): void
    {
        if (!is_array($allowed)) {
            return;
        }

        $filtered = array_filter($allowed, static fn (mixed $v): bool => is_string($v) || is_int($v));
        $this->enum = $filtered !== [] ? array_values($filtered) : null;
    }

    private function asIntLike(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * OpenAPI patterns are undelimited ECMA regexes while the validation
     * engine stores delimited PHP regexes ("/^...$/i"). Strip the outer
     * delimiter and trailing flags with a conservative heuristic; input
     * that cannot be parsed is emitted verbatim.
     */
    private function stripPatternDelimiters(string $regex): string
    {
        if (strlen($regex) < 3) {
            return $regex;
        }

        $delimiter = $regex[0];
        $last = strrpos($regex, $delimiter);
        // $last === 0 means no pattern body; $last === false means no
        // closing delimiter at all. Both fall through verbatim.
        $isRealDelimiter = preg_match('/^[A-Za-z0-9\\\]$/', $delimiter) !== 1 && $last !== false && $last > 0;

        if (!$isRealDelimiter) {
            return $regex;
        }

        return substr($regex, 1, $last - 1);
    }
}
