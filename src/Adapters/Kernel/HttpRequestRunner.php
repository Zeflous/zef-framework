<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: HTTP request execution.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; no public API change).
 */

namespace Zef\Framework\Kernel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Application;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\Observability\Telemetry;

/**
 * @internal
 *
 * Per-request execution engine of {@see Application}: ingress validation,
 * the telemetry span lifecycle, middleware-pipeline dispatch and the
 * per-request scope/flush teardown. Extracted verbatim from
 * Application::handle()/handleGlobals(); the shutdown/lazy-boot guards
 * stay on Application and are re-entered through the callback handed to
 * {@see runFromGlobals()} so the historical execution order (globals are
 * read before any boot decision) is preserved exactly.
 */
final readonly class HttpRequestRunner
{
    public function __construct(
        private Container $container,
        private ?MiddlewarePipeline $pipeline,
        private RequestBodyPolicy $bodyPolicy,
        private bool $debug = false,
    ) {}

    /**
     * Validate and run one server request through the middleware pipeline.
     *
     * @param list<string> $trustedHosts
     * @param list<string> $trustedProxies
     */
    public function run(ServerRequestInterface $request, array $trustedHosts, array $trustedProxies): ResponseInterface
    {
        try {
            $request = RequestFactory::validateIngress(
                $request,
                $trustedHosts,
                $trustedProxies,
                $this->bodyPolicy,
            );
        } catch (PayloadTooLargeException) {
            return JsonResponse::error(413, 'Content Too Large');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error(400, 'Bad Request', [
                'message' => $this->debug ? $e->getMessage() : 'Invalid request.',
            ]);
        }
        $scope = $this->container->createRequestScope();

        /** @var Telemetry $telemetry */
        $telemetry = $this->container->get(Telemetry::class);
        $parent = $telemetry->extract(
            $request->getHeaderLine('traceparent'),
            $request->getHeaderLine('tracestate'),
        );
        $span = $telemetry->startSpan(
            'zef.http.request',
            [
                'http.request.method' => $request->getMethod(),
                'url.path' => $request->getUri()->getPath(),
                'server.address' => $request->getUri()->getHost(),
            ],
            $parent,
        );
        $request = $request
            ->withAttribute('__zef_request_scope', $scope)
            ->withAttribute('__zef_telemetry_span', $span)
            ->withAttribute('__zef_trusted_proxies', $trustedProxies)
        ;
        $traceId = $span->getContext()->isValid() ? $span->getContext()->traceId : '';
        $startNs = hrtime(true);
        $this->recordLifecycle($telemetry, 'request.started', $traceId);

        try {
            $response = $this->pipeline?->handle($request)
                ?? new Response(500, ['Content-Type' => 'text/plain'], 'Application pipeline unavailable.');
            if (strtoupper($request->getMethod()) === 'HEAD') {
                // ZEF-DEEP-05: the body is now empty, so the handler's
                // GET-representation Content-Length no longer matches the
                // response object. Drop it to keep the object internally
                // consistent — the SAPI emitter has always reconciled this
                // lying header away; the RoadRunner path forwards verbatim
                // and would otherwise ship a stale framing header.
                $response = $this->headView($response);
            }
            $elapsed = (hrtime(true) - $startNs) / 1_000_000_000;
            $span
                ->setAttribute('http.response.status_code', $response->getStatusCode())
                ->setAttribute('zef.request.duration_seconds', $elapsed)
                ->setStatus($response->getStatusCode() >= 500 ? 'ERROR' : 'OK')
            ;
            $telemetry->meter()->increment(
                'zef.http.requests.total',
                1,
                [
                    'http.request.method' => $request->getMethod(),
                    'http.response.status_code' => $response->getStatusCode(),
                ],
            );
            $telemetry->meter()->observe(
                'zef.http.request.duration_seconds',
                $elapsed,
                ['http.request.method' => $request->getMethod()],
            );
            $this->recordLifecycle($telemetry, 'request.completed', $traceId);

            return $telemetry->isEnabled()
                ? $this->withTraceparent($response, $span->getContext()->traceParent())
                : $response;
        } catch (\Throwable $e) {
            $span->setStatus('ERROR', $e::class);
            $span->addEvent('exception', [
                'exception.type' => $e::class,
                'exception.message' => $e->getMessage(),
            ]);
            $telemetry->meter()->increment(
                'zef.http.errors.total',
                1,
                [
                    'http.request.method' => $request->getMethod(),
                    'exception.type' => $e::class,
                ],
            );
            $this->recordLifecycle($telemetry, 'request.failed', $traceId);

            throw $e;
        } finally {
            $span->end();
            // Issue #55 step 3: the flush-per-request knob reads through the
            // container-bound EnvInterface port (singleton — the per-request
            // get() is a plan lookup, not a construction).
            $envPort = $this->container->get(EnvInterface::class);
            if ($envPort instanceof EnvInterface && $envPort->readBool('ZEF_OTEL_FLUSH_PER_REQUEST')) {
                $telemetry->flush();
            }
            $scope->close();
        }
    }

    /**
     * Build a request from the SAPI superglobals and hand it to $handle
     * ({@see Application::handle()}, which owns the shutdown and lazy-boot
     * guards). Ingress failures from the globals read itself are mapped to
     * the same 413/400 responses as {@see run()}.
     *
     * @param list<string>                            $trustedHosts
     * @param list<string>                            $trustedProxies
     * @param \Closure(ServerRequestInterface): ResponseInterface $handle
     */
    public function runFromGlobals(array $trustedHosts, array $trustedProxies, \Closure $handle): ResponseInterface
    {
        try {
            return $handle(
                RequestFactory::fromGlobals($trustedHosts, $trustedProxies, $this->bodyPolicy),
            );
        } catch (PayloadTooLargeException) {
            return JsonResponse::error(413, 'Content Too Large');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error(400, 'Bad Request', [
                'message' => $this->debug ? $e->getMessage() : 'Invalid request.',
            ]);
        }
    }

    private function recordLifecycle(Telemetry $telemetry, string $event, string $traceId = ''): void
    {
        $attributes = ['event.name' => $event];
        if ($traceId !== '') {
            $attributes['trace_id'] = $traceId;
        }
        $telemetry->recordLog('INFO', $event, $attributes);
        $telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => $event]);
    }

    /**
     * HEAD view of a response: same object semantics as the historical
     * withBody()+withoutHeader() pair. The explicit instanceof hops keep the
     * static analyser's view pinned to ResponseInterface (the PSR-7 interface
     * signatures declare the with*() fluent returns as MessageInterface;
     * every concrete implementation — including this framework's — returns
     * `static`). Same narrowing pattern as RuntimeResponder::withoutContentLength().
     */
    private function headView(ResponseInterface $response): ResponseInterface
    {
        $bodyless = $response->withBody(Stream::fromString(''));
        if (!$bodyless instanceof ResponseInterface) {
            throw new \LogicException('withBody must preserve the response type.');
        }

        $headerless = $bodyless->withoutHeader('Content-Length');
        if (!$headerless instanceof ResponseInterface) {
            throw new \LogicException('withoutHeader must preserve the response type.');
        }

        return $headerless;
    }

    /** Same rationale as {@see headView()}: one withHeader() hop. */
    private function withTraceparent(ResponseInterface $response, string $traceparent): ResponseInterface
    {
        $decorated = $response->withHeader('traceparent', $traceparent);
        if (!$decorated instanceof ResponseInterface) {
            throw new \LogicException('withHeader must preserve the response type.');
        }

        return $decorated;
    }
}
