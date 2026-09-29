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
use Zef\Framework\Security\OriginPolicy;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $allowedOrigins;
    private readonly bool $allowAll;

    /** @param null|list<string>|string $allowOrigin */
    public function __construct(
        array|string|null $allowOrigin = null,
        private readonly string $allowMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private readonly string $allowHeaders = 'Content-Type, Authorization, X-Request-ID',
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
        if (!$this->requestOriginIsAllowed($requestOrigin)) {
            return $this->rejectOrigin($request, $handler);
        }

        return $this->corsResponse($request, $handler, $requestOrigin);
    }

    /** True when the request's Origin header may receive CORS headers. */
    private function requestOriginIsAllowed(string $requestOrigin): bool
    {
        if ($this->allowAll) {
            return true;
        }

        return $requestOrigin !== '' && $this->originIsAllowed($requestOrigin);
    }

    /** A disallowed origin gets a bare 204 preflight or a plain pass-through. */
    private function rejectOrigin(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return new Response(204, ['Vary' => 'Origin']);
        }

        return $handler->handle($request);
    }

    /** The preflight (204) or simple-request response carrying the CORS headers. */
    private function corsResponse(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $requestOrigin,
    ): ResponseInterface {
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

        return self::asResponse(
            $response->withHeader('Vary', implode(', ', $this->mergedVary($response))),
        );
    }

    /** The response's existing Vary tokens plus Origin, deduplicated case-insensitively.
     *
     * @return list<string>
     */
    private function mergedVary(MessageInterface $message): array
    {
        $tokens = [];
        foreach ($message->getHeader('Vary') as $line) {
            if (!is_string($line)) {
                continue;
            }
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

// Retained for legacy modules; core now depends on Psr\Log\LoggerInterface.
