<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Adapters layer (inbound adapters)
 * Added by the router feature-expansion pass (subdomain routing).
 */

namespace Zef\Framework\Router;

/**
 * Host (subdomain) pattern matcher for the Router — the multi-tenancy
 * primitive behind `Router::group(['host' => '{tenant}.example.com'])`.
 *
 * Pattern grammar (label-separated, case-insensitive, dot-delimited):
 *   - a literal label  ("api", "example", "com") matches itself exactly;
 *   - a `{name}` label is a capturing wildcard (one non-empty label);
 *   - a bare `*` label  is an anonymous wildcard (one non-empty label).
 *
 * A label that mixes literal text with a placeholder ("api-{tenant}") is
 * rejected at registration time by {@see assertValidPattern()} — the matcher
 * never guesses. The pattern must carry exactly as many labels as the host,
 * so `{tenant}.example.com` matches `acme.example.com` but never
 * `a.b.example.com`. Any port suffix on the host is ignored.
 *
 * Stateless by design (like {@see RoutePatternParser}): no construction.
 */
final class HostPatternMatches
{
    /**
     * Validates a host pattern at registration time (fail-closed).
     *
     * @throws \InvalidArgumentException on an empty/malformed pattern
     */
    public static function assertValidPattern(string $pattern): void
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Route host pattern must not be empty.');
        }
        if (str_contains($pattern, '/') || str_contains($pattern, ':')) {
            throw new \InvalidArgumentException(
                "Route host pattern '{$pattern}' must not contain a scheme, path or port.",
            );
        }
        foreach (self::labels($pattern) as $label) {
            if ($label === '' || $label === '*') {
                continue;
            }
            if (str_contains($label, '{') || str_contains($label, '}')) {
                if (preg_match('/^\{([A-Za-z_]\w*)\}$/', $label) !== 1) {
                    throw new \InvalidArgumentException(
                        "Invalid route host label '{$label}': use a literal, '{name}' or '*'.",
                    );
                }
            }
        }
    }

    /**
     * Matches a request host against a pattern.
     *
     * @return null|array<string,string> captured wildcard parameters, or null
     *                                   when the host does not match the pattern
     */
    public static function match(string $pattern, string $host): ?array
    {
        $host = strtolower(rtrim(trim($host), '.'));
        // Strip a trailing :port (and leave IPv6 literals untouched).
        if (!str_contains($host, ']') && str_contains($host, ':')) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }
        $pattern = strtolower(trim($pattern));
        $patternLabels = self::labels($pattern);
        $hostLabels = $pattern === '' ? [] : self::labels($host);

        if (count($patternLabels) !== count($hostLabels)) {
            return null;
        }
        $params = [];
        foreach ($patternLabels as $index => $label) {
            $value = $hostLabels[$index];
            if ($label === '*') {
                if ($value === '') {
                    return null;
                }

                continue;
            }
            if (preg_match('/^\{([A-Za-z_]\w*)\}$/', $label, $m) === 1) {
                if ($value === '') {
                    return null;
                }
                $params[$m[1]] = $value;

                continue;
            }
            if ($label !== $value) {
                return null;
            }
        }

        return $params;
    }

    /**
     * Capturing wildcard names in declaration order (anonymous `*` excluded).
     *
     * @return list<string>
     */
    public static function wildcardNames(string $pattern): array
    {
        $names = [];
        foreach (self::labels($pattern) as $label) {
            if (preg_match('/^\{([A-Za-z_]\w*)\}$/', $label, $m) === 1) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function labels(string $host): array
    {
        return $host === '' ? [] : explode('.', $host);
    }
}
