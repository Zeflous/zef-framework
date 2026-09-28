<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Http\JsonResponse;

final readonly class SecurityRuntimeMiddleware implements MiddlewareInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
    private ?CsrfTokenManager $csrf;

    /**
     * @param list<string> $trustedProxies
     *
     * v2.31.0 (Regresi I-5 / issue #173): `$logger` is an optional PSR-3
     * sink for swallowed limiter failures — null by default keeps the
     * historical silent behaviour. A fail-closed mass 503 otherwise looked
     * identical whether it was an attack or a limiter storage bug.
     */
    public function __construct(
        private SecurityPolicy $policy,
        private RateLimiterInterface $rateLimiter,
        private array $trustedProxies = [],
        private ?LoggerInterface $logger = null,
    ) {
        $this->csrf = $policy->csrfEnabled && $policy->csrfSecret !== ''
            ? new CsrfTokenManager($policy->csrfSecret, $policy->csrfTokenBytes, $policy->csrfTokenTtlSeconds)
            : null;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $requestId = $request->getHeaderLine('X-Request-ID');
        if (
            $requestId === ''
            || strlen($requestId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1
        ) {
            $requestId = bin2hex(random_bytes(16));
        }
        $requestTrustedProxies = $request->getAttribute('__zef_trusted_proxies', $this->trustedProxies);
        $trustedProxies = is_array($requestTrustedProxies)
            ? array_values(array_filter($requestTrustedProxies, is_string(...)))
            : $this->trustedProxies;
        $context = new SecurityContext(
            $requestId,
            ClientAddressResolver::resolve($request, $trustedProxies),
            $request->getHeaderLine('Origin') !== '' ? $request->getHeaderLine('Origin') : null,
            strtolower($request->getUri()->getScheme()) === 'https',
        );
        $request = $request->withAttribute('zef.security.context', $context);

        $rateDecision = null;
        if ($this->policy->rateLimitEnabled) {
            try {
                $rateDecision = $this->rateLimiter->check(
                    $context->clientIp,
                    $this->policy->rateLimitMaxRequests,
                    $this->policy->rateLimitWindowSeconds,
                );
            } catch (RateLimiterCapacityException $e) {
                // ZEF-DEEP-02: a full key store is not a storage failure — the
                // request is served untracked rather than converting capacity
                // into a global 503. Buckets that already exist keep counting.
                $this->logSwallowedFailure('capacity exhausted (untracked fail-open)', $context->clientIp, $requestId, $e);
                $rateDecision = null;
            } catch (\Throwable $e) {
                // Regresi I-5 (issue #173): the swallowed failure is logged —
                // a mass 503 (fail-closed) must be diagnosable as attack vs
                // bug. Safe context only: client IP + request id, no headers.
                $this->logSwallowedFailure('storage failure (fail-closed 503)', $context->clientIp, $requestId, $e);

                return JsonResponse::error(503, 'Service Unavailable', ['correlation_id' => $requestId], [
                    'Retry-After' => '1',
                    'X-Request-ID' => $requestId,
                ]);
            }
            if ($rateDecision instanceof RateLimitDecision && !$rateDecision->allowed) {
                return JsonResponse::error(429, 'Too Many Requests', ['correlation_id' => $requestId], [
                    'Retry-After' => (string) $rateDecision->retryAfter,
                    'X-RateLimit-Limit' => (string) $rateDecision->limit,
                    'X-RateLimit-Remaining' => '0',
                    'X-Request-ID' => $requestId,
                ]);
            }
        }

        if ($this->policy->originEnabled) {
            try {
                OriginPolicy::assertAllowed($context->origin, $this->policy->allowedOrigins);
            } catch (\Throwable) {
                return JsonResponse::error(403, 'Forbidden', ['reason' => 'Origin denied'], [
                    'X-Request-ID' => $requestId,
                ]);
            }
        }

        $setCookie = null;
        if ($this->csrf instanceof CsrfTokenManager) {
            $cookieToken = $this->cookieValue($request->getHeaderLine('Cookie'), $this->policy->csrfCookieName);
            if (in_array($method, self::SAFE_METHODS, true)) {
                // Re-issue not only when the cookie is absent but also when
                // it is stale/invalid (secret rotation, tampering); the
                // old code left browsers permanently locked out of unsafe
                // requests with no recovery path.
                if ($cookieToken === null || !$this->csrf->isValid($cookieToken)) {
                    $setCookie = $this->csrf->issue();
                }
            } else {
                $headerToken = $request->getHeaderLine($this->policy->csrfHeaderName);
                if (
                    $cookieToken === null
                    || $headerToken === ''
                    || !$this->csrf->isValid($cookieToken)
                    || !hash_equals($cookieToken, $headerToken)
                ) {
                    return JsonResponse::error(403, 'Forbidden', ['reason' => 'CSRF validation failed'], [
                        'X-Request-ID' => $requestId,
                    ]);
                }
            }
        }

        $response = $handler->handle($request)->withHeader('X-Request-ID', $requestId);
        if ($rateDecision instanceof RateLimitDecision) {
            $response = $response
                ->withHeader('X-RateLimit-Limit', (string) $rateDecision->limit)
                ->withHeader('X-RateLimit-Remaining', (string) $rateDecision->remaining)
            ;
        }
        if ($setCookie !== null) {
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
            $response = $response->withAddedHeader('Set-Cookie', implode('; ', $attributes));
        }

        return $response;
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
