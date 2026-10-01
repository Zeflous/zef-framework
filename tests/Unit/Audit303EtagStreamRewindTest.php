<?php

declare(strict_types=1);

/*
 * Audit #303 regression: ETagMiddleware consumes the response body to hash
 * it and leaves the shared stream's cursor at EOF; the SAPI emitter then
 * echoed zero octets for a fresh 200 GET. The middleware now restores the
 * cursor after reading, and the emitter rewinds a seekable body BEFORE
 * header reconciliation (so an exact Content-Length survives too).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;
use Zef\Framework\ResponseEmitter;

/**
 * @internal
 */
final class Audit303EtagStreamRewindTest extends TestCase
{
    public function testMiddlewareRestoresStreamCursorAfterHashing(): void
    {
        $middleware = new ETagMiddleware();
        $response = $this->runThroughMiddleware($middleware, 'hello', 'GET', 200);

        $body = $response->getBody();
        self::assertTrue($body->isSeekable());
        self::assertSame(0, $body->tell(), 'the cursor must be restored to the start after (string) hashing');
        self::assertSame('hello', (string) $body, 'the full representation is still readable downstream');
        self::assertSame($this->etagOf('hello'), $response->getHeaderLine('ETag'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testEmitterEchoesFullBodyAfterETagMiddleware(): void
    {
        $middleware = new ETagMiddleware();
        $response = $this->runThroughMiddleware($middleware, 'hello', 'GET', 200);

        \ob_start();

        try {
            new ResponseEmitter()->emit($this->asForeign($response));
        } finally {
            $echoed = \ob_get_clean();
        }

        self::assertSame('hello', $echoed, 'the SAPI path must still ship the full 5 octets');
    }

    public function testEmitterRestoresDrainedStreamBeforeHeaders(): void
    {
        $body = Stream::fromString('hello');
        self::assertSame(0, $body->tell(), 'precondition: a fresh stream starts at 0');
        $consumed = (string) $body; // simulate an inspecting middleware
        self::assertSame('hello', $consumed);
        self::assertTrue($body->eof(), 'precondition: (string) left the cursor at EOF');

        \ob_start();

        try {
            new ResponseEmitter()->emit(new Response(200, ['Content-Length' => '5'], $body));
        } finally {
            $echoed = \ob_get_clean();
        }

        self::assertSame('hello', $echoed, 'a drained seekable stream is rewound and re-emitted');
    }

    public function testNotModifiedShortCircuitStillBodies304(): void
    {
        $middleware = new ETagMiddleware();
        $etag = $this->etagOf('hello');
        $response = $this->runThroughMiddleware($middleware, 'hello', 'GET', 200, $etag);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody(), 'a 304 carries no content');
        self::assertSame($etag, $response->getHeaderLine('ETag'));
    }

    private function etagOf(string $body): string
    {
        return '"' . bin2hex(hash('sha256', $body, true)) . '"';
    }

    private function runThroughMiddleware(
        ETagMiddleware $middleware,
        string $body,
        string $method,
        int $status,
        ?string $ifNoneMatch = null,
    ): ResponseInterface {
        $request = new ServerRequest($method, new Uri('/etag'));
        if ($ifNoneMatch !== null) {
            $withHeader = $request->withHeader('If-None-Match', $ifNoneMatch);
            self::assertInstanceOf(ServerRequestInterface::class, $withHeader);
            $request = $withHeader;
        }
        $handler = new class($body, $status) implements RequestHandlerInterface {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status, [], Stream::fromString($this->body));
            }
        };

        $response = $middleware->process($request, $handler);
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /** Wraps in a plain PSR-7 response so the emitter's foreign path is exercised. */
    private function asForeign(ResponseInterface $response): ResponseInterface
    {
        return new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $response->getBody(),
        );
    }
}
