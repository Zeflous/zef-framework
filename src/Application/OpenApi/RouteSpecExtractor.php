<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * The bridge between the ZEF router and an OpenAPI document.
 *
 * Accepts the plain route arrays emitted by Router::getRoutes() (no
 * Router dependency, so the Application layer stays deptrac-clean) and
 * produces a SpecificationBuilder with one Operation per route.
 *
 * Enrichment: when a `classResolver` is provided (typically backed by the
 * container) the handler service id is mapped to a class whose
 * #[OpenApi], #[SecurityScheme], #[Tag], #[Security], #[Route],
 * #[Parameter], #[RequestBody], #[Response] and #[Deprecated] attributes
 * are merged into the generated operations (see
 * {@see ClassRootAttributeScanner} and {@see RouteMethodAttributeScanner}).
 *
 * Without any attribute metadata every operation still documents itself
 * with derived path parameters and a default 200 response.
 */
final readonly class RouteSpecExtractor
{
    /**
     * Default router-constraint name => schema shape.
     */
    private const array CONSTRAINT_SCHEMAS = [
        'int' => ['type' => 'integer'],
        'uint' => ['type' => 'integer', 'minimum' => 1],
        'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
        'uuid' => ['type' => 'string', 'format' => 'uuid'],
        'hex' => ['type' => 'string', 'pattern' => '^[0-9a-f]+$'],
    ];

    /**
     * @param null|\Closure(string): ?class-string $classResolver
     */
    public function __construct(
        private Info $info,
        private ?\Closure $classResolver = null,
        private OpenApiVersion $version = OpenApiVersion::V3_1_0,
    ) {}

    /**
     * @param list<array<string, mixed>> $routes Router::getRoutes() shaped arrays
     */
    public function extract(array $routes): SpecificationBuilderInterface
    {
        $generator = new SchemaGenerator();

        // Phase 0 — resolve handler classes and collect root metadata
        // (#[OpenApi] info override, #[SecurityScheme], #[Tag]) so the
        // builder is created with the final Info; the first override
        // found wins, each class is scanned once per document.
        $roots = new ClassRootAttributeScanner()->scanRoutes($routes, $this->classResolver);
        $builder = new SpecificationBuilder($roots->infoOverride ?? $this->info, $this->version);
        $this->registerRoots($builder, $roots);
        $this->addRouteOperations($routes, $builder, $generator);
        $this->registerSchemas($builder, $generator);

        return $builder;
    }

    private function registerRoots(SpecificationBuilder $builder, RouteClassRootMetadata $roots): void
    {
        foreach ($roots->schemes as $schemeName => $scheme) {
            $builder->addSecurityScheme($schemeName, $scheme);
        }
        foreach ($roots->tags as $rootTag) {
            $builder->addTag($rootTag);
        }
    }

    private function registerSchemas(SpecificationBuilder $builder, SchemaGenerator $generator): void
    {
        foreach ($generator->registeredSchemas() as $schemaName => $schema) {
            $builder->addSchema($schemaName, $schema);
        }
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private function addRouteOperations(array $routes, SpecificationBuilder $builder, SchemaGenerator $generator): void
    {
        $usedOperationIds = [];
        $scanner = new RouteMethodAttributeScanner();
        foreach ($routes as $route) {
            $this->addRouteOperation($route, $builder, $generator, $scanner, $usedOperationIds);
        }
    }

    /**
     * @param array<string, mixed>      $route
     * @param array<string, true>       $usedOperationIds
     */
    private function addRouteOperation(
        array $route,
        SpecificationBuilder $builder,
        SchemaGenerator $generator,
        RouteMethodAttributeScanner $scanner,
        array &$usedOperationIds,
    ): void {
        [$method, $pattern, $handler] = $this->routeFields($route);
        $handlerClass = $this->resolveHandlerClass($handler);
        $path = $this->toOpenApiPath($pattern);

        $meta = $scanner->scan($handlerClass, $method, $pattern, $path, $generator);
        foreach ($meta->classTags as $classTag) {
            $builder->addTag($classTag);
        }

        $operationId = $this->resolveOperationId($route, $method, $handler, $meta, $usedOperationIds);
        $builder->addOperation(new Operation(
            operationId: $operationId,
            method: $method,
            path: $path,
            responses: $meta->responses === []
                ? ['200' => new Response('Successful response.')]
                : $meta->responses,
            summary: $meta->summary,
            description: $meta->description,
            tags: $meta->tags,
            parameters: [...$this->pathParameters($pattern, $route['segments'] ?? null), ...$meta->parameters],
            requestBody: $meta->requestBody,
            deprecated: $meta->deprecated,
            security: $meta->methodSecurity ?? $meta->classSecurity,
        ));
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, true>  $usedOperationIds
     */
    private function resolveOperationId(
        array $route,
        string $method,
        string $handler,
        RouteHandlerMetadata $meta,
        array &$usedOperationIds,
    ): string {
        $name = $route['name'] ?? null;
        if (is_string($name) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
            $name = null; // hostile/unusual route names fall back to derivation
        }
        $operationId = is_string($name) && $name !== ''
            ? $name
            : $this->deriveOperationId($method, $handler, $usedOperationIds);
        if ($meta->operationIdOverride !== null && !is_string($name)) {
            $operationId = $meta->operationIdOverride;
        }
        $usedOperationIds[$operationId] = true;

        return $operationId;
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array{string, string, string}
     */
    private function routeFields(array $route): array
    {
        $method = $route['method'] ?? null;
        $pattern = $route['pattern'] ?? null;
        $handler = $route['handler'] ?? null;
        if (!is_string($method) || !is_string($pattern) || !is_string($handler)) {
            throw new SpecificationException('Route arrays must provide string method, pattern, and handler entries.');
        }

        return [$method, $pattern, $handler];
    }

    private function resolveHandlerClass(string $handler): ?string
    {
        if (!$this->classResolver instanceof \Closure) {
            return null;
        }
        $resolved = ($this->classResolver)($handler);

        return is_string($resolved) && (class_exists($resolved) || interface_exists($resolved))
            ? $resolved
            : null;
    }

    /**
     * Convert a ZEF route pattern into an OpenAPI path template:
     * /users/{id:int} -> /users/{id}.
     */
    private function toOpenApiPath(string $pattern): string
    {
        $converted = preg_replace(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[A-Za-z_][A-Za-z0-9_]*)?\}/',
            '{$1}',
            $pattern,
        );
        if (!is_string($converted) || $converted === '' || $converted[0] !== '/') {
            throw new SpecificationException("Route pattern '{$pattern}' cannot be converted to an OpenAPI path.");
        }

        return $converted;
    }

    /**
     * Derive Parameter objects for every dynamic segment. Constraint names
     * map to schema types (int/uint/alpha/slug/uuid/hex); unknown or custom
     * constraints degrade to unconstrained strings.
     *
     * @param mixed $segments Router segment arrays (or null to parse pattern)
     *
     * @return list<Parameter>
     */
    private function pathParameters(string $pattern, mixed $segments): array
    {
        $names = $this->segmentNames($segments);
        if ($names === []) {
            // segments absent/empty (fixture arrays, edge tooling): parse the
            // wire pattern directly. Duplicates are preserved verbatim — the
            // Router rejects them, but the extractor stays garbage-tolerant.
            $names = $this->patternNames($pattern);
        }

        $parameters = [];
        foreach ($names as [$paramName, $constraint]) {
            $parameters[] = new Parameter(
                name: $paramName,
                in: ParameterLocation::Path,
                schema: $this->constraintSchema($constraint),
                description: $constraint !== null ? "Path parameter constrained by '{$constraint}'." : '',
            );
        }

        return $parameters;
    }

    /**
     * @return list<array{string, ?string}>
     */
    private function segmentNames(mixed $segments): array
    {
        $names = [];
        if (!is_array($segments)) {
            return $names;
        }
        foreach ($segments as $segment) {
            if (
                is_array($segment)
                && ($segment['dynamic'] ?? false) === true
                && isset($segment['name'])
                && is_string($segment['name'])
            ) {
                $constraint = is_string($segment['constraint'] ?? null) ? $segment['constraint'] : null;
                $names[] = [$segment['name'], $constraint];
            }
        }

        return $names;
    }

    /**
     * @return list<array{string, ?string}>
     */
    private function patternNames(string $pattern): array
    {
        $names = [];
        $regex = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}/';
        if (preg_match_all($regex, $pattern, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $names[] = [$match[1], $match[2] ?? null];
            }
        }

        return $names;
    }

    private function constraintSchema(?string $constraint): ?Schema
    {
        if ($constraint === null || !isset(self::CONSTRAINT_SCHEMAS[$constraint])) {
            return null;
        }
        $shape = self::CONSTRAINT_SCHEMAS[$constraint];

        return new Schema(
            type: SchemaType::from($shape['type']),
            format: $shape['format'] ?? null,
            pattern: $shape['pattern'] ?? null,
            minimum: $shape['minimum'] ?? null,
        );
    }

    /** Deterministic, collision-free fallback operation id.
     * @param array<string, true> $usedOperationIds
     */
    private function deriveOperationId(string $method, string $handler, array $usedOperationIds): string
    {
        $base = strtolower($method) . '.' . preg_replace('/[^A-Za-z0-9._-]/', '_', $handler);
        $base = trim($base, '.');
        if ($base === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $base) !== 1) {
            $base = strtolower($method) . '.route';
        }
        $candidate = $base;
        $suffix = 2;
        while (isset($usedOperationIds[$candidate])) {
            $candidate = $base . '.' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }
}
