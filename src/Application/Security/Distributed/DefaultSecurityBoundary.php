<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class DefaultSecurityBoundary implements SecurityBoundaryInterface
{
    #[\Override]
    public function admit(
        AuthenticationResult $authentication,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityAdmissionDecision {
        $failure = $this->authenticationFailure($authentication);
        if ($failure === SecurityFailure::NONE) {
            $failure = $this->postAuthenticationFailure(
                $authentication->context,
                $request,
                $authorization,
                $replayProtector,
                $nowMs,
            );
        }

        return new SecurityAdmissionDecision(
            $failure === SecurityFailure::NONE ? SecurityVerdict::ALLOW : SecurityVerdict::DENY,
            $failure,
            false,
        );
    }

    private function authenticationFailure(AuthenticationResult $authentication): SecurityFailure
    {
        if ($authentication->status !== AuthenticationStatus::AUTHENTICATED) {
            return match ($authentication->status) {
                AuthenticationStatus::EXPIRED => SecurityFailure::CREDENTIAL_EXPIRED,
                AuthenticationStatus::UNAVAILABLE => SecurityFailure::AUTHENTICATION_UNAVAILABLE,
                default => SecurityFailure::AUTHENTICATION_FAILED,
            };
        }

        return $authentication->context instanceof SecurityContext
            ? SecurityFailure::NONE
            : SecurityFailure::MALFORMED_METADATA;
    }

    private function postAuthenticationFailure(
        ?SecurityContext $context,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityFailure {
        if ($context === null) {
            return SecurityFailure::MALFORMED_METADATA;
        }
        if ($authorization->authorize($context, $request)->verdict !== SecurityVerdict::ALLOW) {
            return SecurityFailure::AUTHORIZATION_DENIED;
        }

        return $this->replayFailure($replayProtector, $request, $nowMs);
    }

    private function replayFailure(
        ReplayProtectorInterface $replayProtector,
        SecurityRequest $request,
        int $nowMs,
    ): SecurityFailure {
        $replay = $replayProtector->check($request->replayId, $nowMs);
        if ($replay->allows()) {
            return SecurityFailure::NONE;
        }

        return match ($replay->decision) {
            ReplayDecision::UNAVAILABLE => SecurityFailure::REPLAY_UNAVAILABLE,
            default => SecurityFailure::REPLAY_REJECTED,
        };
    }
}
