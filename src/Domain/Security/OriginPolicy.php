<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Application -> Domain with the same
 * FQCN and namespace (classmap + PSR-4 multi-directory both resolve it),
 * so every consumer — the Domain security policy aggregator
 * (SecurityPolicy, same namespace) and the middleware origin checks —
 * is untouched. The class is a pure origin-normalisation policy helper:
 * its only dependency is the Domain InvalidConfigurationException, and
 * it performs no I/O, so it satisfies the hexagonal rule the
 * OriginPolicySpec carve-out used to bypass.
 */

namespace Zef\Framework\Security;

use Zef\Framework\Exception\InvalidConfigurationException;

final class OriginPolicy
{
    /** Single DNS label per RFC 1123: alphanumeric edges, inner hyphens. */
    private const string HOST_LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    private const string HOST_NAME_PATTERN = '/^(?=.{1,253}$)' . self::HOST_LABEL
        . '(?:\.' . self::HOST_LABEL . ')*$/';

    /** @param list<string> $allowedOrigins */
    public static function assertAllowed(?string $origin, array $allowedOrigins): void
    {
        if ($origin === null || $origin === '') {
            return;
        }
        $normalized = self::normalizeOrigin($origin);
        if (!in_array($normalized, $allowedOrigins, true)) {
            throw new InvalidConfigurationException('Origin is not allowed.');
        }
    }

    public static function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === 'null') {
            return 'null';
        }
        if (preg_match('/[\r\n]/', $origin) === 1) {
            throw new \InvalidArgumentException('Malformed Origin header.');
        }
        [$scheme, $host, $port] = self::parseOrigin($origin);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported Origin scheme.');
        }
        $host = self::normalizeIpv6Host($host);
        self::assertHostGrammar($host);
        $port = self::normalizePort($port, $scheme);

        return $scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host)
            . ($port === null ? '' : ':' . $port);
    }

    /**
     * Parse the Origin grammar: scheme + host + optional port only —
     * credentials, query, fragment and path are all malformed here.
     *
     * @return array{string, string, null|int} [lowercase scheme, lowercase host, port]
     */
    private static function parseOrigin(string $origin): array
    {
        $parts = parse_url($origin);
        $hasCredentials = isset($parts['user'], $parts['pass']);
        $hasExtraComponents = isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '');
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || $hasCredentials
            || $hasExtraComponents
        ) {
            throw new \InvalidArgumentException('Malformed Origin header.');
        }

        return [
            strtolower((string) $parts['scheme']),
            strtolower((string) $parts['host']),
            isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    private static function normalizeIpv6Host(string $host): string
    {
        // parse_url keeps IPv6 hosts bracketed ('[::1]'); strip them so the
        // IP / hostname grammar below sees the bare address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private static function assertHostGrammar(string $host): void
    {
        $isValidIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isValidIp && preg_match(self::HOST_NAME_PATTERN, $host) !== 1) {
            throw new \InvalidArgumentException('Malformed Origin host.');
        }
    }

    private static function normalizePort(?int $port, string $scheme): ?int
    {
        if ($port !== null && $port < 1) {
            throw new \InvalidArgumentException('Malformed Origin port.');
        }

        // A port equal to the scheme's default is indistinguishable from
        // no port at all per PSR-7 "SHOULD omit".
        return match (true) {
            $scheme === 'http' && $port === 80 => null,
            $scheme === 'https' && $port === 443 => null,
            default => $port,
        };
    }
}
