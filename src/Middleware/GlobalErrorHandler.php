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
use Psr\Log\LoggerInterface;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\Response;
use Zef\Framework\Observability\TelemetrySanitizer;

final readonly class GlobalErrorHandler implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ErrorResponseFactory $factory,
    ) {}

    /**
     * Bug fix #20: handles MethodNotAllowedException (defence in depth); uses JsonResponse.
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $correlationId = $request->getHeaderLine('X-Request-ID');
        if (
            $correlationId === ''
            || strlen($correlationId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $correlationId) !== 1
        ) {
            $correlationId = bin2hex(random_bytes(16));
        }

        try {
            return $handler->handle($request)->withHeader('X-Request-ID', $correlationId);
        } catch (MethodNotAllowedException $e) {
            return $this->factory->create(405, 'Method Not Allowed', $correlationId)
                ->withHeader('Allow', implode(', ', $e->allowedMethods))
                ->withHeader('X-Request-ID', $correlationId)
            ;
        } catch (\Throwable $e) {
            try {
                $this->logger->error('Unhandled application exception', [
                    'exception' => $e,
                    'exception.message' => TelemetrySanitizer::redact($e->getMessage()),
                    'request_id' => $correlationId,
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                ]);
            } catch (\Throwable $loggingFailure) {
                // Last-resort sink: the PSR-3 logger itself is broken, so the
                // class name is written straight to the SAPI error log. The
                // bool return value is intentionally ignored — there is no
                // lower fallback left to try.
                error_log('ZEF logging failure: ' . $loggingFailure::class);
            }

            return $this->internalErrorResponse($e, $correlationId);
        }
    }

    /**
     * Builds the 500 response through the error factory, falling back to a
     * plain text response when the factory itself is unavailable (double
     * failure) so the client still receives a 500 with the correlation id.
     */
    private function internalErrorResponse(\Throwable $e, string $correlationId): ResponseInterface
    {
        try {
            return $this->factory->create(
                500,
                $this->factory->isDebug() ? $e->getMessage() : 'Internal Server Error',
                $correlationId,
            )->withHeader('X-Request-ID', $correlationId);
        } catch (\Throwable) {
            return new Response(500, [
                'Content-Type' => 'text/plain',
                'X-Request-ID' => $correlationId,
            ], 'Internal Server Error');
        }
    }
}
