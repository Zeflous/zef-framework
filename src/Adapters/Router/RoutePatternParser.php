<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * Pure route-pattern codec shared by the Router and its compiled matcher:
 * segment parsing, request-path splitting, canonical signatures and
 * segment-list matching. Stateless by design — no construction needed.
 *
 * @phpstan-type Segment array{dynamic:true,name:string,constraint?:string|null}|array{dynamic:false,value:string}
 */
final class RoutePatternParser
{
    /**
     * Parses a route pattern into typed segments (see Router::add()).
     *
     * N-9 (issue #176): same empty-segment collapse as splitPath() so
     * patterns and request paths agree on "//".
     *
     * @return list<Segment>
     */
    public static function parsePattern(string $pattern): array
    {
        $parts = $pattern === '/' ? [] : explode('/', trim($pattern, '/'));
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return array_map(
            static function (string $segment): array {
                if (
                    preg_match(
                        '/^\{([A-Za-z_]\w*)(?::([A-Za-z_]\w*))?\}$/',
                        $segment,
                        $matches,
                    ) === 1
                ) {
                    return [
                        'dynamic' => true,
                        'name' => $matches[1],
                        'constraint' => $matches[2] ?? null,
                    ];
                }
                if (str_contains($segment, '{') || str_contains($segment, '}')) {
                    throw new \InvalidArgumentException("Invalid route segment '{$segment}'.");
                }

                return ['dynamic' => false, 'value' => $segment];
            },
            $parts,
        );
    }

    /**
     * N-9 (issue #176): duplicate slashes are collapsed — an empty
     * segment is never a distinct route segment, so "/a//b" matches
     * "/a/b" exactly like the pre-existing leading/trailing slash
     * tolerance of trim(). Patterns go through the same normalization in
     * {@see parsePattern()}, so a pattern containing "//" is equivalent
     * to its single-slash form instead of being unmatchable. Percent-
     * encoded slashes (%2F) stay inside ONE segment: the split happens on
     * the raw path before any decoding.
     *
     * @return list<string>
     */
    public static function splitPath(string $path): array
    {
        if ($path === '/') {
            return [];
        }
        $parts = explode('/', trim($path, '/'));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param list<Segment> $segments
     */
    public static function canonicalSignature(string $method, array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $segment['dynamic']
                ? '*' . ($segment['constraint'] ?? '')
                : $segment['value'];
        }

        return $method . '|/' . implode('/', $parts);
    }

    /**
     * Validates dynamic-segment names: duplicates and unknown constraints
     * fail fast at registration time (moved verbatim from Router::add()).
     *
     * @param list<Segment> $segments
     */
    public static function assertUniqueParams(array $segments, RouteConstraintValidator $constraints): void
    {
        $names = [];
        foreach ($segments as $segment) {
            if (!$segment['dynamic']) {
                continue;
            }
            $name = $segment['name'];
            if (isset($names[$name])) {
                throw new \InvalidArgumentException("Duplicate route parameter '{$name}'.");
            }
            $names[$name] = true;
            $constraint = $segment['constraint'] ?? null;
            if ($constraint !== null) {
                $constraints->assertKnown($constraint);
            }
        }
    }

    /**
     * Matches a parsed segment list against a concrete request path.
     *
     * ZEF-DEEP-04: a dynamic segment carries a percent-encoded value —
     * decode it BEFORE the constraint test and BEFORE it reaches the
     * handler attributes, so `GET /users/%31%32%33` satisfies `{id:int}`
     * and `GET /users/john%20doe` yields "john doe". rawurldecode() (not
     * urldecode()) keeps "+" a literal plus: "+" only means space in
     * query strings. This restores the round-trip symmetry with
     * UrlGenerator, which rawurlencode()s every dynamic value. Static
     * segments keep comparing against the raw path text below.
     *
     * @param list<Segment> $segments
     *
     * @return array<string,string>|false|RouteConstraintException
     */
    public static function matchRoute(
        array $segments,
        string $path,
        RouteConstraintValidator $constraints,
    ): array|false|RouteConstraintException {
        $pathParts = self::splitPath($path);
        if (count($segments) !== count($pathParts)) {
            return false;
        }
        $params = [];
        $matched = true;
        foreach ($segments as $index => $segment) {
            $value = $pathParts[$index] ?? '';
            if ($segment['dynamic']) {
                $decoded = rawurldecode($value);
                $constraint = $segment['constraint'] ?? null;
                if (
                    $constraint !== null
                    && !$constraints->test($segment['name'], $constraint, $decoded)
                ) {
                    return new RouteConstraintException(
                        $segment['name'],
                        $constraint,
                        $decoded,
                    );
                }
                $params[$segment['name']] = $decoded;

                continue;
            }
            if ($segment['value'] !== $value) {
                $matched = false;

                break;
            }
        }

        return $matched ? $params : false;
    }
}
