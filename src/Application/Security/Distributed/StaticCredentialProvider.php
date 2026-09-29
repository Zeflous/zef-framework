<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class StaticCredentialProvider implements CredentialProviderInterface
{
    /**
     * @var list<string> SHA-256 digests of the accepted tokens
     */
    private array $tokenDigests;

    /** @param list<string> $validTokens */
    public function __construct(
        array $validTokens,
        private string $principalId = 'static-principal',
        private string $scope = 'api',
        private int $expiresAtMs = 0,
    ) {
        if ($validTokens === []) {
            throw new \InvalidArgumentException('StaticCredentialProvider requires at least one valid token.');
        }
        // Compare fixed-length SHA-256 digests instead of the raw secrets
        // so the authentication lookup cannot leak the raw token's length
        // or a matching prefix. N-2 (issue #176): this is digest
        // hardening, NOT constant-time — in_array() still early-exits on
        // digest equality (hash_equals is not used); the residual timing
        // signal only reveals the digest's position in the list, never
        // any property of the raw secret itself.
        $this->tokenDigests = array_map(static fn (string $t): string => hash('sha256', $t), $validTokens);
    }

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        if (!in_array(hash('sha256', $handle->handleId), $this->tokenDigests, true)) {
            return new AuthenticationResult(AuthenticationStatus::FAILED);
        }
        if ($this->expiresAtMs > 0 && $nowMs > $this->expiresAtMs) {
            return new AuthenticationResult(AuthenticationStatus::EXPIRED);
        }

        return new AuthenticationResult(
            AuthenticationStatus::AUTHENTICATED,
            new SecurityContext(
                principalId: $this->principalId,
                authenticationMethod: 'static-bearer',
                authorizationContext: 'route',
                credentialScope: $this->scope,
                peerIdentity: null,
            ),
        );
    }
}
