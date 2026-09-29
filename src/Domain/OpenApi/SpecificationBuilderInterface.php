<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Contract for assembling a complete OpenAPI document from parts.
 *
 * Implemented by the Application-layer SpecificationBuilder; Infrastructure
 * adapters consume the built array for serialization/caching.
 *
 * @phpstan-type SpecArray array<string, mixed>
 */
interface SpecificationBuilderInterface
{
    public function addServer(Server $server): self;

    public function addOperation(Operation $operation): self;

    public function addSchema(string $name, Schema $schema): self;

    public function addSecurityScheme(string $name, SecurityScheme $scheme): self;

    public function addTag(Tag $tag): self;

    /**
     * Assemble the full specification document as a plain array ready for
     * JSON/YAML serialization.
     *
     * @return array{
     *     openapi: string,
     *     info: array<string, mixed>,
     *     paths: array<string, array<string, array<string, mixed>>>,
     *     servers?: list<array<string, mixed>>,
     *     tags?: list<array<string, mixed>>,
     *     components?: array{
     *         schemas?: array<string, array<string, mixed>>,
     *         securitySchemes?: array<string, array<string, mixed>>
     *     }
     * }
     */
    public function build(): array;
}
