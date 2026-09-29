<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Response Object. A non-empty description is mandated by the
 * OpenAPI specification for every response.
 */
final readonly class Response
{
    /**
     * @param array<string, Schema> $content
     */
    public function __construct(
        public string $description,
        public array $content = [],
    ) {
        if (trim($description) === '') {
            throw new SchemaDefinitionException('Response description must not be empty.');
        }
        foreach ($content as $mediaType => $schema) {
            if (!is_string($mediaType) || trim($mediaType) === '' || !$schema instanceof Schema) {
                throw new SchemaDefinitionException(
                    'Response content keys must be non-empty media type strings mapping to Schema instances.'
                );
            }
        }
    }

    /**
     * @return array{description: string, content?: array<string, array{schema: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        $out = ['description' => $this->description];
        if ($this->content !== []) {
            $out['content'] = array_map(
                static fn (Schema $schema): array => ['schema' => $schema->toArray()],
                $this->content,
            );
        }

        return $out;
    }
}
