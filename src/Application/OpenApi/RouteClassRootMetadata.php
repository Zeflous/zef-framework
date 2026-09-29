<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Document-root metadata aggregated over all handler classes: the first
 * #[OpenApi] info override encountered, every #[SecurityScheme]
 * definition (later same-name definitions overwrite earlier ones) and
 * every #[Tag] carrying a description, in scan order.
 */
final readonly class RouteClassRootMetadata
{
    /**
     * @param array<string, SecurityScheme> $schemes
     * @param list<Tag> $tags
     */
    public function __construct(
        public ?Info $infoOverride = null,
        public array $schemes = [],
        public array $tags = [],
    ) {}
}
