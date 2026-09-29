<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Schema Object (subset relevant to ZEF documentation output).
 *
 * Immutable recursive value object. When `ref` is set the instance is a
 * reference placeholder and toArray() emits only the $ref key (plus
 * nullable when requested); all sibling constraints are ignored.
 *
 * ReDoS policy: pattern length is bounded (2048) mirroring the framework
 * validation engine.
 */
final readonly class Schema
{
    private const int MAX_PATTERN_LENGTH = 2048;

    /**
     * @param list<string> $required
     * @param array<string, Schema> $properties
     * @param null|list<Schema> $oneOf
     * @param null|list<Schema> $anyOf
     * @param null|list<Schema> $allOf
     * @param list<bool|float|int|string> $enum
     */
    public function __construct(
        public SchemaType $type = SchemaType::Object,
        public ?string $format = null,
        public ?string $description = null,
        public ?string $title = null,
        public ?string $ref = null,
        public ?bool $nullable = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public ?bool $deprecated = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
        public array $required = [],
        public array $properties = [],
        public ?Schema $items = null,
        public ?array $oneOf = null,
        public ?array $anyOf = null,
        public ?array $allOf = null,
        public ?Schema $additionalProperties = null,
        public ?bool $additionalPropertiesAllowed = null,
        public mixed $default = null,
        public mixed $example = null,
        public ?array $enum = null,
    ) {
        $this->assertShape();
        $this->assertBounds();
        $this->assertRequired();
        $this->assertProperties();
        $this->assertEnum();
    }

    /**
     * Immutable nullable-derivative: same constraints, nullable forced on.
     * Ref-only schemas keep their $ref and only flip the nullable flag.
     */
    public function asNullable(): self
    {
        if ($this->nullable === true) {
            return $this;
        }

        return new self(
            type: $this->type,
            format: $this->format,
            description: $this->description,
            title: $this->title,
            ref: $this->ref,
            nullable: true,
            readOnly: $this->readOnly,
            writeOnly: $this->writeOnly,
            deprecated: $this->deprecated,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
            minimum: $this->minimum,
            maximum: $this->maximum,
            minItems: $this->minItems,
            maxItems: $this->maxItems,
            uniqueItems: $this->uniqueItems,
            minProperties: $this->minProperties,
            maxProperties: $this->maxProperties,
            required: $this->required,
            properties: $this->properties,
            items: $this->items,
            oneOf: $this->oneOf,
            anyOf: $this->anyOf,
            allOf: $this->allOf,
            additionalProperties: $this->additionalProperties,
            additionalPropertiesAllowed: $this->additionalPropertiesAllowed,
            default: $this->default,
            example: $this->example,
            enum: $this->enum,
        );
    }

    /**
     * Recursive serialization; null/unset constraints are omitted so the
     * emitted document stays minimal and deterministic.
     *
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
     *     minimum?: int,
     *     maximum?: int,
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
    public function toArray(): array
    {
        // Serialization lives in SchemaArraySerializer (class-size budget);
        // the shape contract is documented above and preserved verbatim.
        return SchemaArraySerializer::toArray($this);
    }

    private function assertShape(): void
    {
        if ($this->type === SchemaType::Array && !$this->items instanceof Schema && $this->ref === null) {
            throw new SchemaDefinitionException('Array schema must define items.');
        }
        if ($this->pattern !== null && ($this->pattern === '' || strlen($this->pattern) > self::MAX_PATTERN_LENGTH)) {
            throw new SchemaDefinitionException('Schema pattern length must be 1..2048.');
        }
    }

    private function assertBounds(): void
    {
        if ($this->minLength !== null && $this->minLength < 0) {
            throw new SchemaDefinitionException("Schema minLength must be >= 0 (got {$this->minLength}).");
        }
        if ($this->maxLength !== null && $this->maxLength < 1) {
            throw new SchemaDefinitionException("Schema maxLength must be >= 1 (got {$this->maxLength}).");
        }
        if ($this->minItems !== null && $this->minItems < 0) {
            throw new SchemaDefinitionException("Schema minItems must be >= 0 (got {$this->minItems}).");
        }
        if ($this->maxItems !== null && $this->maxItems < 1) {
            throw new SchemaDefinitionException("Schema maxItems must be >= 1 (got {$this->maxItems}).");
        }
        if ($this->minProperties !== null && $this->minProperties < 0) {
            throw new SchemaDefinitionException("Schema minProperties must be >= 0 (got {$this->minProperties}).");
        }
        if ($this->maxProperties !== null && $this->maxProperties < 1) {
            throw new SchemaDefinitionException("Schema maxProperties must be >= 1 (got {$this->maxProperties}).");
        }
    }

    private function assertRequired(): void
    {
        foreach ($this->required as $field) {
            if (!is_string($field) || trim($field) === '') {
                throw new SchemaDefinitionException('Schema required entries must be non-empty strings.');
            }
        }
        if ($this->required !== [] && array_unique($this->required) !== $this->required) {
            throw new SchemaDefinitionException('Schema required entries must be unique.');
        }
    }

    private function assertProperties(): void
    {
        foreach ($this->properties as $name => $property) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 128) {
                throw new SchemaDefinitionException(
                    'Schema property names must be non-empty strings of at most 128 characters.',
                );
            }
            if (!$property instanceof self) {
                throw new SchemaDefinitionException("Schema property '{$name}' must be a Schema instance.");
            }
        }
    }

    private function assertEnum(): void
    {
        if ($this->enum !== null && $this->enum === []) {
            throw new SchemaDefinitionException('Schema enum must be a non-empty list when provided.');
        }
        foreach ($this->enum ?? [] as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new SchemaDefinitionException('Schema enum values must be scalars.');
            }
        }
    }
}
