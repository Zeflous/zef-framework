<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Immutable scan context (php:S107 parameter object) carrying the route
 * facts every attribute-scanning phase of {@see RouteMethodAttributeScanner}
 * needs: which handler class is reflected, which router method/pattern the
 * operation belongs to, and the schema generator used to expand
 * #[RequestBody]/#[Response] schemas.
 */
final readonly class RouteScanContext
{
    public function __construct(
        public string $handlerClass,
        public string $method,
        public string $pattern,
        public string $openApiPath,
        public SchemaGenerator $generator,
    ) {}
}
