<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): HTTP message deep edges —
 * streams, uploaded files, limited input, request/target parsing and the
 * server request mutation guards.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

/**
 * @internal
 */
final class HttpDeepTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_MAX_HEADER_COUNT');
        putenv('ZEF_MAX_HEADER_VALUE_BYTES');
    }
    // ------------------------------------------------------------------
    // Stream
    // ------------------------------------------------------------------

    public function testStreamRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type
        new Stream('not-a-resource');
    }

    public function testStreamToStringOnClosedStreamIsEmpty(): void
    {
        $stream = Stream::fromString('payload');
        $stream->close();
        self::assertSame('', (string) $stream);
        self::assertNull($stream->getSize());
    }

    public function testStreamTellAndSeekAfterDetach(): void
    {
        $stream = Stream::fromString('payload');
        $resource = $stream->detach();
        self::assertIsResource($resource);
        fclose($resource);
        $this->expectException(\RuntimeException::class);
        $stream->tell();
    }

    public function testStreamSeekOnDetachedStreamFails(): void
    {
        $stream = Stream::fromString('payload');
        $stream->detach();
        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testStreamWriteReadAndSeekCycle(): void
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        self::assertTrue($stream->isWritable());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isSeekable());
        self::assertSame(4, $stream->write('abcd'));
        self::assertSame(4, $stream->tell());
        $stream->seek(2);
        self::assertSame(2, $stream->tell());
        self::assertSame('cd', $stream->read(2));
        $stream->seek(2);
        self::assertSame('cd', $stream->getContents());
        $stream->rewind();
        self::assertSame('abcd', (string) $stream);
        self::assertIsArray($stream->getMetadata());
        self::assertSame('PHP', $stream->getMetadata('wrapper_type'));
        self::assertSame(4, $stream->getSize());
        $stream->seek(0);
        self::assertFalse($stream->eof());
        // Reading one byte MORE than available trips feof() — reading the
        // exact remaining count would not (PHP feof semantics).
        $stream->read(5);
        self::assertTrue($stream->eof());
    }

    public function testStreamWriteOnReadOnlyResourceFails(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        self::assertFalse($stream->isWritable());
        $this->expectException(\RuntimeException::class);
        $stream->write('nope');
    }

    public function testStreamReadGuards(): void
    {
        $stream = Stream::fromString('payload');
        $this->expectException(\InvalidArgumentException::class);
        $stream->read(-1);
    }

    public function testStreamReadOnNonReadableResourceFails(): void
    {
        $resource = fopen('php://stdout', 'wb');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        self::assertFalse($stream->isReadable());
        $this->expectException(\RuntimeException::class);
        $stream->read(4);
    }

    public function testStreamGetContentsOnDetachedStreamFails(): void
    {
        $stream = Stream::fromString('payload');
        $stream->detach();
        $this->expectException(\RuntimeException::class);
        $stream->getContents();
    }

    public function testStreamSeekOnNonSeekableStreamFails(): void
    {
        $resource = fopen('php://output', 'wb');
        self::assertIsResource($resource);
        $stream = new Stream($resource);
        self::assertFalse($stream->isSeekable());
        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testLimitedStreamReadGuards(): void
    {
        $stream = $this->limitedStream('abcdef', 10);
        $this->expectException(\InvalidArgumentException::class);
        $stream->read(-1);
    }

    public function testLimitedStreamReadZeroReturnsEmpty(): void
    {
        self::assertSame('', $this->limitedStream('abcdef', 10)->read(0));
    }

    public function testLimitedStreamAllowsBodyUpToLimit(): void
    {
        $stream = $this->limitedStream('abcdef', 6);
        self::assertSame('abcdef', $stream->getContents());
        self::assertSame(6, $stream->getSize());
    }

    public function testLimitedStreamThrowsWhenBodyExceedsLimit(): void
    {
        $stream = $this->limitedStream(str_repeat('x', 12), 8);
        $this->expectException(PayloadTooLargeException::class);
        $stream->getContents();
    }

    public function testLimitedStreamToStringSwallowsPayloadTooLarge(): void
    {
        $stream = $this->limitedStream(str_repeat('x', 12), 8);
        self::assertSame('', (string) $stream);
    }

    public function testLimitedStreamResetsObservedBytesOnRewind(): void
    {
        $stream = $this->limitedStream('abcdef', 12);
        self::assertSame('abc', $stream->read(3));
        $stream->rewind();
        self::assertSame('abcdef', $stream->getContents());
        $stream->seek(1);
        self::assertSame('bcdef', $stream->getContents());
        self::assertFalse($stream->isWritable());
        self::assertTrue($stream->isReadable());
        $this->expectException(\RuntimeException::class);
        $stream->write('x');
    }

    public function testLimitedStreamDelegatesMetadata(): void
    {
        $stream = $this->limitedStream('abc', 4);
        $stream->close();
        self::assertNull($stream->getSize());
        self::assertNull($stream->getMetadata('wrapper_type'));
    }

    // ------------------------------------------------------------------
    // UploadedFile
    // ------------------------------------------------------------------

    public function testUploadedFileRejectsNegativeSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadedFile(Stream::fromString('x'), -1);
    }

    public function testUploadedFileGetStreamFailsOnErrorAndAfterMove(): void
    {
        $errored = new UploadedFile(Stream::fromString('x'), 1, UPLOAD_ERR_INI_SIZE);
        $this->expectException(\RuntimeException::class);
        $errored->getStream();
    }

    public function testUploadedFileMoveToGuards(): void
    {
        $file = new UploadedFile(Stream::fromString('abc'), 3);

        try {
            $file->moveTo('');
            self::fail('Expected empty target rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\RuntimeException::class);
        $file->moveTo('/definitely/not/a/directory/target.bin');
    }

    public function testUploadedFileMoveToFailsForErroredUpload(): void
    {
        $file = new UploadedFile(Stream::fromString('abc'), 3, UPLOAD_ERR_PARTIAL);
        $this->expectException(\RuntimeException::class);
        $file->moveTo('/tmp/should-not-be-written.bin');
    }

    public function testUploadedFileMoveToCopiesThenRefusesAgain(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'zefup');
        assert($target !== false);
        @unlink($target); // nosemgrep: php.lang.security.unlink-use
        $directory = sys_get_temp_dir();
        $path = $directory . '/zef-upload-' . bin2hex(random_bytes(4)) . '.bin';
        $file = new UploadedFile(Stream::fromString('uploaded-bytes'), 13);
        $file->moveTo($path);
        self::assertSame('uploaded-bytes', (string) file_get_contents($path));
        $this->expectException(\RuntimeException::class);

        try {
            $file->moveTo($path);
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testUploadedFileExposesClientMetadata(): void
    {
        $file = new UploadedFile(Stream::fromString('abc'), 3, UPLOAD_ERR_OK, 'report.txt', 'text/plain');
        self::assertSame(3, $file->getSize());
        self::assertSame('report.txt', $file->getClientFilename());
        self::assertSame('text/plain', $file->getClientMediaType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
    }

    // ------------------------------------------------------------------
    // ServerRequest guards
    // ------------------------------------------------------------------

    public function testServerRequestRejectsScalarParsedBody(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ServerRequest('GET', new Uri('http://localhost/'), [], [], [], [], 'not-an-array');
    }

    public function testServerRequestUploadedTreeValidation(): void
    {
        $request = new ServerRequest('GET', new Uri('http://localhost/'));
        $leaf = new UploadedFile(Stream::fromString('a'), 1);
        $mutated = $request->withUploadedFiles(['nested' => ['f' => $leaf]]);
        $tree = $mutated->getUploadedFiles();
        self::assertArrayHasKey('nested', $tree);
        self::assertIsArray($tree['nested']);
        self::assertArrayHasKey('f', $tree['nested']);
        self::assertSame($leaf, $tree['nested']['f']);
        $this->expectException(\InvalidArgumentException::class);
        $request->withUploadedFiles(['broken' => 'nope']);
    }

    public function testServerRequestQueryAndAttributeMutations(): void
    {
        $request = new ServerRequest('GET', new Uri('http://localhost/?a=1'), [], [], ['a' => '1']);
        self::assertSame(['a' => '1'], $request->getQueryParams());
        $withQuery = $request->withQueryParams(['b' => '2']);
        self::assertSame(['b' => '2'], $withQuery->getQueryParams());
        self::assertNull($request->getAttribute('missing'));
        self::assertSame('fallback', $request->getAttribute('missing', 'fallback'));
        $withAttr = $request->withAttribute('k', 'v');
        self::assertSame('v', $withAttr->getAttribute('k'));
        self::assertNull($withAttr->withoutAttribute('k')->getAttribute('k'));
    }

    public function testFactoryParsesIPv6HostWithPort(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/v6',
            'HTTP_HOST' => '[::1]:8080',
        ]);
        self::assertSame('::1', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
    }

    public function testFactoryParsesTrailingDotAndDefaultPorts(): void
    {
        // Regresi N-7 (issue #176): an explicit Host-header default port
        // normalizes to "no port" once it reaches Uri (http:80).
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dot',
            'HTTP_HOST' => 'example.test.:80',
        ]);
        self::assertSame('example.test', $request->getUri()->getHost());
        self::assertNull($request->getUri()->getPort(), 'explicit http:80 from the Host header is omitted');
        self::assertSame('http://example.test/dot', (string) $request->getUri());
    }

    public function testFactoryRejectsMalformedHosts(): void
    {
        foreach (['bad host', 'host:port', '[::1', 'host:99999'] as $badHost) {
            try {
                $this->factoryRequest([
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/',
                    'HTTP_HOST' => $badHost,
                ]);
                self::fail("Expected malformed host rejection for '{$badHost}'.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'host:0',
        ]);
    }

    public function testFactoryAcceptsHostlessRequests(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'cli.test',
        ]);
        self::assertSame('cli.test', $request->getUri()->getHost());
    }

    public function testFactoryNormalizesFrontControllerScriptPath(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php/hello',
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/srv/app/public/index.php',
        ]);
        self::assertSame('/hello', $request->getUri()->getPath());

        $rooted = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/srv/app/public/index.php',
        ]);
        self::assertSame('/', $rooted->getUri()->getPath());
    }

    public function testFactoryParsesAsteriskAndAbsoluteTargets(): void
    {
        $options = $this->factoryRequest([
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '*',
            'HTTP_HOST' => 'localhost',
        ]);
        self::assertSame('*', $options->getRequestTarget());

        $absolute = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'http://localhost/abs?x=1',
            'HTTP_HOST' => 'localhost',
        ]);
        self::assertSame('/abs', $absolute->getUri()->getPath());
        self::assertSame('x=1', $absolute->getUri()->getQuery());

        $this->expectException(\InvalidArgumentException::class);
        $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'http://',
            'HTTP_HOST' => 'localhost',
        ]);
    }

    public function testFactoryStripsQueryAndFragmentFromOriginForm(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/page?limit=2#top',
            'HTTP_HOST' => 'localhost',
        ]);
        self::assertSame('/page', $request->getUri()->getPath());
        self::assertSame('limit=2', $request->getUri()->getQuery());
    }

    public function testFactoryHonoursForwardedProtoAndHostFromTrustedProxy(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/secure',
            'HTTP_HOST' => 'internal.local',
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'public.example.test, internal.local',
            'SERVER_PORT' => '8080',
        ], ['10.0.0.9']);
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('public.example.test', $request->getUri()->getHost());
    }

    public function testFactoryIgnoresMalformedServerPort(): void
    {
        $request = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => 'not-a-port',
        ]);
        self::assertNull($request->getUri()->getPort());
    }

    public function testFactoryRejectsOversizedHeaderPayload(): void
    {
        putenv('ZEF_MAX_HEADER_VALUE_BYTES=256');
        $this->expectException(PayloadTooLargeException::class);
        $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_BIG' => str_repeat('v', 300),
        ]);
    }

    public function testFactoryRejectsOversizedDeclaredContentLength(): void
    {
        $this->expectException(PayloadTooLargeException::class);
        $this->factoryRequest([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'CONTENT_LENGTH' => '999999',
        ], [], new RequestBodyPolicy(16));
    }

    public function testFactoryNormalizesUploadTree(): void
    {
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'HTTP_HOST' => 'localhost',
        ], [], [], null, [], [], [], [
            'ghost' => ['name' => 'a.txt', 'error' => UPLOAD_ERR_OK, 'size' => 10],
            'real' => ['name' => 'b.txt', 'error' => UPLOAD_ERR_NO_FILE, 'size' => null],
            'string_error' => ['name' => 'c.txt', 'error' => '3', 'size' => '12'],
            'nested' => [
                'name' => ['x' => 'd.txt'],
                'error' => ['x' => UPLOAD_ERR_OK],
                'size' => ['x' => 3],
                'tmp_name' => ['x' => ''],
            ],
        ]);
        $uploads = $request->getUploadedFiles();
        foreach (['ghost', 'real', 'string_error'] as $key) {
            self::assertArrayHasKey($key, $uploads);
            self::assertInstanceOf(UploadedFileInterface::class, $uploads[$key]);
        }
        self::assertIsArray($uploads['nested']);
        self::assertInstanceOf(UploadedFileInterface::class, $uploads['nested']['x']);
        self::assertSame(UPLOAD_ERR_NO_FILE, $uploads['ghost']->getError());
        self::assertSame(UPLOAD_ERR_NO_FILE, $uploads['real']->getError());
        self::assertSame(UPLOAD_ERR_PARTIAL, $uploads['string_error']->getError());
        self::assertSame(12, $uploads['string_error']->getSize());
        self::assertSame(UPLOAD_ERR_NO_FILE, $uploads['nested']['x']->getError());
        self::assertNull($request->getParsedBody());
    }

    public function testFactoryNormalizesProtocolVariants(): void
    {
        $modern = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
        ]);
        self::assertSame('2.0', $modern->getProtocolVersion());

        $weird = $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'SERVER_PROTOCOL' => 'HTTP/22',
        ]);
        self::assertSame('1.1', $weird->getProtocolVersion());
    }

    public function testFactoryRejectsNewlineInRequestTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factoryRequest([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => "/bad\r\nHost: evil",
            'HTTP_HOST' => 'localhost',
        ]);
    }

    // ------------------------------------------------------------------
    // LimitedInputStream
    // ------------------------------------------------------------------

    private function limitedStream(string $content, int $maxBytes): LimitedInputStream
    {
        return new LimitedInputStream(Stream::fromString($content), new RequestBodyPolicy($maxBytes));
    }

    // ------------------------------------------------------------------
    // RequestFactory parsing edges
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $server
     * @param list<string> $trustedProxies
     */
    private function factoryRequest(array $server, array $trustedProxies = [], ?RequestBodyPolicy $policy = null): ServerRequest
    {
        $request = RequestFactory::fromServer($server, [], $trustedProxies, $policy);
        assert($request instanceof ServerRequest);

        return $request;
    }
}
