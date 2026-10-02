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
use Psr\Http\Server\MiddlewareInterface;
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
use Zef\Framework\Router\ContentNegotiator;
use Zef\Framework\Router\RouteModelBinderInterface;
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
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            $host = $request->getUri()->getHost();
            // v2.36.0: host-aware matching (subdomain routing).
            $match = $this->router->match($method, $path, $host);
            $routePattern = is_string($match['pattern'] ?? null) ? $match['pattern'] : '';
            $routerSpan->setAttribute('http.route', $routePattern)->setStatus('OK');
            // v2.36.0 content negotiation: a route that advertises its
            // accepted representations answers 406 when the request cannot
            // accept any of them.
            $accepts = is_array($match['accepts'] ?? null) ? $match['accepts'] : [];
            if ($accepts !== [] && !ContentNegotiator::matches($request->getHeaderLine('Accept'), $accepts)) {
                return JsonResponse::error(406, 'Not Acceptable', ['representations' => $accepts]);
            }
            foreach ($match['params'] as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }
            $scope = $request->getAttribute('__zef_request_scope');
            $resolver = $scope instanceof RequestScope
                ? $scope
                : $this->container;
            // v2.36.0 route model binding: replace declared string params
            // with their bound values before the handler sees the request.
            $request = $this->applyModelBindings($request, $match, $resolver);
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
            // v2.36.0 (fix P0-1): the matched route's middleware service IDs
            // are wrapped around the handler as an inner pipeline — before
            // this fix the metadata existed but was never executed.
            $terminal = $this->routePipeline(
                is_array($match['middleware'] ?? null) ? $match['middleware'] : [],
                $handler,
                $resolver,
            );
            $handlerSpan = $telemetry->startSpan(
                'zef.handler.execute',
                [
                    'zef.handler' => is_string($match['handler'] ?? null) ? $match['handler'] : '',
                    'http.route' => $routePattern,
                ],
                $parentContext,
            );

            try {
                $response = $terminal->handle($request);
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
     * v2.36.0 (fixed finding P0-1): builds the per-route middleware pipeline
     * from the matched route's `middleware` service-ID list. Before this fix
     * the metadata was recorded and printed by `route:list` but NEVER
     * executed — an auth/rate-limit middleware attached to a route group was
     * silently a no-op (a real security gap). The handler becomes the
     * pipeline terminal, so route middleware runs innermost, inside the
     * global `middleware.stack` pipeline that already wraps this dispatcher.
     *
     * @param list<string> $middleware
     */
    private function routePipeline(
        array $middleware,
        RequestHandlerInterface $handler,
        Container|RequestScope $resolver,
    ): RequestHandlerInterface {
        if ($middleware === []) {
            return $handler;
        }
        $stack = [];
        foreach ($middleware as $id) {
            if (!is_string($id) || $id === '') {
                throw new InvalidConfigurationException(
                    'Route middleware entries must be non-empty service IDs.',
                );
            }
            if (!$resolver->has($id)) {
                throw new InvalidConfigurationException("Route middleware service '{$id}' is not registered.");
            }
            $entry = $resolver->get($id);
            if (!$entry instanceof MiddlewareInterface) {
                throw new InvalidConfigurationException("Service '{$id}' does not implement MiddlewareInterface.");
            }
            $stack[] = $entry;
        }

        return new MiddlewarePipeline($stack, $handler);
    }

    /**
     * v2.36.0: applies the matched route's model bindings. Each declared
     * `bindings[param]` service id is resolved and its resolve() output is
     * stored as a request attribute (replacing the raw string parameter).
     *
     * @param array<string,mixed> $match
     */
    private function applyModelBindings(
        ServerRequestInterface $request,
        array $match,
        Container|RequestScope $resolver,
    ): ServerRequestInterface {
        $bindings = is_array($match['bindings'] ?? null) ? $match['bindings'] : [];
        foreach ($bindings as $param => $serviceId) {
            if (!is_string($param) || $param === '' || !is_string($serviceId) || $serviceId === '') {
                continue;
            }
            $rawValue = $request->getAttribute($param);
            if (!is_string($rawValue)) {
                continue;
            }
            if (!$resolver->has($serviceId)) {
                throw new InvalidConfigurationException(
                    "Route binding service '{$serviceId}' for parameter '{$param}' is not registered.",
                );
            }
            $binder = $resolver->get($serviceId);
            if (!$binder instanceof RouteModelBinderInterface) {
                throw new InvalidConfigurationException(
                    "Service '{$serviceId}' for route binding '{$param}' does not implement RouteModelBinderInterface.",
                );
            }
            $request = $request->withAttribute($param, $binder->resolve($rawValue, $request));
        }

        return $request;
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
