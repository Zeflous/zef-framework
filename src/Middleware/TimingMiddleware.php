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

final readonly class TimingMiddleware implements MiddlewareInterface
{
    /**
     * ZEF-DX-08 (issue #248): the header is OFF in production by default —
     * server-side execution timings are an attacker-visible side channel
     * (full-precision timing oracle against blind attacks). It stays ON in
     * debug mode (where it is a legitimate development signal) and can be
     * forced either way per deployment with ZEF_TIMING_HEADER=1/0.
     */
    public function __construct(
        private bool $emitHeader = true,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startNs = hrtime(true);
        $response = $handler->handle($request);
        if (!$this->emitHeader) {
            return $response;
        }
        $elapsedMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

        return $response->withHeader('X-Response-Time', $elapsedMs . 'ms');
    }
}
