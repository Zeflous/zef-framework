<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @param array{hsts?:bool,csp?:bool,contentSecurityPolicy?:string,permissionsPolicy?:bool} $policy */
    public function __construct(private readonly array $policy = []) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ;
        if (($this->policy['permissionsPolicy'] ?? true) === true) {
            $response = $response->withHeader(
                'Permissions-Policy',
                'camera=(), microphone=(), geolocation=(), payment=()',
            );
        }
        if (($this->policy['csp'] ?? false) === true) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                (string) ($this->policy['contentSecurityPolicy'] ?? "default-src 'self'; frame-ancestors 'none'; base-uri 'self'"),
            );
        }
        if (
            ($this->policy['hsts'] ?? false) === true
            && strtolower($request->getUri()->getScheme()) === 'https'
        ) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return self::asResponse($response);
    }

    /**
     * PSR-7 declares `withHeader()` as returning `MessageInterface`, so a
     * fluent header chain loses the `ResponseInterface` type even though the
     * runtime object is always the same immutable response. Narrow it back
     * explicitly instead of widening this method's return type.
     */
    private static function asResponse(MessageInterface $message): ResponseInterface
    {
        assert($message instanceof ResponseInterface);

        return $message;
    }
}
