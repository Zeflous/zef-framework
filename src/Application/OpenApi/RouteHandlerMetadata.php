<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Operation metadata harvested from a handler class's attributes, in the
 * shape {@see RouteSpecExtractor} consumes it (formerly the collector's
 * array shape). "empty()" marks the no-attributes / no-matched-method
 * case where class-level metadata is discarded as well.
 */
final readonly class RouteHandlerMetadata
{
    /**
     * @param list<string> $tags
     * @param list<Tag> $classTags
     * @param list<Parameter> $parameters
     * @param array<int|string, Response> $responses
     * @param list<SecurityRequirement> $classSecurity
     * @param ?list<SecurityRequirement> $methodSecurity
     */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public array $classTags = [],
        public array $parameters = [],
        public ?RequestBody $requestBody = null,
        public array $responses = [],
        public bool $deprecated = false,
        public array $classSecurity = [],
        public ?array $methodSecurity = null,
        public ?string $operationIdOverride = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
