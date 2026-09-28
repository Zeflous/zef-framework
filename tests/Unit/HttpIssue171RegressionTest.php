<?php

declare(strict_types=1);

/*
 * Regression suite for GitHub issue #171 — [POTENTIAL][ZEF-DEEP-17]
 * HTTP/Async edge cases, HTTP half:
 *
 *   P-11  ApiVersionNegotiator::splitPathPrefix() hijacked every top-level
 *         /v<word> path ("/vendor" parsed as version "endor").
 *   P-12  RequestFactory::fromServer() silently fell back to the
 *         $_GET/$_COOKIE/$_POST/$_FILES superglobals, contaminating
 *         cross-request state in long-running workers.
 *   P-13  ETagMiddleware evaluated If-Modified-Since even when the request
 *         carried If-None-Match (RFC 9110 §13.1.3 precedence), while the
 *         IMS-only path stayed dead for body-carrying responses — an
 *         IMS-only conditional GET always paid the full 200.
 *
 * The async half (P-14, FiberScheduler::timeout() cancellation
 * disambiguation) is already covered by the runtime suites.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Exception\ApiVersionUnsupportedException;
use Zef\Framework\Http\ApiVersion;
use Zef\Framework\Http\ApiVersionNegotiator;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

/**
 * @internal
 */
final class HttpIssue171RegressionTest extends TestCase
{
    // ------------------------------------------------------------------
    // P-11: ApiVersionNegotiator path-prefix anchoring
    // ------------------------------------------------------------------

    public function testVendorStylePathWordsAreNotHijackedAsVersionPrefixes(): void
    {
        // Regresi P-11 (issue #171): the trailing-$ alternative of the old
        // grammar parsed "/vendor" as version "endor" and raised a bogus 406.
        $negotiator = new ApiVersionNegotiator(['1', '2'], default: '1');

        foreach (['/vendor', '/videos', '/v1x/entries'] as $path) {
            [$token, $rest] = $negotiator->splitPathPrefix($path);
            self::assertNull($token, "{$path} must not parse as a version prefix.");
            self::assertSame($path, $rest);
        }

        $version = $negotiator->negotiate('/vendor');
        self::assertSame('1', $version->version);
        self::assertSame(ApiVersion::SOURCE_DEFAULT, $version->source, 'An ordinary route falls through to the default version.');
    }

    public function testNumericVersionPrefixesStillParse(): void
    {
        // Regresi P-11 (issue #171): anchoring to the registry must keep the
        // documented grammar alive — /v1, /v2.3 and /v1/rooms keep parsing.
        $negotiator = new ApiVersionNegotiator(['1', '2.3']);

        [$token, $rest] = $negotiator->splitPathPrefix('/v1');
        self::assertSame('1', $token);
        self::assertSame('/', $rest);

        [$token, $rest] = $negotiator->splitPathPrefix('/v2.3');
        self::assertSame('2.3', $token);
        self::assertSame('/', $rest);

        [$token, $rest] = $negotiator->splitPathPrefix('/v1/rooms');
        self::assertSame('1', $token);
        self::assertSame('/rooms', $rest);

        $version = $negotiator->negotiate('/v1/rooms');
        self::assertSame('1', $version->version);
        self::assertSame(ApiVersion::SOURCE_PATH, $version->source);
    }

    public function testNumericButUnregisteredPrefixStillReportsUnsupported(): void
    {
        // Regresi P-11 (issue #171): anchoring must keep the explicit
        // unsupported-version outcome — /v9 must not silently become the
        // default version.
        $negotiator = new ApiVersionNegotiator(['1', '2'], default: '1');

        try {
            $negotiator->negotiate('/v9/users');
            self::fail('An unregistered numeric version prefix must raise the dedicated exception.');
        } catch (ApiVersionUnsupportedException $e) {
            self::assertSame("API version '9' is not supported. Supported versions: 1, 2.", $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // P-12: RequestFactory superglobal isolation
    // ------------------------------------------------------------------

    public function testFromServerExplicitArgumentsWinOverPlantedSuperglobals(): void
    {
        // Regresi P-12 (issue #171): explicit injection must beat superglobal
        // state planted in the process.
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/issue-171',
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        $this->withPlantedSuperglobals($server, static function () use ($server): void {
            $request = RequestFactory::fromServer(
                $server,
                [],
                [],
                null,
                ['page' => '2'],
                ['sid' => 'session-token'],
                ['field' => 'value'],
                ['f' => ['name' => 'a.txt', 'type' => 'text/plain', 'size' => 3, 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE]],
            );

            self::assertSame(['page' => '2'], $request->getQueryParams());
            self::assertSame(['sid' => 'session-token'], $request->getCookieParams());
            self::assertSame(['field' => 'value'], $request->getParsedBody());

            $upload = $request->getUploadedFiles()['f'] ?? null;
            self::assertInstanceOf(UploadedFile::class, $upload);
            self::assertSame(UPLOAD_ERR_NO_FILE, $upload->getError());
        });
    }

    public function testFromServerWithoutOptionalArgumentsNeverReadsSuperglobals(): void
    {
        // Regresi P-12 (issue #171): the default path sees an empty world
        // regardless of superglobal contents — cross-request state in a
        // long-running worker must not leak in.
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/issue-171',
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        $this->withPlantedSuperglobals($server, static function () use ($server): void {
            $request = RequestFactory::fromServer($server);

            self::assertSame([], $request->getQueryParams());
            self::assertSame([], $request->getCookieParams());
            self::assertSame([], $request->getUploadedFiles());
            self::assertNull($request->getParsedBody());
        });
    }

    public function testFromGlobalsForwardsEveryRequestSuperglobal(): void
    {
        // Regresi P-12 (issue #171): the opt-in variant is the only reader of
        // superglobals and forwards every SAPI source array.
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/issue-171',
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        $this->withPlantedSuperglobals($server, static function (): void {
            $request = RequestFactory::fromGlobals();

            self::assertSame('POST', $request->getMethod());
            self::assertSame('/issue-171', $request->getUri()->getPath());
            self::assertSame(['leak' => 'get'], $request->getQueryParams());
            self::assertSame(['leak' => 'cookie'], $request->getCookieParams());
            self::assertSame(['leak' => 'post'], $request->getParsedBody());

            $upload = $request->getUploadedFiles()['leak'] ?? null;
            self::assertInstanceOf(UploadedFile::class, $upload);
            self::assertSame(UPLOAD_ERR_NO_FILE, $upload->getError());
        });
    }

    // ------------------------------------------------------------------
    // P-13: ETagMiddleware If-None-Match / If-Modified-Since precedence
    // ------------------------------------------------------------------

    public function testIfNoneMatchShadowsIfModifiedSincePerRfc9110(): void
    {
        // Regresi P-13 (issue #171): RFC 9110 §13.1.3 — If-Modified-Since
        // MUST be ignored whenever the request carries If-None-Match, even a
        // non-matching one, so an ETag-less response must stay 200.
        $middleware = new ETagMiddleware();
        $lastModified = 'Mon, 01 Jan 2024 10:00:00 GMT';

        $shadowed = $middleware->process(
            $this->serverRequest([
                'If-None-Match' => '"stale-etag"',
                'If-Modified-Since' => $lastModified,
            ]),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified])),
        );
        self::assertSame(200, $shadowed->getStatusCode(), 'IMS must not 304 while the request carries If-None-Match.');
        self::assertFalse($shadowed->hasHeader('ETag'));

        // The matching direction: a matching If-None-Match wins even when
        // If-Modified-Since disagrees.
        $matching = $middleware->process(
            $this->serverRequest([
                'If-None-Match' => $this->etagFor('body'),
                'If-Modified-Since' => 'Mon, 01 Jan 2024 09:59:59 GMT',
            ]),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified], 'body')),
        );
        self::assertSame(304, $matching->getStatusCode(), 'If-None-Match takes precedence over a disagreeing IMS.');
    }

    public function testIfModifiedSinceOnlyRequestGets304ForBodyCarryingResponses(): void
    {
        // Regresi P-13 (issue #171): the old `$etag === ''` guard kept the
        // IMS path dead for body-carrying responses (an ETag is always
        // computed for a non-empty body), so an IMS-only conditional GET
        // always paid the full 200 — RFC 9110 §13.1.3 wants a 304 whenever
        // Last-Modified is at or before the client's timestamp.
        $middleware = new ETagMiddleware();
        $lastModified = 'Mon, 01 Jan 2024 10:00:00 GMT';

        $notModified = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => $lastModified]),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified], 'body')),
        );
        self::assertSame(304, $notModified->getStatusCode(), 'An IMS-only request must 304 when Last-Modified <= If-Modified-Since.');
        self::assertSame('', (string) $notModified->getBody());
        self::assertSame($this->etagFor('body'), $notModified->getHeaderLine('ETag'), 'The validator a 200 would have sent travels with the 304.');
        self::assertFalse($notModified->hasHeader('Content-Length'), 'the 200 framing must not describe a payload that never arrives');

        // Representation changed after the client's copy → full 200 + ETag.
        $changed = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => 'Mon, 01 Jan 2024 09:59:59 GMT']),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified], 'body')),
        );
        self::assertSame(200, $changed->getStatusCode());
        self::assertSame($this->etagFor('body'), $changed->getHeaderLine('ETag'));

        // No Last-Modified on the response → IMS is unevaluable, ETag still issued.
        $unevaluable = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => $lastModified]),
            $this->handler(new Response(200, [], 'body')),
        );
        self::assertSame(200, $unevaluable->getStatusCode());
        self::assertSame($this->etagFor('body'), $unevaluable->getHeaderLine('ETag'));
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Plants sentinel values in every request superglobal and restores the
     * originals afterwards: PHPUnit runs without process isolation, so the
     * surrounding suite must never observe the planted state.
     *
     * @param array<string,mixed> $server
     * @param callable(): void $scenario
     */
    private function withPlantedSuperglobals(array $server, callable $scenario): void
    {
        $get = $_GET;
        $post = $_POST;
        $cookie = $_COOKIE;
        $files = $_FILES;
        $serverBackup = $_SERVER;

        $_GET = ['leak' => 'get'];
        $_POST = ['leak' => 'post'];
        $_COOKIE = ['leak' => 'cookie'];
        $_FILES = ['leak' => ['name' => 'leak.txt', 'type' => 'text/plain', 'size' => 1, 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE]];
        $_SERVER = $server;

        try {
            $scenario();
        } finally {
            $_GET = $get;
            $_POST = $post;
            $_COOKIE = $cookie;
            $_FILES = $files;
            $_SERVER = $serverBackup;
        }
    }

    private function etagFor(string $body): string
    {
        return '"' . bin2hex(hash('sha256', $body, true)) . '"';
    }

    /**
     * @param array<string, string> $headers
     */
    private function serverRequest(array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', new Uri('http://h.example/resource'), headers: $headers);
    }

    private function handler(Response $response): RequestHandlerInterface
    {
        return new readonly class($response) implements RequestHandlerInterface {
            public function __construct(private Response $response) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): Response
            {
                return $this->response;
            }
        };
    }
}
