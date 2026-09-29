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
use Zef\Framework\Http\Response;
use Zef\Framework\Security\OriginPolicy;

final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @var list<string>
     */
    private array $allowedOrigins;
    private bool $allowAll;

    /** @param null|list<string>|string $allowOrigin */
    public function __construct(
        array|string|null $allowOrigin = null,
        private string $allowMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private string $allowHeaders = 'Content-Type, Authorization, X-Request-ID',
    ) {
        $origins = is_array($allowOrigin) ? $allowOrigin : [$allowOrigin];
        $normalized = [];
        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                continue;
            }
            $origin = trim($origin);
            if ($origin === '') {
                continue;
            }
            if ($origin === '*') {
                $this->allowAll = true;
                $this->allowedOrigins = ['*'];

                return;
            }
            $normalized[] = $this->normalizeOrigin($origin);
        }
        $this->allowAll = false;
        $this->allowedOrigins = array_values(array_unique($normalized));
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $preflight = $this->preflightResponse($request);
        if ($preflight !== null) {
            return $preflight;
        }

        $response = $handler->handle($request);
        $origin = $this->negotiatedOrigin($request);
        if ($origin === null) {
            return $response;
        }

        return $this->decorateWithCorsHeaders($response, $origin);
    }

    /**
     * Returns the 204 response for a CORS preflight (or a rejected OPTIONS
     * request), or null when the request should continue through the handler.
     */
    private function preflightResponse(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->isOptions($request) || $this->allowedOrigins === []) {
            return null;
        }
        $echo = $this->negotiatedOrigin($request);
        if ($echo === null) {
            return $this->disallowedPreflightResponse($request);
        }

        return new Response(204, [
            'Access-Control-Allow-Origin' => $echo,
            'Access-Control-Allow-Methods' => $this->allowMethods,
            'Access-Control-Allow-Headers' => $this->allowHeaders,
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }

    /**
     * A disallowed-origin OPTIONS request is answered with a bare Vary
     * response; an absent Origin header falls through to the handler.
     */
    private function disallowedPreflightResponse(ServerRequestInterface $request): ?ResponseInterface
    {
        if (trim($request->getHeaderLine('Origin')) === '') {
            return null;
        }

        return new Response(204, ['Vary' => 'Origin']);
    }

    /**
     * Returns the origin value that must be echoed back in CORS headers, or
     * null when the response must not be decorated (CORS disabled, no Origin
     * header, or an origin the policy does not allow).
     */
    private function negotiatedOrigin(ServerRequestInterface $request): ?string
    {
        if ($this->allowedOrigins === []) {
            return null;
        }
        if ($this->allowAll) {
            return '*';
        }
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '' || !$this->originIsAllowed($origin)) {
            return null;
        }

        return $origin;
    }

    private function decorateWithCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', $this->allowMethods)
            ->withHeader('Access-Control-Allow-Headers', $this->allowHeaders)
        ;

        return $response->withHeader('Vary', implode(', ', $this->mergedVaryTokens($response)));
    }

    /**
     * @return list<string>
     */
    private function mergedVaryTokens(ResponseInterface $response): array
    {
        $tokens = [];
        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $tokens[strtolower($token)] = $token;
                }
            }
        }
        $tokens['origin'] = 'Origin';

        return array_values($tokens);
    }

    private function isOptions(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS';
    }

    private function originIsAllowed(string $origin): bool
    {
        try {
            $normalized = $this->normalizeOrigin($origin);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return in_array($normalized, $this->allowedOrigins, true);
    }

    /**
     * Delegates to OriginPolicy::normalizeOrigin() to eliminate duplication.
     */
    private function normalizeOrigin(string $origin): string
    {
        return OriginPolicy::normalizeOrigin($origin);
    }
}
