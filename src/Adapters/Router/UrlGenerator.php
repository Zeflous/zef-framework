<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * Reverse routing: generates concrete URLs from named routes.
 *
 * Dynamic segments (`{id}`, `{id:int}`) are substituted with rawurlencode()d
 * parameter values and re-validated against the route's constraint so that a
 * generated URL can never fail to match its own route. Generation is strict:
 * missing parameters and unknown extra parameters both throw.
 */
final readonly class UrlGenerator
{
    private RouteConstraintValidator $constraints;

    public function __construct(
        private Router $router,
    ) {
        // Reuse the router's own validator so custom constraints are known here.
        $this->constraints = $router->constraintValidator();
    }

    /**
     * @param array<string,null|scalar|\Stringable> $params
     *
     * @throws \InvalidArgumentException         for unknown route names, missing
     *                                           or invalid parameter types
     * @throws RouteConstraintException          when a value violates the route's
     *                                           constraint (same semantics as matching)
     */
    public function generate(string $name, array $params = []): string
    {
        $pattern = $this->router->patternFor($name);
        $parts = $pattern === '/' ? [] : explode('/', trim($pattern, '/'));
        $consumed = [];
        $path = [];
        foreach ($parts as $part) {
            $path[] = $this->segment($name, $part, $params, $consumed);
        }
        foreach (array_keys($params) as $extra) {
            if (!isset($consumed[(string) $extra])) {
                throw new \InvalidArgumentException("Route '{$name}' does not accept parameter '{$extra}'.");
            }
        }

        return '/' . implode('/', $path);
    }

    /**
     * Resolves one path segment: dynamic `{param}` / `{param:constraint}`
     * placeholders are substituted (validated) and marked consumed; static
     * segments pass through untouched.
     *
     * @param array<string,null|scalar|\Stringable> $params
     * @param array<string,true> $consumed
     */
    private function segment(string $name, string $part, array $params, array &$consumed): string
    {
        if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}$/', $part, $m) !== 1) {
            return $part;
        }
        $paramName = $m[1];
        $constraint = $m[2] ?? null;
        if (!array_key_exists($paramName, $params)) {
            throw new \InvalidArgumentException("Route '{$name}' requires parameter '{$paramName}'.");
        }
        $value = $this->stringify($name, $paramName, $params[$paramName]);
        if ($constraint !== null && !$this->constraints->test($paramName, $constraint, $value)) {
            // Mirrors Router::match() semantics: constraint violation throws.
            throw new RouteConstraintException($paramName, $constraint, $value);
        }
        $consumed[$paramName] = true;

        return rawurlencode($value);
    }

    private function stringify(string $route, string $param, mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(
            "Route '{$route}' parameter '{$param}' must be scalar or Stringable, got "
            . get_debug_type($value) . '.',
        );
    }
}
