<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use Nyholm\Psr7\ServerRequest as WorkerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;

/** @internal */
final class ApplicationIngressTest extends TestCase
{
    #[DataProvider('rejectedHosts')]
    public function testRejectsUntrustedOrMissingHostBeforeDispatch(string $uri, ?string $host): void
    {
        $request = new WorkerRequest('POST', $uri, $host === null ? [] : ['Host' => $host], 'ok');
        $response = $this->application()->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Dispatched'));
        self::assertStringNotContainsString('untrusted.invalid', (string) $response->getBody());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rejectedHosts(): iterable
    {
        yield 'untrusted URI and host' => ['http://untrusted.invalid/echo', null];

        yield 'untrusted header' => ['http://example.com/echo', 'untrusted.invalid'];

        yield 'untrusted URI' => ['http://untrusted.invalid/echo', 'example.com'];

        yield 'missing authority' => ['/echo', null];

        yield 'empty host header' => ['http://example.com/echo', ''];

        yield 'malformed authority' => ['http://example.com/echo', 'example.com@untrusted.invalid'];
    }

    #[DataProvider('acceptedHosts')]
    public function testAcceptsTrustedHosts(string $uri, string $host, string $allowed): void
    {
        $response = $this->application([$allowed])->handle(new WorkerRequest('POST', $uri, ['Host' => $host], 'ok'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function acceptedHosts(): iterable
    {
        yield 'case and port' => ['http://EXAMPLE.com:8080/echo', 'EXAMPLE.com:8080', 'example.com'];

        yield 'IPv6' => ['http://[::1]:8080/echo', '[::1]:8080', '::1'];
    }

    public function testEmptyAllowlistStillAcceptsRelativeRequests(): void
    {
        self::assertSame(200, $this->application([])->handle(new WorkerRequest('POST', '/echo'))->getStatusCode());
    }

    #[DataProvider('bodyCases')]
    public function testChecksDeclaredAndActualBodySizes(string $body, ?string $length, int $status): void
    {
        $request = new WorkerRequest('POST', 'http://example.com/echo', $length === null ? [] : ['Content-Length' => $length], $body);
        $response = $this->application()->handle($request);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($status === 200, $response->hasHeader('X-Dispatched'));
        if ($status === 200) {
            self::assertSame($body, (string) $response->getBody());
        }
    }

    /** @return iterable<string, array{string, ?string, int}> */
    public static function bodyCases(): iterable
    {
        yield 'declared too large' => ['', '5', 413];

        yield 'oversized without length' => ['12345', null, 413];

        yield 'understated length' => ['12345', '1', 413];

        yield 'oversized decimal' => ['', '9999999999999999999999999999', 413];

        yield 'exact limit' => ['1234', '4', 200];

        yield 'empty' => ['', null, 200];
    }

    public function testChecksWholeSeekableBodyAndPreservesCursor(): void
    {
        $app = $this->application();
        $request = new WorkerRequest('POST', 'http://example.com/echo', [], '1234');
        $request->getBody()->seek(2);
        self::assertSame(200, $app->handle($request)->getStatusCode());
        self::assertSame(2, $request->getBody()->tell());

        $large = new WorkerRequest('POST', 'http://example.com/echo', [], '12345');
        $large->getBody()->seek(5);
        self::assertSame(413, $app->handle($large)->getStatusCode());
        self::assertSame(5, $large->getBody()->tell());
    }

    #[DataProvider('unknownBodies')]
    public function testBoundsUnknownSizeStreams(string $content, int $status, bool $seekable): void
    {
        $inner = Stream::fromString($content);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn($seekable);
        $stream->method('tell')->willReturnCallback($inner->tell(...));
        $stream->method('rewind')->willReturnCallback($inner->rewind(...));
        $stream->method('seek')->willReturnCallback($inner->seek(...));
        $stream->method('__toString')->willReturnCallback($inner->__toString(...));
        $stream->method('eof')->willReturnCallback($inner->eof(...));
        $stream->method('read')->willReturnCallback($inner->read(...));
        if ($seekable) {
            $inner->seek(2);
        }
        $request = new WorkerRequest('POST', 'http://example.com/echo', [], $stream);
        $response = $this->application()->handle($request);

        self::assertSame($status, $response->getStatusCode());
        self::assertLessThanOrEqual(5, $inner->tell());
        if ($seekable) {
            self::assertSame(2, $inner->tell());
        }
        if ($status === 200) {
            self::assertSame($content, (string) $response->getBody());
        } else {
            self::assertFalse($response->hasHeader('X-Dispatched'));
        }
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function unknownBodies(): iterable
    {
        yield 'non-seekable at limit' => ['1234', 200, false];

        yield 'non-seekable over limit' => [str_repeat('x', 100), 413, false];

        yield 'seekable at limit' => ['1234', 200, true];

        yield 'seekable over limit' => [str_repeat('x', 100), 413, true];
    }

    public function testNativeTrustedProxyRequestRetainsItsEffectiveHost(): void
    {
        $app = $this->application();
        $app->setTrustedProxies(['10.0.0.1']);
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/echo',
            'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => 'internal.local',
            'HTTP_X_FORWARDED_HOST' => 'example.com',
        ], ['example.com'], ['10.0.0.1']);

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    public function testUntrustedPeerCannotUseForwardedHostToBypassAllowlist(): void
    {
        $request = new WorkerRequest('POST', 'http://example.com/echo', [
            'Host' => 'untrusted.invalid', 'X-Forwarded-Host' => 'example.com',
        ], '', '1.1', ['REMOTE_ADDR' => '192.0.2.1']);
        $app = $this->application();
        $app->setTrustedProxies(['10.0.0.1']);

        self::assertSame(400, $app->handle($request)->getStatusCode());
    }

    public function testRoadRunnerReturnsClientErrorsAndContinuesServing(): void
    {
        $worker = new InMemoryWorker([
            new WorkerRequest('POST', 'http://untrusted.invalid/echo'),
            new WorkerRequest('POST', 'http://example.com/echo', [], '12345'),
            new WorkerRequest('POST', 'http://example.com/echo', [], '1234'),
        ]);
        $runtime = new RoadRunnerRuntime($this->application(), $worker, installSignalHandlers: false);

        self::assertSame(0, $runtime->run());
        self::assertSame([400, 413, 200], array_map(static fn (ResponseInterface $r): int => $r->getStatusCode(), $worker->responses()));
        self::assertSame('1234', (string) $worker->responses()[2]->getBody());
    }

    /** @param list<string> $hosts */
    private function application(array $hosts = ['example.com']): Application
    {
        $app = new Application(bodyPolicy: new RequestBodyPolicy(4));
        $app->setTrustedHosts($hosts);
        $app->getContainer()->register('ingress.echo', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $body = $request->getBody();
                $position = $body->tell();
                $body->rewind();
                $content = (string) $body;
                $body->seek($position);

                return new Response(200, ['X-Dispatched' => 'yes'], $content);
            }
        });
        $app->getRouter()->add('POST', '/echo', 'ingress.echo');

        return $app;
    }
}
