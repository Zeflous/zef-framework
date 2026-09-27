<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-05 (issue #159):
 * bodyless responses must not carry a stale Content-Length.
 *
 * Pre-fix, an ETagMiddleware 304 (and the Application HEAD body-strip)
 * kept the 200's Content-Length while emptying the body. The SAPI emitter
 * reconciles lying framing headers, but RoadRunnerRuntime forwarded them
 * VERBATIM — a client could hang waiting for octets that never arrive.
 */

namespace Zef\Test\Unit;

use Nyholm\Psr7\ServerRequest as WorkerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;

/**
 * @internal
 */
final class ContentLengthReconciliationTest extends TestCase
{
    // ------------------------------------------------------------------
    // ETagMiddleware 304 (the audit PoC)
    // ------------------------------------------------------------------

    public function testNotModifiedDropsTheStaleContentLength(): void
    {
        $middleware = new ETagMiddleware();
        $body = 'Hello World'; // 11 octets
        $etag = $middleware->process($this->get('/payload'), $this->handler(new Response(200, ['Content-Length' => '11'], $body)))->getHeaderLine('ETag');
        self::assertNotSame('', $etag, 'fixture: the fresh response carries an ETag');

        $notModified = $middleware->process(
            $this->get('/payload', ['If-None-Match' => $etag]),
            $this->handler(new Response(200, ['Content-Length' => '11'], $body)),
        );

        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('', (string) $notModified->getBody());
        self::assertFalse($notModified->hasHeader('Content-Length'), 'the 200\'s Content-Length must not describe a payload that never arrives');
        self::assertSame($etag, $notModified->getHeaderLine('ETag'), 'the validator survives');
    }

    // ------------------------------------------------------------------
    // Application HEAD body-strip
    // ------------------------------------------------------------------

    public function testHeadStripsBodyAndStaleContentLength(): void
    {
        $app = $this->application(static fn (): ResponseInterface => new Response(200, ['Content-Length' => '11'], 'Hello World'));

        $response = $app->handle(new WorkerRequest('HEAD', 'http://example.com/echo'));

        self::assertSame('', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Length'), 'an emptied body must not keep the GET-representation length');
    }

    // ------------------------------------------------------------------
    // RoadRunnerRuntime verbatim path — the centralized safety net
    // ------------------------------------------------------------------

    public function testRoadRunnerDropsLyingContentLength(): void
    {
        $app = $this->application(static fn (): ResponseInterface => new Response(200, ['Content-Length' => '999'], 'short'));
        $worker = new InMemoryWorker([new WorkerRequest('POST', 'http://example.com/echo', [], '1234')]);
        $runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);

        self::assertSame(0, $runtime->run());
        $responded = $worker->responses()[0];
        self::assertSame(200, $responded->getStatusCode());
        self::assertFalse($responded->hasHeader('Content-Length'), 'declared 999 vs 5 actual octets must be reconciled away');
    }

    public function testRoadRunnerDropsContentLengthOnBodylessStatuses(): void
    {
        $app = $this->application(static fn (): ResponseInterface => new Response(304, ['Content-Length' => '11', 'ETag' => '"x"'], ''));
        $worker = new InMemoryWorker([new WorkerRequest('POST', 'http://example.com/echo', [], '1234')]);
        $runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);

        self::assertSame(0, $runtime->run());
        $responded = $worker->responses()[0];
        self::assertSame(304, $responded->getStatusCode());
        self::assertFalse($responded->hasHeader('Content-Length'), '304 never carries a payload — a stale length from the 200 it derives from must go');
        self::assertSame('"x"', $responded->getHeaderLine('ETag'), 'validators survive the reconciliation');
    }

    public function testRoadRunnerKeepsHonestContentLength(): void
    {
        $app = $this->application(static fn (): ResponseInterface => new Response(200, ['Content-Length' => '5'], '12345'));
        $worker = new InMemoryWorker([new WorkerRequest('POST', 'http://example.com/echo', [], '1234')]);
        $runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);

        self::assertSame(0, $runtime->run());
        $responded = $worker->responses()[0];
        self::assertSame('5', $responded->getHeaderLine('Content-Length'), 'a declared length that matches the stream is framing information, not a lie');
        self::assertSame('12345', (string) $responded->getBody());
    }

    public function testRoadRunnerHeadResponsesAreConsistent(): void
    {
        $app = $this->application(static fn (): ResponseInterface => new Response(200, ['Content-Length' => '5'], '12345'));
        $worker = new InMemoryWorker([new WorkerRequest('HEAD', 'http://example.com/echo')]);
        $runtime = new RoadRunnerRuntime($app, $worker, installSignalHandlers: false);

        self::assertSame(0, $runtime->run());
        $responded = $worker->responses()[0];
        self::assertSame('', (string) $responded->getBody());
        self::assertFalse($responded->hasHeader('Content-Length'), 'the Application strip and the runtime net agree: no stale framing header');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param \Closure(): ResponseInterface $responder */
    private function application(\Closure $responder): Application
    {
        $app = new Application(bodyPolicy: new RequestBodyPolicy(64));
        $app->setTrustedHosts(['example.com']);
        $app->getContainer()->register('echo.handler', static fn (): RequestHandlerInterface => new readonly class($responder) implements RequestHandlerInterface {
            /** @param \Closure(): ResponseInterface $responder */
            public function __construct(private \Closure $responder) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->responder)();
            }
        });
        $app->getRouter()->add('POST', '/echo', 'echo.handler');
        $app->getRouter()->add('HEAD', '/echo', 'echo.handler');
        $app->getRouter()->add('GET', '/payload', 'echo.handler');

        return $app;
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']), [], [], [], [], null, $headers);
    }
}
