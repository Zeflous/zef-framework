<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Merges class-level + method-level operation metadata for one route by
 * reflecting the resolved handler class. Attribute interpretation lives
 * in {@see RouteMetadataAccumulator}; method selection in
 * {@see HandlerMethodLocator}. Ported from the former
 * RouteSpecExtractor::collectMethodAttributes(); an absent handler class
 * or a class with no documentable method yields empty metadata (class
 * level attributes included), exactly as before.
 */
final class RouteMethodAttributeScanner
{
    private const int ATTR_INSTANCEOF = \ReflectionAttribute::IS_INSTANCEOF;

    public function scan(
        ?string $handlerClass,
        string $method,
        string $pattern,
        string $openApiPath,
        SchemaGenerator $generator,
    ): RouteHandlerMetadata {
        if ($handlerClass === null) {
            return RouteHandlerMetadata::empty();
        }

        // @phpstan-ignore argument.type (resolved through class_exists in RouteSpecExtractor)
        $reflection = new \ReflectionClass($handlerClass);
        $meta = new RouteMetadataAccumulator();
        $this->applyClassAttributes($reflection, $meta);

        $matched = new HandlerMethodLocator()->locate($reflection, $method, $pattern, $openApiPath);
        if ($matched === []) {
            return RouteHandlerMetadata::empty();
        }

        $context = new RouteScanContext($handlerClass, $method, $pattern, $openApiPath, $generator);
        foreach ($matched as $source) {
            $this->applySourceAttributes($source, $meta, $context);
        }

        return $meta->toMetadata();
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function applyClassAttributes(\ReflectionClass $reflection, RouteMetadataAccumulator $meta): void
    {
        foreach ($reflection->getAttributes(Attribute\Tag::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyClassTag($attribute->newInstance());
        }
        foreach ($reflection->getAttributes(Attribute\Security::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyClassSecurity($attribute->newInstance());
        }
        if ($reflection->getAttributes(Attribute\Deprecated::class, self::ATTR_INSTANCEOF) !== []) {
            $meta->markDeprecated();
        }
    }

    private function applySourceAttributes(
        \ReflectionMethod $source,
        RouteMetadataAccumulator $meta,
        RouteScanContext $context,
    ): void {
        $sourceName = $source->getName();
        foreach ($source->getAttributes(Attribute\Route::class, self::ATTR_INSTANCEOF) as $attribute) {
            $routeMeta = $attribute->newInstance();
            if (strcasecmp($routeMeta->method, $context->method) !== 0) {
                continue;
            }
            if (!in_array($routeMeta->path, ['', $context->pattern, $context->openApiPath], true)) {
                continue;
            }
            $meta->applyRoute($routeMeta);
        }

        foreach ($source->getAttributes(Attribute\Parameter::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyParameter($attribute->newInstance());
        }
        foreach ($source->getAttributes(Attribute\RequestBody::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyRequestBody(
                $attribute->newInstance(),
                $context->generator,
                $context->handlerClass,
                $sourceName,
            );
        }
        foreach ($source->getAttributes(Attribute\Response::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyResponse($attribute->newInstance(), $context->generator);
        }
        foreach ($source->getAttributes(Attribute\Security::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyMethodSecurity($attribute->newInstance());
        }
        foreach ($source->getAttributes(Attribute\Tag::class, self::ATTR_INSTANCEOF) as $attribute) {
            $meta->applyMethodTag($attribute->newInstance());
        }
        if ($source->getAttributes(Attribute\Deprecated::class, self::ATTR_INSTANCEOF) !== []) {
            $meta->markDeprecated();
        }
    }
}
