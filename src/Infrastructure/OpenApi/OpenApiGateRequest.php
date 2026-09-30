<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The engine's request-side input, extracted from PSR-7 by the middleware
 * adapter. Deliberately free of PSR types so the Infrastructure engine
 * stays deptrac-clean and testable without HTTP objects.
 *
 * The body is a LAZY provider: the engine only invokes it when the matched
 * operation declares a requestBody (boundary B7/B8/B9) — requests against
 * operations without a body contract never touch the stream at all.
 *
 * @phpstan-type GateBodyProvider \Closure(): ?string
 */
final readonly class OpenApiGateRequest
{
    /**
     * @param array<string, mixed>  $query      parsed query params (values string or list<string> for repeated keys)
     * @param array<string, string> $headers    lowercased header names to comma-joined values
     * @param array<string, mixed>  $cookies    cookie params by name
     * @param string                $contentType raw Content-Type header line ('' when absent)
     * @param null|GateBodyProvider $body        lazy body provider
     * @param array<string, mixed>  $attributes  request attributes (zef.auth.identity, zef.security.scopes, ...)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public array $cookies,
        public string $contentType,
        public ?\Closure $body,
        public array $attributes,
    ) {}

    /** Invokes the lazy body provider exactly where the engine needs it. */
    public function bodyContents(): ?string
    {
        if (!$this->body instanceof \Closure) {
            return null;
        }

        return ($this->body)();
    }

    /**
     * The verified principal, if the pipeline already established one:
     * the canonical v2.31.0 identity attribute first, its principal alias
     * second — same resolution order and same anonymous exclusion as
     * RateLimitMiddleware.
     */
    public function authenticatedIdentity(): ?string
    {
        foreach (['zef.auth.identity', 'zef.security.principal'] as $attribute) {
            $value = $this->attributes[$attribute] ?? null;
            if (is_string($value) && $value !== '' && $value !== 'anonymous') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The scope grants the application chose to expose, when it exposes
     * any (list<string> attribute zef.security.scopes). Null means scope
     * decisions are delegated to the authorization layer (parity matrix,
     * boundary B10).
     *
     * @return null|list<string>
     */
    public function grantedScopes(): ?array
    {
        $value = $this->attributes['zef.security.scopes'] ?? null;
        if (!is_array($value)) {
            return null;
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (is_string($scope) && $scope !== '') {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
