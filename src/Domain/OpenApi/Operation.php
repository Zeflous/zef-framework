<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Operation Object — one method on one path.
 *
 * Invariants: the HTTP method must be known to the framework validation
 * engine, the path must start with "/", at least one response must be
 * defined, and the operationId must be a stable ASCII identifier.
 */
final readonly class Operation
{
    /** Methods the OpenAPI path-item object documents. */
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * @param array<int|string, Response> $responses status (e.g. "200") => response; numeric-string
     *                                                   status keys are normalized back to strings on output
     * @param list<string> $tags
     * @param list<Parameter> $parameters
     * @param list<SecurityRequirement> $security
     */
    public function __construct(
        public string $operationId,
        public string $method,
        public string $path,
        public array $responses,
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public array $parameters = [],
        public ?RequestBody $requestBody = null,
        public bool $deprecated = false,
        public array $security = [],
    ) {
        self::assertMethod($method);
        self::assertPath($path);
        self::assertOperationId($operationId);
        self::assertResponses($operationId, $responses);
        self::assertTags($operationId, $tags);
        self::assertParameters($operationId, $parameters);
        self::assertSecurity($operationId, $security);
    }

    private static function assertMethod(string $method): void
    {
        if (!in_array(strtoupper($method), self::METHODS, true)) {
            throw new SchemaDefinitionException("Operation method '{$method}' is not a documentable HTTP method.");
        }
    }

    private static function assertPath(string $path): void
    {
        if ($path === '' || $path[0] !== '/') {
            throw new SchemaDefinitionException("Operation path '{$path}' must begin with '/'.");
        }
    }

    private static function assertOperationId(string $operationId): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $operationId) !== 1) {
            throw new SchemaDefinitionException("Operation id '{$operationId}' must match [A-Za-z0-9._-]{1,128}.");
        }
    }

    /**
     * @param array<int|string, Response> $responses
     */
    private static function assertResponses(string $operationId, array $responses): void
    {
        if ($responses === []) {
            throw new SchemaDefinitionException("Operation '{$operationId}' must define at least one response.");
        }
        foreach ($responses as $status => $response) {
            if (!$response instanceof Response || trim((string) $status) === '') {
                throw new SchemaDefinitionException(
                    "Operation '{$operationId}' response keys must be non-empty"
                    . ' status keys mapping to Response instances.',
                );
            }
        }
    }

    /**
     * @param list<string> $tags
     */
    private static function assertTags(string $operationId, array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new SchemaDefinitionException("Operation '{$operationId}' tags must be non-empty strings.");
            }
        }
    }

    /**
     * @param list<Parameter> $parameters
     */
    private static function assertParameters(string $operationId, array $parameters): void
    {
        foreach ($parameters as $parameter) {
            if (!$parameter instanceof Parameter) {
                throw new SchemaDefinitionException(
                    "Operation '{$operationId}' parameters must be Parameter instances.",
                );
            }
        }
    }

    /**
     * @param list<SecurityRequirement> $security
     */
    private static function assertSecurity(string $operationId, array $security): void
    {
        foreach ($security as $requirement) {
            if (!$requirement instanceof SecurityRequirement) {
                throw new SchemaDefinitionException(
                    "Operation '{$operationId}' security entries must be SecurityRequirement instances.",
                );
            }
        }
    }

    /**
     * @return array{
     *     operationId: string,
     *     summary?: string,
     *     description?: string,
     *     tags?: list<string>,
     *     parameters?: list<array<string, mixed>>,
     *     requestBody?: array<string, mixed>,
     *     responses: array<int|string, array<string, mixed>>,
     *     deprecated?: true,
     *     security?: list<array<string, list<string>>>,
     * }
     */
    public function toArray(): array
    {
        $out = [
            'operationId' => $this->operationId,
        ];
        if ($this->summary !== '') {
            $out['summary'] = $this->summary;
        }
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->tags !== []) {
            $out['tags'] = $this->tags;
        }
        if ($this->parameters !== []) {
            /** @var list<Parameter> $parameters */
            $parameters = $this->parameters;
            $out['parameters'] = array_map(static fn (Parameter $p): array => $p->toArray(), $parameters);
        }
        if ($this->requestBody instanceof RequestBody) {
            $out['requestBody'] = $this->requestBody->toArray();
        }
        $responses = [];
        foreach ($this->responses as $status => $response) {
            $responses[(string) $status] = $response->toArray();
        }
        $out['responses'] = $responses;
        if ($this->deprecated) {
            $out['deprecated'] = true;
        }
        if ($this->security !== []) {
            /** @var list<SecurityRequirement> $security */
            $security = $this->security;
            $out['security'] = array_map(static fn (SecurityRequirement $r): array => $r->toArray(), $security);
        }

        return $out;
    }
}
