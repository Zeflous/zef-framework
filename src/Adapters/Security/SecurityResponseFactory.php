<?php

declare(strict_types=1);

/*
 * ZEF Framework — security-gate error response builders.
 *
 * Extracted from SecurityRuntimeMiddleware during the sonar-zero campaign
 * (php:S2042 class-size budget): behavior-preserving move — status codes,
 * bodies and headers are byte-identical to the former private builders.
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\ResponseInterface;
use Zef\Framework\Http\JsonResponse;

/**
 * Builds the fixed JSON error responses that the runtime security gates
 * (rate limit, origin, CSRF) short-circuit with. Stateless by design: every
 * builder derives purely from its arguments.
 */
final class SecurityResponseFactory
{
    public static function serviceUnavailable(string $requestId): ResponseInterface
    {
        return JsonResponse::error(503, 'Service Unavailable', ['correlation_id' => $requestId], [
            'Retry-After' => '1',
            'X-Request-ID' => $requestId,
        ]);
    }

    public static function tooManyRequests(RateLimitDecision $decision, string $requestId): ResponseInterface
    {
        return JsonResponse::error(429, 'Too Many Requests', ['correlation_id' => $requestId], [
            'Retry-After' => (string) $decision->retryAfter,
            'X-RateLimit-Limit' => (string) $decision->limit,
            'X-RateLimit-Remaining' => '0',
            'X-Request-ID' => $requestId,
        ]);
    }

    public static function originDenied(string $requestId): ResponseInterface
    {
        return JsonResponse::error(403, 'Forbidden', ['reason' => 'Origin denied'], [
            'X-Request-ID' => $requestId,
        ]);
    }

    public static function csrfFailed(string $requestId): ResponseInterface
    {
        return JsonResponse::error(403, 'Forbidden', ['reason' => 'CSRF validation failed'], [
            'X-Request-ID' => $requestId,
        ]);
    }
}
