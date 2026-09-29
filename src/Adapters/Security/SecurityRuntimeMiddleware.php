<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Http\JsonResponse;

final class SecurityRuntimeMiddleware implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Lazily created on the first CSRF-enforcing request (php:S2830): the
     * manager is a stateless secret/bytes/TTL holder, so deferring its
     * construction out of the constructor has no observable effect.
     */
    private ?CsrfTokenManager $csrf = null;

    /**
     * @param list<string> $trustedProxies
     *
     * v2.31.0 (Regresi I-5 / issue #173): `$logger` is an optional PSR-3
     * sink for swallowed limiter failures — null by default keeps the
     * historical silent behaviour. A fail-closed mass 503 otherwise looked
     * identical whether it was an attack or a limiter storage bug.
     */
    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly array $trustedProxies = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $requestId = $this->sanitizeRequestId($request->getHeaderLine('X-Request-ID'));
        $context = $this->buildSecurityContext($request, $requestId);
        $request = $request->withAttribute('zef.security.context', $context);

        $gate = $this->runSecurityGates($method, $request, $context, $requestId);
        if ($gate['shortCircuit'] !== null) {
            return $gate['shortCircuit'];
        }

        $response = $handler->handle($request)->withHeader('X-Request-ID', $requestId);
        $response = $this->decorateWithRateHeaders($response, $gate['rateDecision']);

        return $this->decorateWithCsrfCookie($response, $gate['csrfCookie']);
    }

    /**
     * Sanitizes the inbound X-Request-ID header: an absent, oversized or
     * malformed id is replaced with a fresh random one.
     */
    private function sanitizeRequestId(string $requestId): string
    {
        if (
            $requestId === ''
            || strlen($requestId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1
        ) {
            return bin2hex(random_bytes(16));
        }

        return $requestId;
    }

    private function buildSecurityContext(ServerRequestInterface $request, string $requestId): SecurityContext
    {
        $requestTrustedProxies = $request->getAttribute('__zef_trusted_proxies', $this->trustedProxies);
        $trustedProxies = is_array($requestTrustedProxies)
            ? array_values(array_filter($requestTrustedProxies, is_string(...)))
            : $this->trustedProxies;

        return new SecurityContext(
            $requestId,
            ClientAddressResolver::resolve($request, $trustedProxies),
            $request->getHeaderLine('Origin') !== '' ? $request->getHeaderLine('Origin') : null,
            strtolower($request->getUri()->getScheme()) === 'https',
        );
    }

    /**
     * Runs the rate-limit, origin and CSRF gates in order and returns the
     * first short-circuiting error response (null when the request may
     * proceed), together with the rate decision and the CSRF cookie that a
     * successful response must carry.
     *
     * @return array{
     *     shortCircuit: null|ResponseInterface,
     *     rateDecision: null|RateLimitDecision,
     *     csrfCookie: null|string
     * }
     */
    private function runSecurityGates(
        string $method,
        ServerRequestInterface $request,
        SecurityContext $context,
        string $requestId,
    ): array {
        [$rateDecision, $rateResponse] = $this->enforceRateLimit($context, $requestId);
        if ($rateResponse instanceof ResponseInterface) {
            return ['shortCircuit' => $rateResponse, 'rateDecision' => $rateDecision, 'csrfCookie' => null];
        }
        $originResponse = $this->originDeniedResponse($context, $requestId);
        if ($originResponse instanceof ResponseInterface) {
            return ['shortCircuit' => $originResponse, 'rateDecision' => $rateDecision, 'csrfCookie' => null];
        }
        [$csrfCookie, $csrfResponse] = $this->enforceCsrf($method, $request, $requestId);

        return ['shortCircuit' => $csrfResponse, 'rateDecision' => $rateDecision, 'csrfCookie' => $csrfCookie];
    }

    /**
     * @return array{0: null|RateLimitDecision, 1: null|ResponseInterface}
     */
    private function enforceRateLimit(SecurityContext $context, string $requestId): array
    {
        if (!$this->policy->rateLimitEnabled) {
            return [null, null];
        }

        try {
            $decision = $this->rateLimiter->check(
                $context->clientIp,
                $this->policy->rateLimitMaxRequests,
                $this->policy->rateLimitWindowSeconds,
            );
        } catch (RateLimiterCapacityException $e) {
            // ZEF-DEEP-02: a full key store is not a storage failure — the
            // request is served untracked rather than converting capacity
            // into a global 503. Buckets that already exist keep counting.
            $this->logSwallowedFailure('capacity exhausted (untracked fail-open)', $context->clientIp, $requestId, $e);

            return [null, null];
        } catch (\Throwable $e) {
            // Regresi I-5 (issue #173): the swallowed failure is logged —
            // a mass 503 (fail-closed) must be diagnosable as attack vs
            // bug. Safe context only: client IP + request id, no headers.
            $this->logSwallowedFailure('storage failure (fail-closed 503)', $context->clientIp, $requestId, $e);

            return [null, SecurityResponseFactory::serviceUnavailable($requestId)];
        }
        $response = $decision->allowed ? null : SecurityResponseFactory::tooManyRequests($decision, $requestId);

        return [$decision, $response];
    }

    /**
     * Returns a 403 response when the origin policy denies the request, or
     * null when the policy is disabled or allows the origin.
     */
    private function originDeniedResponse(SecurityContext $context, string $requestId): ?ResponseInterface
    {
        if (!$this->policy->originEnabled) {
            return null;
        }

        try {
            OriginPolicy::assertAllowed($context->origin, $this->policy->allowedOrigins);
        } catch (\Throwable) {
            return SecurityResponseFactory::originDenied($requestId);
        }

        return null;
    }

    /**
     * Runs the CSRF gate. Returns either the 403 response for an unsafe
     * method carrying a missing, stale or mismatched token, or the Set-Cookie
     * value that a safe-method response must re-issue; both are null when
     * CSRF is disabled.
     *
     * @return array{0: null|string, 1: null|ResponseInterface}
     */
    private function enforceCsrf(string $method, ServerRequestInterface $request, string $requestId): array
    {
        $tokenManager = $this->csrf();
        if (!$tokenManager instanceof CsrfTokenManager) {
            return [null, null];
        }
        $cookieToken = $this->cookieValue($request->getHeaderLine('Cookie'), $this->policy->csrfCookieName);
        if (!in_array($method, self::SAFE_METHODS, true)) {
            return [null, $this->unsafeMethodCsrfResponse($tokenManager, $request, $cookieToken, $requestId)];
        }
        // Re-issue not only when the cookie is absent but also when it is
        // stale/invalid (secret rotation, tampering); the old code left
        // browsers permanently locked out of unsafe requests with no
        // recovery path.
        $needsReissue = $cookieToken === null || !$tokenManager->isValid($cookieToken);

        return [$needsReissue ? $tokenManager->issue() : null, null];
    }

    private function unsafeMethodCsrfResponse(
        CsrfTokenManager $tokenManager,
        ServerRequestInterface $request,
        ?string $cookieToken,
        string $requestId,
    ): ?ResponseInterface {
        $headerToken = $request->getHeaderLine($this->policy->csrfHeaderName);
        $tokenValid = $cookieToken !== null
            && $headerToken !== ''
            && $tokenManager->isValid($cookieToken)
            && hash_equals($cookieToken, $headerToken);
        if ($tokenValid) {
            return null;
        }

        return SecurityResponseFactory::csrfFailed($requestId);
    }

    private function decorateWithRateHeaders(
        MessageInterface $response,
        ?RateLimitDecision $decision,
    ): MessageInterface {
        if (!$decision instanceof RateLimitDecision) {
            return $response;
        }

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $decision->remaining)
        ;
    }

    private function decorateWithCsrfCookie(MessageInterface $response, ?string $setCookie): MessageInterface
    {
        if ($setCookie === null) {
            return $response;
        }
        $attributes = [
            $this->policy->csrfCookieName . '=' . $setCookie,
            'Path=/',
            'SameSite=' . $this->policy->csrfSameSite,
        ];
        if ($this->policy->csrfSecureCookie) {
            // Emit Secure purely on policy: gating it on the URI scheme
            // silently drops the flag behind TLS-terminating proxies,
            // and SameSite=None + missing Secure makes browsers reject
            // the cookie outright.
            $attributes[] = 'Secure';
        }
        if ($this->policy->csrfHttpOnlyCookie) {
            $attributes[] = 'HttpOnly';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $attributes));
    }

    private function csrf(): ?CsrfTokenManager
    {
        $csrfNotBuilt = !$this->csrf instanceof CsrfTokenManager;
        if ($csrfNotBuilt && $this->policy->csrfEnabled && $this->policy->csrfSecret !== '') {
            $this->csrf = new CsrfTokenManager(
                $this->policy->csrfSecret,
                $this->policy->csrfTokenBytes,
                $this->policy->csrfTokenTtlSeconds,
            );
        }

        return $this->csrf;
    }

    /**
     * Regresi I-5 (issue #173): one concise error-level record per swallowed
     * limiter failure — exactly one per failure path, never per retry. Only
     * safe context is logged (client IP and request id); a null logger keeps
     * the pre-v2.31.0 silent behaviour (BC for every existing wiring).
     */
    private function logSwallowedFailure(string $mode, string $clientIp, string $requestId, \Throwable $error): void
    {
        $this->logger?->error(
            '[ZEF][security] Rate limiter ' . $mode
            . ' for client ' . $clientIp
            . ' on request ' . $requestId
            . ': ' . $error::class . ': ' . $error->getMessage(),
        );
    }

    /**
     * Bug fix #16: trim($key) before comparing.
     */
    private function cookieValue(string $header, string $name): ?string
    {
        foreach (explode(';', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if (trim($key) === $name) {
                return trim($value);
            }
        }

        return null;
    }
}
