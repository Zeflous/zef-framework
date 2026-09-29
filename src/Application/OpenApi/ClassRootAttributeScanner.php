<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Scans handler classes for document-root attributes (#[OpenApi] info
 * override, #[SecurityScheme] definitions, #[Tag] declarations carrying a
 * description). Ported verbatim from the former
 * RouteSpecExtractor::collectClassRootAttributes(); each unique resolved
 * class is scanned at most once per document and the first info override
 * wins.
 */
final class ClassRootAttributeScanner
{
    private const int ATTR_INSTANCEOF = \ReflectionAttribute::IS_INSTANCEOF;

    /**
     * Aggregate root metadata over a route table: every unique resolved
     * handler class is scanned once, in first-encounter order.
     *
     * @param list<array<string, mixed>> $routes Router::getRoutes() shaped arrays
     * @param null|\Closure(string): ?class-string $classResolver
     */
    public function scanRoutes(array $routes, ?\Closure $classResolver): RouteClassRootMetadata
    {
        $appliedClasses = [];
        $infoOverride = null;
        $schemes = [];
        $tags = [];
        foreach ($routes as $route) {
            $handler = $route['handler'] ?? null;
            if (!is_string($handler) || !$classResolver instanceof \Closure) {
                continue;
            }
            $resolved = ($classResolver)($handler);
            if (!is_string($resolved) || (!class_exists($resolved) && !interface_exists($resolved))) {
                continue;
            }
            if (isset($appliedClasses[$resolved])) {
                continue;
            }
            $appliedClasses[$resolved] = true;
            $meta = $this->scan($resolved);
            $schemes = [...$schemes, ...$meta->schemes];
            $tags = [...$tags, ...$meta->tags];
            $carriesInfoOverride = $meta->infoOverride instanceof Info;
            if ($carriesInfoOverride && !$infoOverride instanceof Info) {
                $infoOverride = $meta->infoOverride;
            }
        }

        return new RouteClassRootMetadata($infoOverride, $schemes, $tags);
    }

    /**
     * Scan one handler class's root attributes. Same-name scheme
     * definitions overwrite earlier ones; root tags keep scan order.
     */
    public function scan(string $class): RouteClassRootMetadata
    {
        // @phpstan-ignore argument.type (resolved through class_exists in scanRoutes())
        $reflection = new \ReflectionClass($class);

        $schemes = [];
        foreach ($reflection->getAttributes(Attribute\SecurityScheme::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            $schemes[$meta->name] = new SecurityScheme(
                type: $meta->type,
                scheme: $meta->scheme,
                bearerFormat: $meta->bearerFormat,
                in: $meta->in,
                openIdConnectUrl: $meta->openIdConnectUrl,
                description: $meta->description,
            );
        }

        $tags = [];
        foreach ($reflection->getAttributes(Attribute\Tag::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            if ($meta->description !== '') {
                $tags[] = new Tag($meta->name, $meta->description);
            }
        }

        $root = $reflection->getAttributes(Attribute\OpenApi::class, self::ATTR_INSTANCEOF)[0] ?? null;
        if ($root === null) {
            return new RouteClassRootMetadata(null, $schemes, $tags);
        }

        $meta = $root->newInstance();

        return new RouteClassRootMetadata(
            new Info(
                title: $meta->title,
                version: $meta->version,
                description: $meta->description,
                termsOfService: $meta->termsOfService,
                contact: $meta->contact,
                license: $meta->license,
            ),
            $schemes,
            $tags,
        );
    }
}
