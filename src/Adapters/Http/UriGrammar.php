<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Grammar/encoding helper for {@see Uri}, extracted so the PSR-7 value
 * object stays under the maintainability size budgets (Sonar php:S1448 /
 * php:S2042). Pure move: patterns, encoding and exception messages are
 * byte-identical to the former Uri private helpers.
 */

namespace Zef\Framework\Http;

final class UriGrammar
{
    public const string PATH_ALLOWED = ":/@!$&'()*+,;=-._~";

    public const string QUERY_FRAGMENT_ALLOWED = ":/?@!$&'()*+,;=-._~";

    public const string USERINFO_ALLOWED = "!$&'()*+,;=:";

    public const string PASSWORD_ALLOWED = "!$&'()*+,;=";

    private const string SCHEME_PATTERN = '/^[A-Za-z][A-Za-z0-9+.-]*\z/';

    /** RFC 3986 reg-name label: alphanumeric edges ('_' included), inner hyphens. */
    private const string HOST_LABEL = '[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?';

    private const string HOST_NAME_PATTERN = '/^(?=.{1,253}$)' . self::HOST_LABEL
        . '(?:\.' . self::HOST_LABEL . ')*$/';

    private function __construct() {}

    /**
     * Parse + validate + percent-encode a URI string into its components.
     *
     * @return array{
     *     scheme: string,
     *     userInfo: string,
     *     host: string,
     *     port: null|int,
     *     path: string,
     *     query: string,
     *     fragment: string,
     * }
     */
    public static function parse(string $uri): array
    {
        self::assertNoControls($uri, 'URI');
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI '{$uri}'.");
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        self::assertScheme($scheme);
        $userInfo = self::encodeUserInfo(
            isset($parts['user']) ? (string) $parts['user'] : '',
            array_key_exists('pass', $parts) ? (string) $parts['pass'] : null,
        );
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        self::assertHost($host);

        return [
            'scheme' => $scheme,
            'userInfo' => $userInfo,
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => self::encodeComponent((string) ($parts['path'] ?? ''), self::PATH_ALLOWED),
            'query' => self::encodeComponent((string) ($parts['query'] ?? ''), self::QUERY_FRAGMENT_ALLOWED),
            'fragment' => self::encodeComponent((string) ($parts['fragment'] ?? ''), self::QUERY_FRAGMENT_ALLOWED),
        ];
    }

    public static function assertNoControls(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException("Invalid {$label} control characters.");
        }
    }

    public static function assertScheme(string $scheme): void
    {
        if ($scheme !== '' && preg_match(self::SCHEME_PATTERN, $scheme) !== 1) {
            throw new \InvalidArgumentException('Invalid URI scheme.');
        }
    }

    /**
     * A port equal to the scheme's default (http:80, https:443) is
     * indistinguishable from no port at all per PSR-7 "SHOULD omit".
     */
    public static function isDefaultPortForScheme(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
    }

    /** Percent-encode one URI component, preserving allowed characters. */
    public static function encodeComponent(string $value, string $allowed): string
    {
        $result = '';
        $len = strlen($value);
        $i = 0;
        while ($i < $len) {
            $ch = $value[$i];
            $o = ord($ch);
            $isPercentTriple = $ch === '%' && $i + 2 < $len
                && ctype_xdigit($value[$i + 1])
                && ctype_xdigit($value[$i + 2]);
            if ($isPercentTriple) {
                $result .= '%' . strtoupper($value[$i + 1] . $value[$i + 2]);
                $i += 3;

                continue;
            }
            $isUppercase = $o >= 65 && $o <= 90;
            $isLowercase = $o >= 97 && $o <= 122;
            $isDigit = $o >= 48 && $o <= 57;
            $isUnreserved = $isUppercase || $isLowercase || $isDigit;
            if ($isUnreserved || str_contains('-._~' . $allowed, $ch)) {
                $result .= $ch;
                ++$i;

                continue;
            }
            $result .= sprintf('%%%02X', $o);
            ++$i;
        }

        return $result;
    }

    public static function assertHost(string $host): void
    {
        if ($host === '') {
            return;
        }
        self::assertNoControls($host, 'URI host');
        if (str_contains($host, ':')) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Invalid URI host.');
            }

            return;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return;
        }
        // RFC 3986 reg-name also permits '_' (common for intranet hosts,
        // RFC 9110 Host = reg-name); the DNS-only class rejected it.
        if (strlen($host) > 253 || preg_match(self::HOST_NAME_PATTERN, $host) !== 1) {
            throw new \InvalidArgumentException('Invalid URI host.');
        }
    }

    private static function encodeUserInfo(string $user, ?string $pass): string
    {
        $userInfo = self::encodeComponent($user, self::USERINFO_ALLOWED);
        if ($pass !== null) {
            $userInfo .= ':' . self::encodeComponent($pass, self::PASSWORD_ALLOWED);
        }

        return $userInfo;
    }

    /**
     * Bug fix #2: parse_url returns '[::1]' WITH brackets for IPv6 hosts.
     * Strip them before assertHost() which uses FILTER_VALIDATE_IP.
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }
}
