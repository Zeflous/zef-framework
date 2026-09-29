<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\RequestScope;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Observability\SpanInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Router\Router;

final readonly class Dispatcher implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private Container $container,
    ) {}

    /**
     * Bug fix #20: uses JsonResponse; handles MethodNotAllowedException (defence in depth).
     */
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Telemetry $telemetry */
        $telemetry = $this->container->get(Telemetry::class);
        $parent = $request->getAttribute('__zef_telemetry_span');
        $parentContext = $parent instanceof SpanInterface ? $parent->getContext() : null;
        $routerSpan = $telemetry->startSpan(
            'zef.router.match',
            [
                'http.request.method' => $request->getMethod(),
                'url.path' => $request->getUri()->getPath(),
            ],
            $parentContext,
        );
        $started = hrtime(true);

        try {
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
            $routePattern = is_string($match['pattern'] ?? null) ? $match['pattern'] : '';
            $routerSpan->setAttribute('http.route', $routePattern)->setStatus('OK');
            foreach ($match['params'] as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }
            $scope = $request->getAttribute('__zef_request_scope');
            $resolver = $scope instanceof RequestScope
                ? $scope
                : $this->container;
            $resolveStart = hrtime(true);
            $handler = $resolver->get($match['handler']);
            $telemetry->meter()->observe(
                'zef.container.resolve.duration_seconds',
                (hrtime(true) - $resolveStart) / 1_000_000_000,
                ['zef.service.id' => is_string($match['handler'] ?? null) ? $match['handler'] : ''],
            );
            if (!$handler instanceof RequestHandlerInterface) {
                throw new InvalidConfigurationException(
                    "Handler '{$match['handler']}' does not implement RequestHandlerInterface."
                );
            }
            $handlerSpan = $telemetry->startSpan(
                'zef.handler.execute',
                [
                    'zef.handler' => is_string($match['handler'] ?? null) ? $match['handler'] : '',
                    'http.route' => $routePattern,
                ],
                $parentContext,
            );

            try {
                $response = $handler->handle($request);
                $handlerSpan
                    ->setAttribute('http.response.status_code', $response->getStatusCode())
                    ->setStatus($response->getStatusCode() >= 500 ? 'ERROR' : 'OK')
                ;

                return $response;
            } catch (\Throwable $e) {
                $handlerSpan->setStatus('ERROR', $e::class)
                    ->addEvent('exception', ['exception.type' => $e::class])
                ;

                throw $e;
            } finally {
                $handlerSpan->end();
            }
        } catch (MethodNotAllowedException|RouteConstraintException|RouteNotFoundException $e) {
            return $this->routeFailureResponse($e, $request);
        } finally {
            $routerSpan->setAttribute(
                'zef.router.duration_seconds',
                (hrtime(true) - $started) / 1_000_000_000,
            );
            $routerSpan->end();
        }
    }

    /**
     * Maps the three router ingress failures onto their JSON error responses
     * (404/405/400 — bug fix #20 kept JsonResponse for all of them).
     */
    private function routeFailureResponse(
        MethodNotAllowedException|RouteConstraintException|RouteNotFoundException $e,
        ServerRequestInterface $request,
    ): ResponseInterface {
        if ($e instanceof RouteNotFoundException) {
            return JsonResponse::error(404, 'Not Found', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }
        if ($e instanceof MethodNotAllowedException) {
            return JsonResponse::error(405, 'Method Not Allowed', [
                'method' => $e->method,
                'path' => $e->path,
                'allow' => $e->allowedMethods,
            ], ['Allow' => implode(', ', $e->allowedMethods)]);
        }

        return JsonResponse::error(400, 'Bad Request', ['detail' => $e->getMessage()]);
    }
}
