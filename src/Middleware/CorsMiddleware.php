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
        if ($this->allowedOrigins === []) {
            return $handler->handle($request);
        }
        $requestOrigin = trim($request->getHeaderLine('Origin'));
        if ($this->allowAll) {
            $originAllowed = true;
        } elseif ($requestOrigin === '') {
            return $handler->handle($request);
        } else {
            $originAllowed = $this->originIsAllowed($requestOrigin);
            if (!$originAllowed) {
                if (strtoupper($request->getMethod()) === 'OPTIONS') {
                    return new Response(204, ['Vary' => 'Origin']);
                }

                return $handler->handle($request);
            }
        }
        $allowOrigin = $this->allowAll ? '*' : $requestOrigin;
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => $allowOrigin,
                'Access-Control-Allow-Methods' => $this->allowMethods,
                'Access-Control-Allow-Headers' => $this->allowHeaders,
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ]);
        }
        $response = $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', $this->allowMethods)
            ->withHeader('Access-Control-Allow-Headers', $this->allowHeaders)
        ;
        $vary = $response->getHeader('Vary');
        $tokens = [];
        foreach ($vary as $line) {
            foreach (explode(',', $line) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $tokens[strtolower($token)] = $token;
                }
            }
        }
        $tokens['origin'] = 'Origin';

        return $response->withHeader('Vary', implode(', ', array_values($tokens)));
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
