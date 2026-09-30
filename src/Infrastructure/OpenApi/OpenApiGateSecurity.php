<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Boundary B10: the security slice of the OpenAPI runtime gate.
 *
 * An operation's effective requirements (operation-level security with
 * top-level fallback) are OR-alternatives of AND-sets; a set is satisfied
 * when every named scheme is satisfied — by a verified identity attribute
 * ({@see OpenApiGateRequest::authenticatedIdentity()}, the canonical
 * v2.31.0 surface) or by cheap presence-of-evidence for the scheme type.
 * Credential VERIFICATION is never this class's job. A non-string scheme
 * name is an unknown scheme (fail-closed, same as an undefined string
 * name) — never silently skipped.
 *
 * Extracted from {@see OpenApiRequestGate} for the class-size budget
 * (php:S2042); behaviour, messages and precedence are carried over
 * verbatim.
 */
final readonly class OpenApiGateSecurity
{
    public function __construct(
        private OpenApiGateIndex $index,
    ) {}

    /**
     * Null = satisfied (or no security contract at all).
     *
     * @param array<mixed, mixed> $operation
     */
    public function verdict(array $operation, OpenApiGateRequest $request): ?OpenApiGateVerdict
    {
        $requirements = $this->effectiveRequirements($operation);
        if ($requirements === []) {
            return null;
        }
        $identity = $request->authenticatedIdentity();
        $scopes = $request->grantedScopes();
        foreach ($requirements as $requirement) {
            if ($this->requirementSatisfied($requirement, $identity, $scopes, $request)) {
                return null;
            }
        }

        return $this->unsatisfiedVerdict($requirements, $identity);
    }

    /**
     * @param array<mixed, mixed> $operation
     *
     * @return array<mixed, mixed>
     */
    private function effectiveRequirements(array $operation): array
    {
        if (array_key_exists('security', $operation)) {
            $security = $operation['security'];

            return is_array($security) ? $security : [];
        }
        if ($this->index->specSecurity !== null) {
            return $this->index->specSecurity;
        }

        return [];
    }

    /**
     * An empty requirement object is satisfied by definition; a malformed
     * entry (non-array) is never satisfied — the boot validator's shape
     * check refuses these documents for operation-level security, so the
     * branch is only reachable through a hand-written top-level list.
     *
     * @param null|array<string> $scopes
     */
    private function requirementSatisfied(
        mixed $requirement,
        ?string $identity,
        ?array $scopes,
        OpenApiGateRequest $request,
    ): bool {
        if ($requirement === []) {
            return true;
        }
        if (!is_array($requirement)) {
            return false;
        }

        return array_all(
            $requirement,
            fn ($neededScopes, $schemeName): bool => $this->schemeSatisfied(
                $schemeName,
                $neededScopes,
                $identity,
                $scopes,
                $request,
            ),
        );
    }

    /**
     * @param null|array<string> $scopes
     */
    private function schemeSatisfied(
        mixed $schemeName,
        mixed $neededScopes,
        ?string $identity,
        ?array $scopes,
        OpenApiGateRequest $request,
    ): bool {
        /** @var int|string $schemeName */
        $scheme = $this->index->securitySchemes[$schemeName] ?? null;
        $satisfied = $identity !== null
            || (is_array($scheme) && $this->schemeEvidence($scheme, $request));

        // Scope checks only run when the application exposes grants;
        // otherwise the decision is delegated to the authorization layer
        // (parity matrix B10, documented pass-through).
        if ($satisfied && $scopes !== null && is_array($neededScopes) && $neededScopes !== []) {
            return $this->scopesGranted($neededScopes, $scopes);
        }

        return $satisfied;
    }

    /**
     * @param array<mixed, mixed> $neededScopes
     * @param array<string> $scopes
     */
    private function scopesGranted(array $neededScopes, array $scopes): bool
    {
        return array_all($neededScopes, fn ($scope): bool => !is_string($scope) || in_array($scope, $scopes, true));
    }

    /**
     * Cheap presence-of-evidence check — never a credential verification.
     *
     * @param array<mixed, mixed> $scheme
     */
    private function schemeEvidence(array $scheme, OpenApiGateRequest $request): bool
    {
        $type = $scheme['type'] ?? null;

        if ($type === 'http') {
            return $this->httpEvidence($scheme, $request);
        }
        if ($type === 'apiKey') {
            return $this->apiKeyEvidence($scheme, $request);
        }

        // oauth2 / openIdConnect / mutualTLS have no cheap evidence path —
        // only a verified identity attribute satisfies them (documented).
        return false;
    }

    /**
     * @param array<mixed, mixed> $scheme
     */
    private function httpEvidence(array $scheme, OpenApiGateRequest $request): bool
    {
        $authorization = $request->headers['authorization'] ?? '';
        if ($authorization === '') {
            return false;
        }
        $name = is_string($scheme['scheme'] ?? null) ? strtolower(trim($scheme['scheme'])) : '';

        return match ($name) {
            'bearer', 'basic', 'digest' => str_starts_with(strtolower($authorization), $name . ' '),
            default => true,
        };
    }

    /**
     * @param array<mixed, mixed> $scheme
     */
    private function apiKeyEvidence(array $scheme, OpenApiGateRequest $request): bool
    {
        $in = $scheme['in'] ?? null;
        $name = is_string($scheme['name'] ?? null) ? $scheme['name'] : '';
        if ($name === '') {
            return false;
        }

        return match ($in) {
            'header' => ($request->headers[strtolower($name)] ?? '') !== '',
            'query' => ($request->query[$name] ?? null) !== null && $request->query[$name] !== '',
            'cookie' => ($request->cookies[$name] ?? null) !== null && $request->cookies[$name] !== '',
            default => false,
        };
    }

    /**
     * @param array<mixed, mixed> $requirements
     */
    private function unsatisfiedVerdict(array $requirements, ?string $identity): OpenApiGateVerdict
    {
        $status = $identity !== null ? 403 : 401;
        $first = is_array($requirements[0] ?? null) ? $requirements[0] : [];
        $names = [];
        foreach ($first as $schemeName => $_scopes) {
            // Array keys are int|string; both render deterministically.
            $names[] = is_string($schemeName) ? $schemeName : (string) $schemeName;
        }
        $issues = [];
        foreach ($names as $schemeName) {
            $issues[] = [
                'in' => 'security',
                'name' => $schemeName,
                'pointer' => '',
                'message' => "security scheme '{$schemeName}' is not satisfied",
            ];
        }

        return OpenApiGateVerdict::rejected(
            $status,
            'The security requirement (' . implode(', ', $names) . ') is not satisfied.',
            $issues,
        );
    }
}
