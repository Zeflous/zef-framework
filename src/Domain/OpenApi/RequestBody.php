<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Request Body Object.
 *
 * Content maps media type ranges to schemas; the map must be non-empty.
 */
final readonly class RequestBody
{
    /**
     * @param array<string, Schema> $content
     */
    public function __construct(
        public array $content,
        public string $description = '',
        public bool $required = false,
    ) {
        if ($content === []) {
            throw new SchemaDefinitionException('RequestBody content must define at least one media type.');
        }
        foreach ($content as $mediaType => $schema) {
            if (!is_string($mediaType) || trim($mediaType) === '' || !$schema instanceof Schema) {
                throw new SchemaDefinitionException(
                    'RequestBody content keys must be non-empty media type strings'
                    . ' mapping to Schema instances.',
                );
            }
        }
    }

    /**
     * @return array{content: array<string, array{schema: array<string, mixed>}>, description?: string, required?: true}
     */
    public function toArray(): array
    {
        $out = [
            'content' => array_map(
                static fn (Schema $schema): array => ['schema' => $schema->toArray()],
                $this->content,
            ),
        ];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->required) {
            $out['required'] = true;
        }

        return $out;
    }
}
