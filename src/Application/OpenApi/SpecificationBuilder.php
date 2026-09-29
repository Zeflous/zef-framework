<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Assembles a complete OpenAPI document from parts.
 *
 * Determinism guarantees (important for cache keys and diffs):
 *   - paths are emitted sorted;
 *   - HTTP methods within a path follow the canonical order
 *     get, post, put, patch, delete, head, options, trace;
 *   - component schemas and security schemes are emitted sorted by name;
 *   - servers, tags and operations keep insertion order.
 *
 * Duplicate registration is rejected loudly: the same path+method twice,
 * two different schemas under one name, or a repeated operationId are all
 * specification bugs and throw SpecificationException.
 */
final class SpecificationBuilder implements SpecificationBuilderInterface
{
    private const array METHOD_ORDER = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @var list<Server> */
    private array $servers = [];

    /** @var array<string, array<string, Operation>> path => (method => Operation) */
    private array $operations = [];

    /** @var array<string, Schema> */
    private array $schemas = [];

    /** @var array<string, SecurityScheme> */
    private array $securitySchemes = [];

    /** @var array<string, Tag> name => Tag (insertion order, deduplicated) */
    private array $tags = [];

    /** @var array<string, true> */
    private array $operationIds = [];

    public function __construct(
        private readonly Info $info,
        private readonly OpenApiVersion $version = OpenApiVersion::V3_1_0,
    ) {}

    #[\Override]
    public function addServer(Server $server): self
    {
        $this->servers[] = $server;

        return $this;
    }

    #[\Override]
    public function addOperation(Operation $operation): self
    {
        $path = $operation->path;
        $method = strtolower($operation->method);
        if (isset($this->operations[$path][$method])) {
            throw new SpecificationException(
                "Duplicate operation [{$method}] {$path}; a path+method pair may only be registered once.",
            );
        }
        if (isset($this->operationIds[$operation->operationId])) {
            throw new SpecificationException(sprintf(
                'Duplicate operationId \'%s\'; operation identifiers must be unique across the document.',
                $operation->operationId,
            ));
        }
        $this->operations[$path][$method] = $operation;
        $this->operationIds[$operation->operationId] = true;

        return $this;
    }

    #[\Override]
    public function addSchema(string $name, Schema $schema): self
    {
        if (isset($this->schemas[$name]) && $this->schemas[$name] !== $schema) {
            throw new SpecificationException("Duplicate schema name '{$name}' with a different definition.");
        }
        $this->schemas[$name] = $schema;

        return $this;
    }

    #[\Override]
    public function addSecurityScheme(string $name, SecurityScheme $scheme): self
    {
        if (isset($this->securitySchemes[$name]) && $this->securitySchemes[$name] !== $scheme) {
            throw new SpecificationException("Duplicate security scheme name '{$name}' with a different definition.");
        }
        $this->securitySchemes[$name] = $scheme;

        return $this;
    }

    #[\Override]
    public function addTag(Tag $tag): self
    {
        $this->tags[$tag->name] ??= $tag;

        return $this;
    }

    #[\Override]
    public function build(): array
    {
        ksort($this->operations, SORT_STRING);

        $paths = [];
        foreach ($this->operations as $path => $byMethod) {
            $entries = [];
            foreach (self::METHOD_ORDER as $method) {
                $operation = $byMethod[$method] ?? null;
                if ($operation !== null) {
                    $entries[$method] = $operation->toArray();
                }
            }
            $paths[$path] = $entries;
        }

        $spec = [
            'openapi' => $this->version->value,
            'info' => $this->info->toArray(),
            'paths' => $paths,
        ];
        if ($this->servers !== []) {
            $spec['servers'] = array_map(static fn (Server $s): array => $s->toArray(), $this->servers);
        }
        if ($this->tags !== []) {
            $spec['tags'] = array_map(static fn (Tag $t): array => $t->toArray(), array_values($this->tags));
        }
        $components = [];
        if ($this->schemas !== []) {
            $sortedSchemas = $this->schemas;
            ksort($sortedSchemas, SORT_STRING);
            $components['schemas'] = array_map(static fn (Schema $s): array => $s->toArray(), $sortedSchemas);
        }
        if ($this->securitySchemes !== []) {
            $schemes = $this->securitySchemes;
            ksort($schemes, SORT_STRING);
            $components['securitySchemes'] = array_map(static fn (SecurityScheme $s): array => $s->toArray(), $schemes);
        }
        if ($components !== []) {
            $spec['components'] = $components;
        }

        return $spec;
    }
}
