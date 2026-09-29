<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Parameter Object — one operation parameter (path/query/header/cookie).
 *
 * Path parameters are always required per the OpenAPI specification; the
 * constructor enforces this invariant up front.
 */
final readonly class Parameter
{
    public function __construct(
        public string $name,
        public ParameterLocation $in,
        public ?Schema $schema = null,
        public string $description = '',
        public ?bool $required = null,
        public bool $deprecated = false,
        public mixed $example = null,
    ) {
        if (trim($name) === '' || mb_strlen($name) > 128) {
            throw new SchemaDefinitionException('Parameter name must be a non-empty string of at most 128 characters.');
        }
        if ($this->in === ParameterLocation::Path && $this->required === false) {
            throw new SchemaDefinitionException("Path parameter '{$name}' must be required.");
        }
    }

    public function isRequired(): bool
    {
        return $this->in === ParameterLocation::Path || $this->required === true;
    }

    /**
     * @return array{
     *     name: string,
     *     in: string,
     *     schema?: array<string, mixed>,
     *     description?: string,
     *     required?: true,
     *     deprecated?: true,
     *     example?: mixed,
     * }
     */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'in' => $this->in->value,
        ];
        if ($this->schema instanceof Schema) {
            $out['schema'] = $this->schema->toArray();
        }
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->isRequired()) {
            $out['required'] = true;
        }
        if ($this->deprecated) {
            $out['deprecated'] = true;
        }
        if ($this->example !== null) {
            $out['example'] = $this->example;
        }

        return $out;
    }
}
