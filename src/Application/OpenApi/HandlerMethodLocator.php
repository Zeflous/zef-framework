<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Picks the handler methods an operation's attributes are read from.
 *
 * Handler conventions: attributes may live on the PSR-15 handle() method,
 * or on dedicated public methods when a controller serves several routes.
 * Methods whose #[Route] declaration matches the current method (+ an
 * optional path) take precedence over the default target; the default
 * target is only used when it carries no #[Route] of its own (those
 * belong to other paths). Ported verbatim from the former
 * RouteSpecExtractor::collectMethodAttributes() matching block.
 */
final class HandlerMethodLocator
{
    private const int ATTR_INSTANCEOF = \ReflectionAttribute::IS_INSTANCEOF;

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionMethod> empty when no method documents this route
     */
    public function locate(\ReflectionClass $reflection, string $method, string $pattern, string $openApiPath): array
    {
        $matched = $this->matchingRouteMethods($reflection, $method, $pattern, $openApiPath);
        if ($matched !== []) {
            return $matched;
        }

        $target = $this->documentationTarget($reflection);
        if (!$target instanceof \ReflectionMethod) {
            return [];
        }

        return $target->getAttributes(Attribute\Route::class, self::ATTR_INSTANCEOF) === []
            ? [$target]
            : [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionMethod>
     */
    private function matchingRouteMethods(
        \ReflectionClass $reflection,
        string $method,
        string $pattern,
        string $openApiPath,
    ): array {
        $matched = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $candidate) {
            $isMagic = str_starts_with($candidate->getName(), '__') && $candidate->getName() !== '__invoke';
            if ($candidate->isStatic() || $isMagic) {
                continue;
            }
            foreach ($candidate->getAttributes(Attribute\Route::class, self::ATTR_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                if (strcasecmp($meta->method, $method) === 0
                    && in_array($meta->path, ['', $pattern, $openApiPath], true)) {
                    $matched[] = $candidate;

                    break;
                }
            }
        }

        return $matched;
    }

    /** PSR-15 handle() first, then __invoke(). */
    private function documentationTarget(\ReflectionClass $reflection): ?\ReflectionMethod
    {
        if ($reflection->hasMethod('handle')) {
            return $reflection->getMethod('handle');
        }
        if ($reflection->hasMethod('__invoke')) {
            return $reflection->getMethod('__invoke');
        }

        return null;
    }
}
