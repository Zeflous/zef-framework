<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound HTTP adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Zef\Framework\Exception\ApiVersionUnsupportedException;

/**
 * API version negotiation for HTTP endpoints.
 *
 * Priority (most explicit wins):
 *   1. path prefix  /v{version}/...          e.g. /v2/users  → "2"
 *   2. header       X-Api-Version (configurable)
 *   3. query        ?api_version= (configurable)
 *   4. configured default (when provided)
 *
 * Everything that is malformed, oversized, or not in the supported list is
 * raised as ApiVersionUnsupportedException (a 400/406-grade outcome), never
 * as a raw PHP error. Header/query length caps block header-injection noise.
 * Path prefixes are anchored to the registry plus the numeric version
 * grammar, so plain /v-prefixed routes ("/vendor") never hijack negotiation.
 */
final class ApiVersionNegotiator
{
    private const int MAX_TOKEN_BYTES = 16;
    private const int MAX_HEADER_BYTES = 256;
    private const int MAX_QUERY_BYTES = 64;

    /**
     * @var array<string,true>
     */
    private array $supported;

    /**
     * @var list<string> original version tokens (string-key safety)
     */
    private readonly array $versionList;
    private readonly ?string $default;

    /**
     * @param list<string> $supported e.g. ['1','2','3'] (exact tokens)
     */
    public function __construct(
        array $supported,
        ?string $default = null,
        public readonly string $headerName = 'X-Api-Version',
        public readonly string $queryKey = 'api_version',
    ) {
        if ($supported === []) {
            throw new \InvalidArgumentException('ApiVersionNegotiator requires at least one supported version.');
        }
        foreach ($supported as $version) {
            if (!is_string($version) || !$this->isValidToken($version)) {
                throw new \InvalidArgumentException(
                    'Supported versions must match [A-Za-z0-9._-]{1,16}, got: '
                    . (is_scalar($version) ? (string) $version : get_debug_type($version)),
                );
            }
        }
        if ($default !== null) {
            if (!in_array($default, $supported, true)) {
                throw new \InvalidArgumentException("Default version '{$default}' must be part of the supported list.");
            }
            $this->default = $default;
        } else {
            $this->default = null;
        }
        $this->supported = array_fill_keys($supported, true);
        $this->versionList = array_values($supported);
    }

    /** @return list<string> */
    public function supportedVersions(): array
    {
        return $this->versionList;
    }

    /**
     * @param string $path         request path (leading '/' expected, not enforced)
     * @param ?string $headerValue raw header value for the configured header name
     * @param ?string $queryValue  raw query value for the configured query key
     */
    public function negotiate(string $path, ?string $headerValue = null, ?string $queryValue = null): ApiVersion
    {
        // 1–3) Explicit channels in priority order; first non-null token wins.
        foreach ($this->versionCandidates($path, $headerValue, $queryValue) as [$token, $source]) {
            if ($token !== null) {
                return $this->assertSupported($token, $source);
            }
        }

        // 4) Default.
        if ($this->default !== null) {
            return new ApiVersion($this->default, ApiVersion::SOURCE_DEFAULT);
        }

        throw new ApiVersionUnsupportedException(
            null,
            $this->supportedVersions(),
            'No API version provided (path /v{n} prefix, '
            . $this->headerName
            . ' header or ?'
            . $this->queryKey
            . '= query).',
        );
    }

    /**
     * Splits a leading /v{token} prefix off the path.
     * "/v2/users" → ["2", "/users"]; "/users" → [null, "/users"].
     *
     * Regresi P-11 (issue #171): the extracted token is anchored to the
     * supported-version registry (whitelist) or to the numeric version
     * grammar — any other /v-prefixed path word ("/vendor", "/videos") is an
     * ordinary route, never a hijacked version prefix. Numeric-but-unregistered
     * tokens still split so negotiate() reports them as explicit unsupported
     * versions instead of silently falling back to the default.
     *
     * @return array{?string, string}
     */
    public function splitPathPrefix(string $path): array
    {
        if (preg_match('#^/v([A-Za-z0-9._-]{1,' . self::MAX_TOKEN_BYTES . '})(/|$)#', $path, $m) === 1) {
            $token = $m[1];
            if (isset($this->supported[$token]) || $this->isNumericVersionToken($token)) {
                $rest = substr($path, strlen($m[0]));

                return [$token, $rest === '' ? '/' : '/' . $rest];
            }
        }

        return [null, $path];
    }

    /**
     * Explicit (non-default) version candidates in priority order.
     *
     * @return list<array{?string, string}>
     */
    private function versionCandidates(string $path, ?string $headerValue, ?string $queryValue): array
    {
        return [
            [$this->splitPathPrefix($path)[0], ApiVersion::SOURCE_PATH],
            [$this->sanitizeToken($headerValue, self::MAX_HEADER_BYTES), ApiVersion::SOURCE_HEADER],
            [$this->sanitizeToken($queryValue, self::MAX_QUERY_BYTES), ApiVersion::SOURCE_QUERY],
        ];
    }

    private function assertSupported(string $token, string $source): ApiVersion
    {
        if (!isset($this->supported[$token])) {
            throw new ApiVersionUnsupportedException(
                $token,
                $this->supportedVersions(),
                "API version '{$token}' is not supported. Supported versions: "
                . implode(', ', $this->supportedVersions())
                . '.',
            );
        }

        return new ApiVersion($token, $source);
    }

    /** Null when absent/malformed/oversized — never throws. */
    private function sanitizeToken(?string $raw, int $maxLength): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > $maxLength || !$this->isValidToken($raw)) {
            return null;
        }

        return $raw;
    }

    private function isValidToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,' . self::MAX_TOKEN_BYTES . '}$/', $token) === 1;
    }

    /** Numeric version grammar: major[.minor[.patch]], e.g. "1", "2.3", "1.4.7". */
    private function isNumericVersionToken(string $token): bool
    {
        return preg_match('/^\d{1,3}(?:\.\d{1,3}){0,2}$/', $token) === 1;
    }
}
