<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Mutable collector used while one handler class's attributes are read.
 * Each apply*() method ports one attribute family from the former
 * RouteSpecExtractor::collectMethodAttributes() body (schema construction
 * delegated to SchemaContentBuilder); tags are deduplicated once by
 * toMetadata(), exactly as before.
 */
final class RouteMetadataAccumulator
{
    public string $summary = '';

    public string $description = '';

    /** @var list<string> */
    public array $tags = [];

    /** @var list<Tag> */
    public array $classTags = [];

    /** @var list<Parameter> */
    public array $parameters = [];

    public ?RequestBody $requestBody = null;

    /** @var array<int|string, Response> */
    public array $responses = [];

    public bool $deprecated = false;

    /** @var list<SecurityRequirement> */
    public array $classSecurity = [];

    /** @var ?list<SecurityRequirement> */
    public ?array $methodSecurity = null;

    public ?string $operationIdOverride = null;

    private ?SchemaContentBuilder $schemaContent = null;

    public function applyClassTag(Attribute\Tag $meta): void
    {
        if ($meta->description !== '') {
            $this->classTags[] = new Tag($meta->name, $meta->description);
        }
        $this->tags[] = $meta->name;
    }

    public function applyClassSecurity(Attribute\Security $meta): void
    {
        $this->classSecurity[] = new SecurityRequirement([$meta->scheme => $meta->scopes]);
    }

    public function applyRoute(Attribute\Route $meta): void
    {
        $this->summary = $meta->summary;
        $this->description = $meta->description;
        if ($meta->tags !== []) {
            $this->tags = [...$this->tags, ...$meta->tags];
        }
        if ($meta->operationId !== null) {
            $this->operationIdOverride = $meta->operationId;
        }
        if ($meta->deprecated) {
            $this->deprecated = true;
        }
    }

    public function applyParameter(Attribute\Parameter $meta): void
    {
        $this->parameters[] = new Parameter(
            name: $meta->name,
            in: $meta->in,
            schema: $this->schemaContent()->parameterSchema($meta),
            description: $meta->description,
            required: $meta->required,
            deprecated: $meta->deprecated,
            example: $meta->example,
        );
    }

    /**
     * At most one #[RequestBody] per operation; the message is part of the
     * extractor's public error contract.
     */
    public function applyRequestBody(
        Attribute\RequestBody $meta,
        SchemaGenerator $generator,
        string $handlerClass,
        string $sourceName,
    ): void {
        if ($this->requestBody instanceof RequestBody) {
            $method = "{$handlerClass}::{$sourceName}()";
            throw new SpecificationException(
                "Handler {$method} declares more than one #[RequestBody]; at most one is allowed per operation."
            );
        }
        $this->requestBody = new RequestBody(
            content: $this->schemaContent()->requestBodyContent($meta, $generator),
            description: $meta->description,
            required: $meta->required,
        );
    }

    public function applyResponse(Attribute\Response $meta, SchemaGenerator $generator): void
    {
        $statusKey = (string) $meta->status;
        $this->responses[$statusKey] = new Response(
            description: $meta->description !== '' ? $meta->description : 'Response.',
            content: $this->schemaContent()->responseContent($meta, $generator),
        );
    }

    public function applyMethodSecurity(Attribute\Security $meta): void
    {
        $requirements = $this->methodSecurity ?? [];
        $requirements[] = new SecurityRequirement([$meta->scheme => $meta->scopes]);
        $this->methodSecurity = $requirements;
    }

    public function applyMethodTag(Attribute\Tag $meta): void
    {
        $this->tags[] = $meta->name;
    }

    public function markDeprecated(): void
    {
        $this->deprecated = true;
    }

    /** The schema/content collaborator (php:S1200 extraction), built on first use. */
    private function schemaContent(): SchemaContentBuilder
    {
        $this->schemaContent ??= new SchemaContentBuilder();

        return $this->schemaContent;
    }

    public function toMetadata(): RouteHandlerMetadata
    {
        return new RouteHandlerMetadata(
            summary: $this->summary,
            description: $this->description,
            tags: array_values(array_unique($this->tags)),
            classTags: $this->classTags,
            parameters: $this->parameters,
            requestBody: $this->requestBody,
            responses: $this->responses,
            deprecated: $this->deprecated,
            classSecurity: $this->classSecurity,
            methodSecurity: $this->methodSecurity,
            operationIdOverride: $this->operationIdOverride,
        );
    }
}
