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

final class TimingMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startNs = hrtime(true);
        $response = $handler->handle($request);
        $elapsedMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

        return self::asResponse($response->withHeader('X-Response-Time', $elapsedMs . 'ms'));
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
