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
use Psr\Log\LoggerInterface;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\Response;
use Zef\Framework\Observability\TelemetrySanitizer;

final class GlobalErrorHandler implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ErrorResponseFactory $factory,
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
            return self::asResponse($handler->handle($request)->withHeader('X-Request-ID', $correlationId));
        } catch (MethodNotAllowedException $e) {
            return self::asResponse(
                $this->factory->create(405, 'Method Not Allowed', $correlationId)
                    ->withHeader('Allow', implode(', ', $e->allowedMethods))
                    ->withHeader('X-Request-ID', $correlationId),
            );
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
                @error_log('ZEF logging failure: ' . get_class($loggingFailure));
            }

            try {
                return self::asResponse(
                    $this->factory->create(
                        500,
                        $this->factory->isDebug() ? $e->getMessage() : 'Internal Server Error',
                        $correlationId,
                    )->withHeader('X-Request-ID', $correlationId),
                );
            } catch (\Throwable) {
                return new Response(500, [
                    'Content-Type' => 'text/plain',
                    'X-Request-ID' => $correlationId,
                ], 'Internal Server Error');
            }
        }
    }

    /**
     * PSR-7 declares `withHeader()` as returning `MessageInterface`, so a
     * fluent header chain loses the `ResponseInterface` type even though the
     * runtime object is always the same immutable response. Narrow it back
     * explicitly instead of widening this method's return type.
     */
    private static function asResponse(MessageInterface $message): ResponseInterface
    {
        \assert($message instanceof ResponseInterface);

        return $message;
    }
}
