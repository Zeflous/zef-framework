<?php

declare(strict_types=1);

/*
 * ZEF Framework — Native PHPUnit coverage for the HTTP adapter stack:
 * UploadedFile, RequestFactory, Uri, Stream, LimitedInputStream,
 * TrustedProxyMatcher, ApiVersion negotiation, ProblemDetails, FormRequest,
 * JsonResponse, ETag/CORS/GlobalError middleware and error responses.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Zef\Framework\Exception\ApiVersionUnsupportedException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\ApiVersion;
use Zef\Framework\Http\ApiVersionNegotiator;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\FormRequest;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\ProblemDetails;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\TrustedProxyMatcher;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;
use Zef\Framework\Validation\Validator;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\ErrorResponseFactory;
use Zef\Middleware\GlobalErrorHandler;

/**
 * @internal
 */
final class HttpTest extends TestCase
{
    // ------------------------------------------------------------------
    // UploadedFile
    // ------------------------------------------------------------------

    public function testUploadedFileMoveToAndMetadata(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zef');
        file_put_contents((string) $tmp, 'payload-bytes');
        $file = new UploadedFile(Stream::fromString('payload-bytes'), 13, \UPLOAD_ERR_OK, 'notes.txt', 'text/plain');

        self::assertSame(13, $file->getSize());
        self::assertSame(\UPLOAD_ERR_OK, $file->getError());
        self::assertSame('notes.txt', $file->getClientFilename());
        self::assertSame('text/plain', $file->getClientMediaType());
        self::assertSame('payload-bytes', (string) $file->getStream());

        $target = $tmp . '-moved';
        $file->moveTo($target);
        self::assertFileExists($target);
        self::assertSame('payload-bytes', file_get_contents($target));
        @unlink($target); // nosemgrep: php.lang.security.unlink-use
        @unlink((string) $tmp); // nosemgrep: php.lang.security.unlink-use
    }

    public function testUploadedFileRejectsNegativeSizeAndBadStream(): void
    {
        try {
            new UploadedFile(Stream::fromString('x'), -1);
            self::fail('negative size must throw');
        } catch (\InvalidArgumentException) {
        }

        $errorFile = new UploadedFile(Stream::fromString(''), null, \UPLOAD_ERR_INI_SIZE);
        self::assertSame(\UPLOAD_ERR_INI_SIZE, $errorFile->getError());
        $this->expectException(\RuntimeException::class);
        $errorFile->getStream();
    }

    // ------------------------------------------------------------------
    // RequestFactory
    // ------------------------------------------------------------------

    public function testFromServerBuildsRequestFromSuperglobalShape(): void
    {
        $server = [
            'REQUEST_METHOD' => 'post',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_URI' => '/toko/produk/2?ref=x',
            'QUERY_STRING' => 'ref=x',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_REQUEST_ID' => 'corr-1',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '5',
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTPS' => 'on',
        ];
        $request = RequestFactory::fromServer($server, ['localhost'], ['10.0.0.0/8'], null, ['ref' => 'x'], [], [], []);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/toko/produk/2', $request->getUri()->getPath());
        self::assertSame('ref=x', $request->getUri()->getQuery());
        self::assertSame('corr-1', $request->getHeaderLine('X-Request-ID'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['ref' => 'x'], $request->getQueryParams());
        self::assertSame('10.0.0.5', $request->getAttribute('client_ip') ?? $request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    public function testDecodeJsonBodyValidAndInvalid(): void
    {
        $request = new ServerRequest('POST', new Uri('http://localhost/api'))
            ->withBody(Stream::fromString('{"a":1}'))
            ->withHeader('Content-Type', 'application/json')
        ;
        self::assertSame(['a' => 1], RequestFactory::decodeJsonBody($request));

        $bad = new ServerRequest('POST', new Uri('http://localhost/api'))
            ->withBody(Stream::fromString('{nope}'))
            ->withHeader('Content-Type', 'application/json')
        ;
        $this->expectException(\InvalidArgumentException::class);
        RequestFactory::decodeJsonBody($bad);
    }

    // ------------------------------------------------------------------
    // Uri
    // ------------------------------------------------------------------

    public function testUriParsesAndRebuildsEveryComponent(): void
    {
        $uri = new Uri('https://user:pass@shop.example.co.id:8443/catalog/p/3?color=red#reviews');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('shop.example.co.id', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/catalog/p/3', $uri->getPath());
        self::assertSame('color=red', $uri->getQuery());
        self::assertSame('reviews', $uri->getFragment());
        self::assertSame('user:pass@shop.example.co.id:8443', $uri->getAuthority());
        self::assertSame('https://user:pass@shop.example.co.id:8443/catalog/p/3?color=red#reviews', (string) $uri);

        $mutated = $uri->withScheme('http')->withUserInfo('u')->withHost('other.example')->withPort(null)->withPath('/x')->withQuery('a=1')->withFragment('f');
        self::assertSame('http://u@other.example/x?a=1#f', (string) $mutated);
        self::assertSame('https://user:pass@shop.example.co.id:8443/catalog/p/3?color=red#reviews', (string) $uri);
    }

    public function testUriDefaultPortsAreOmittedAndTrustedHostsEnforced(): void
    {
        // Regresi N-7 (issue #176): PSR-7 "SHOULD omit" — an explicit
        // scheme-default port is indistinguishable from no port, so
        // getPort() reports null and the authority/serialisation drop it
        // (aligned with RequestFactory's own default-port handling).
        $http = new Uri('http://localhost:80/');
        self::assertNull($http->getPort(), 'explicit default port http:80 is omitted');
        self::assertSame('localhost', $http->getAuthority());
        self::assertSame('http://localhost/', (string) $http);
        $implicit = new Uri('http://localhost/');
        self::assertNull($implicit->getPort());
        $https = new Uri('https://localhost:443/');
        self::assertNull($https->getPort(), 'explicit default port https:443 is omitted');
        self::assertSame('https://localhost/', (string) $https);
        // The stored port re-exposes itself once it stops being the
        // scheme default: 80 is non-default under https.
        $flipped = $http->withScheme('https');
        self::assertSame(80, $flipped->getPort());
        self::assertSame('https://localhost:80/', (string) $flipped);

        $ok = new Uri('http://localhost/', ['localhost']);
        self::assertSame('localhost', $ok->getHost());

        $this->expectException(\InvalidArgumentException::class);
        new Uri('http://evil.example/', ['localhost']);
    }

    // ------------------------------------------------------------------
    // Stream + LimitedInputStream
    // ------------------------------------------------------------------

    public function testStreamReadWriteSeekAndMetadata(): void
    {
        $resource = fopen('php://memory', 'w+b');
        $stream = new Stream($resource);

        self::assertSame(6, $stream->write('hello!'));
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isSeekable());
        $stream->rewind();
        self::assertSame('hello!', $stream->getContents());
        self::assertTrue($stream->eof());
        $stream->seek(0);
        self::assertSame(0, $stream->tell());
        self::assertSame('hel', $stream->read(3));
        self::assertSame(6, $stream->getSize());
        self::assertTrue((bool) $stream->getMetadata('seekable'));
        $stream->rewind();
        self::assertSame('hello!', (string) $stream);
        $stream->close();

        $detached = new Stream(fopen('php://memory', 'w+b'));
        self::assertIsResource($detached->detach());
    }

    public function testLimitedInputStreamEnforcesByteBudget(): void
    {
        $policy = new RequestBodyPolicy(8);
        $limited = new LimitedInputStream(Stream::fromString(str_repeat('x', 100)), $policy);

        try {
            $limited->getContents();
            self::fail('oversized body must raise PayloadTooLargeException');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('', (string) $limited, 'string cast degrades to empty instead of throwing');
        self::assertFalse($limited->isWritable());

        $fits = new LimitedInputStream(Stream::fromString('small'), $policy);
        self::assertSame('small', (string) $fits);
    }

    // ------------------------------------------------------------------
    // TrustedProxyMatcher
    // ------------------------------------------------------------------

    public function testTrustedProxyMatcherCidrSemantics(): void
    {
        self::assertTrue(TrustedProxyMatcher::ipInCidr('10.1.2.3', '10.0.0.0', 8));
        self::assertFalse(TrustedProxyMatcher::ipInCidr('11.1.2.3', '10.0.0.0', 8));
        self::assertTrue(TrustedProxyMatcher::ipInCidr('192.168.0.7', '192.168.0.0', 24));
        self::assertTrue(TrustedProxyMatcher::matches('10.0.0.1', ['10.0.0.0/8', '192.168.1.1']));
        self::assertTrue(TrustedProxyMatcher::matches('192.168.1.1', ['192.168.1.1']));
        self::assertFalse(TrustedProxyMatcher::matches('8.8.8.8', ['10.0.0.0/8']));
    }

    // ------------------------------------------------------------------
    // ApiVersion negotiation
    // ------------------------------------------------------------------

    public function testApiVersionNegotiatorPrefersPathThenHeaderThenQuery(): void
    {
        $negotiator = new ApiVersionNegotiator(['1', '2'], '1');

        $viaPath = $negotiator->negotiate('/v2/users');
        self::assertSame('2', $viaPath->version);
        self::assertSame(ApiVersion::SOURCE_PATH, $viaPath->source);
        self::assertSame(['2', '/users'], $negotiator->splitPathPrefix('/v2/users'));

        $viaHeader = $negotiator->negotiate('/users', '2');
        self::assertSame('2', $viaHeader->version);
        self::assertSame(ApiVersion::SOURCE_HEADER, $viaHeader->source);

        $viaQuery = $negotiator->negotiate('/users', null, '2');
        self::assertSame('2', $viaQuery->version);

        $default = $negotiator->negotiate('/users');
        self::assertSame('1', $default->version);
        self::assertSame(ApiVersion::SOURCE_DEFAULT, $default->source);
    }

    public function testApiVersionNegotiatorGuards(): void
    {
        try {
            new ApiVersionNegotiator([]);
            self::fail('empty supported list must throw');
        } catch (\InvalidArgumentException) {
        }

        try {
            new ApiVersion('1', 'carrier-pigeon');
            self::fail('unknown source must throw');
        } catch (\InvalidArgumentException) {
        }

        $negotiator = new ApiVersionNegotiator(['1']);
        $this->expectException(ApiVersionUnsupportedException::class);
        $negotiator->negotiate('/users', '9');
    }

    // ------------------------------------------------------------------
    // ProblemDetails + JsonResponse
    // ------------------------------------------------------------------

    public function testProblemDetailsRendersRfc7807Document(): void
    {
        $problem = ProblemDetails::fromStatus(404, 'No such product', '/toko/produk/99', ['product_id' => 99]);

        self::assertSame(404, $problem->status);
        self::assertNotSame('Unknown', $problem->title);
        $array = $problem->toArray();
        self::assertSame('about:blank', $array['type']);
        self::assertSame('/toko/produk/99', $array['instance']);
        self::assertSame(99, $array['product_id']);
        self::assertJsonStringEqualsJsonString((string) json_encode($array), $problem->toJson());

        $response = $problem->toResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $this->expectException(\InvalidArgumentException::class);
        new ProblemDetails(404, 'ok', 'about:blank', '', null, ['bad name!' => 1]);
    }

    public function testJsonResponseErrorShape(): void
    {
        $response = JsonResponse::error(403, 'forbidden', ['detail' => 'nope'], ['X-Trace' => 't1']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('t1', $response->getHeaderLine('X-Trace'));
        $body = json_decode($response->bodyString(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('forbidden', $body['error']);
        self::assertSame(403, $body['status']);
        self::assertSame('nope', $body['detail']);
    }

    // ------------------------------------------------------------------
    // ETag / CORS / GlobalErrorHandler
    // ------------------------------------------------------------------

    public function testEtagMiddlewareServes304OnMatchingIfNoneMatch(): void
    {
        $middleware = new ETagMiddleware();
        $etag = null;

        $first = $middleware->process($this->request(), $this->handler(static fn (): Response => new Response(200, [], 'stable-body')));
        self::assertSame(200, $first->getStatusCode());
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        $conditional = $this->request()->withHeader('If-None-Match', $etag);
        $second = $middleware->process($conditional, $this->handler(static fn (): Response => new Response(200, [], 'stable-body')));
        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', (string) $second->getBody());

        // POST requests are never tagged.
        $post = $middleware->process($this->request()->withMethod('POST'), $this->handler(static fn (): Response => new Response(200, [], 'x')));
        self::assertStringNotContainsString('ETag', implode(',', array_keys($post->getHeaders())));
    }

    public function testCorsMiddlewareHandlesPreflightAndSimpleRequests(): void
    {
        $middleware = new CorsMiddleware('https://app.example');

        // Audit #328: a preflight is an OPTIONS that carries the
        // Access-Control-Request-Method marker (fetch spec) — answered 204.
        $preflight = $middleware->process(
            new ServerRequest('OPTIONS', new Uri('/'), headers: [
                'Origin' => 'https://app.example',
                'Access-Control-Request-Method' => 'POST',
            ]),
            $this->handler(static fn (): Response => new Response(200)),
        );
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('https://app.example', $preflight->getHeaderLine('Access-Control-Allow-Origin'));

        // A plain OPTIONS without the marker is NOT a preflight: it must
        // reach the handler (decorated with CORS headers for the origin).
        $plainOptions = $middleware->process(
            new ServerRequest('OPTIONS', new Uri('/'), headers: ['Origin' => 'https://app.example']),
            $this->handler(static fn (): Response => new Response(200, [], 'options-body')),
        );
        self::assertSame(200, $plainOptions->getStatusCode(), 'plain OPTIONS falls through to the handler');
        self::assertSame('options-body', (string) $plainOptions->getBody());
        self::assertSame('https://app.example', $plainOptions->getHeaderLine('Access-Control-Allow-Origin'));

        $simple = $middleware->process(
            new ServerRequest('GET', new Uri('/'), headers: ['Origin' => 'https://app.example']),
            $this->handler(static fn (): Response => new Response(200, [], 'ok')),
        );
        self::assertSame(200, $simple->getStatusCode());
        self::assertSame('https://app.example', $simple->getHeaderLine('Access-Control-Allow-Origin'));

        // allowAll must not echo '*' when the request carries no Origin at all.
        $allowAll = new CorsMiddleware('*');
        $noOrigin = $allowAll->process(
            $this->request(),
            $this->handler(static fn (): Response => new Response(200, [], 'ok')),
        );
        self::assertSame(200, $noOrigin->getStatusCode());
        self::assertSame('', $noOrigin->getHeaderLine('Access-Control-Allow-Origin'), 'no Origin header means no CORS echo');
    }

    public function testGlobalErrorHandlerConvertsThrowablesIntoProblemResponses(): void
    {
        $factory = new ErrorResponseFactory(true);
        $middleware = new GlobalErrorHandler(new NullLogger(), $factory);

        $boom = $middleware->process($this->request(), $this->handler(static function (): never {
            throw new \RuntimeException('exploded');
        }));
        self::assertSame(500, $boom->getStatusCode());

        $ok = $middleware->process($this->request(), $this->handler(static fn (): Response => new Response(200, [], 'fine')));
        self::assertSame(200, $ok->getStatusCode());
        self::assertTrue($factory->isDebug());
        self::assertSame(200, $factory->create(200, 'all good', 'corr-1')->getStatusCode());
    }

    // ------------------------------------------------------------------
    // FormRequest + Validator
    // ------------------------------------------------------------------

    public function testFormRequestValidationSuccessAndFailure(): void
    {
        $validator = new Validator();
        $validator->field('email')->required()->email();
        $validator->field('qty')->required()->typeInt()->min(1);

        $valid = FormRequest::fromArray($validator, ['email' => 'a@b.co', 'qty' => 2]);
        self::assertTrue($valid->isValid());
        self::assertSame(['email' => 'a@b.co', 'qty' => 2], $valid->validated());
        self::assertSame([], $valid->errors());

        $invalid = FormRequest::fromArray($validator, ['email' => 'nope', 'qty' => 0]);
        self::assertFalse($invalid->isValid());
        self::assertCount(2, $invalid->errors());
        self::assertIsArray($invalid->errors()[0]);

        $payloadRequest = new ServerRequest('POST', new Uri('http://localhost/api'))
            ->withParsedBody(['email' => 'x@y.z', 'qty' => 1])
        ;
        $fromHttp = FormRequest::fromServerRequest($validator, $payloadRequest);
        self::assertTrue($fromHttp->isValid(), 'parsed body payload validates');
    }

    private function handler(callable $fn): RequestHandlerInterface
    {
        return new class($fn) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $fn */
            public function __construct(private $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
    }

    private function request(): ServerRequest
    {
        return new ServerRequest('GET', new Uri('http://localhost/toko', ['localhost']));
    }
}
