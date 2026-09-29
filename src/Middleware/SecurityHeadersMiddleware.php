<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @param array{hsts?:bool,csp?:bool,contentSecurityPolicy?:string,permissionsPolicy?:bool} $policy */
    public function __construct(private array $policy = []) {}

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
            // N-3 (issue #176): HSTS is deliberately scheme-gated while the
            // Secure cookie attribute (SecurityRuntimeMiddleware) is emitted
            // purely on policy. RFC 6797 §7.2 forbids sending
            // Strict-Transport-Security over non-secure transport: an MITM
            // controlling plain http could otherwise pin the host's HTTPS
            // policy. A Secure cookie on plain http is harmless (browsers
            // simply never echo it back), and scheme-gating it would silently
            // drop the flag behind TLS-terminating proxies.
            return $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
