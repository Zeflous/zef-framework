<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.14.1 — Mutation deep-dive round 2: Adapters/Http.
 *
 * Targets the escaped-mutant clusters reported by Infection for
 * RequestFactory (superglobal fallbacks, content-length boundary,
 * JSON body decode, URI authority parsing, header caps, upload
 * normalization, protocol variants, request-target guards) and the
 * smaller HTTP collaborators (ApiVersionNegotiator).
 *
 * Every test asserts an OBSERVABLE difference between original and
 * mutant behaviour (negative assertions, exact boundaries, exception
 * identity) instead of merely executing the code path.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\ApiVersionNegotiator;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;

/**
 * @internal
 */
final class MutationDeepHttpTest extends TestCase
{
    /** @var array<array-key,mixed> */
    private array $savedServer = [];

    /** @var array<array-key,mixed> */
    private array $savedCookie = [];

    /** @var array<array-key,mixed> */
    private array $savedGet = [];

    /** @var array<array-key,mixed> */
    private array $savedPost = [];

    /** @var array<array-key,mixed> */
    private array $savedFiles = [];

    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedCookie = $_COOKIE;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedFiles = $_FILES;
        $this->tmpFile = (string) \tempnam(\sys_get_temp_dir(), 'zefmut');
        \file_put_contents($this->tmpFile, 'upload-payload');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_COOKIE = $this->savedCookie;
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_FILES = $this->savedFiles;
        \putenv('ZEF_MAX_HEADER_COUNT');
        \putenv('ZEF_MAX_HEADER_VALUE_BYTES');
        \putenv('ZEF_MAX_HEADERS_TOTAL_BYTES');
        if ($this->tmpFile !== '' && \is_file($this->tmpFile)) {
            // $this->tmpFile is the \tempnam(\sys_get_temp_dir(), 'zefmut') path
            // created by this test's own setUp() (line 62) and used as a simulated
            // upload source. No request input reaches the argument, and the call is
            // guarded by \is_file(). Accepted suppression: section 7.3.
            @unlink($this->tmpFile); // nosemgrep: unlink-use
        }
    }

    // ------------------------------------------------------------------
    // Superglobal isolation (fromServer never reads them; fromGlobals does)
    // ------------------------------------------------------------------

    public function testFromServerIgnoresPlantedSuperglobalsWhileFromGlobalsForwardsThem(): void
    {
        // Regresi P-12 (issue #171): fromServer() must never consult the
        // superglobals — planted process state (e.g. a stale request inside
        // a long-running worker) must not leak into the injectable path,
        // not even through the optional null arguments.
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8 ',
            'CONTENT_LENGTH' => '7',
        ];
        $_COOKIE = ['session' => 'abc'];
        $_GET = ['page' => '2'];
        $_POST = ['field' => 'value'];
        $_FILES = ['doc' => [
            'name' => 'doc.txt',
            'type' => 'text/plain',
            'tmp_name' => $this->tmpFile,
            'error' => \UPLOAD_ERR_OK,
            'size' => \strlen('upload-payload'),
        ]];
        $isolated = $this->factory([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8 ',
            'CONTENT_LENGTH' => '7',
        ]);
        self::assertSame([], $isolated->getCookieParams());
        self::assertSame([], $isolated->getQueryParams());
        self::assertNull($isolated->getParsedBody());
        self::assertSame([], $isolated->getUploadedFiles());

        // fromGlobals() is the single superglobal reader: every planted
        // array is forwarded explicitly.
        $global = RequestFactory::fromGlobals();
        self::assertSame(['session' => 'abc'], $global->getCookieParams());
        self::assertSame(['page' => '2'], $global->getQueryParams());
        self::assertSame(['field' => 'value'], $global->getParsedBody());
        $uploads = $global->getUploadedFiles();
        self::assertArrayHasKey('doc', $uploads);
        $upload = $uploads['doc'];
        assert($upload instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_OK, $upload->getError());
        self::assertSame('doc.txt', $upload->getClientFilename());
        self::assertSame(14, $upload->getSize());
        self::assertSame('upload-payload', (string) $upload->getStream());
    }

    public function testNonFormContentTypeLeavesParsedBodyNullEvenWithPopulatedPost(): void
    {
        $_POST = ['poison' => 'no'];
        $request = $this->factory([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/json',
        ]);
        self::assertNull($request->getParsedBody());

        // Regresi P-12 (issue #171): the media-type gate must hold on the
        // superglobal path too — a planted $_POST never masquerades as a
        // parsed JSON body through fromGlobals().
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'application/json',
        ];
        self::assertNull(RequestFactory::fromGlobals()->getParsedBody());
    }

    public function testMultipartContentTypeAlsoReadsPostThroughFromGlobals(): void
    {
        // Regresi P-12 (issue #171): multipart form decoding reads $_POST
        // exclusively through the explicit superglobal adapter.
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'MULTIPART/FORM-DATA',
        ];
        $_POST = ['multi' => 'part'];
        self::assertSame(['multi' => 'part'], RequestFactory::fromGlobals()->getParsedBody());
    }

    public function testMultipartContentTypeAlsoHonorsInjectedParsedBody(): void
    {
        // Regresi P-12 (issue #171): the injected $parsedBody mirrors what
        // SAPI would have decoded into $_POST; the multipart media type
        // honors it exactly like the urlencoded one.
        $request = RequestFactory::fromServer(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'zef.test',
                'CONTENT_TYPE' => 'MULTIPART/FORM-DATA',
            ],
            [],
            [],
            null,
            null,
            null,
            ['multi' => 'part'],
        );
        self::assertSame(['multi' => 'part'], $request->getParsedBody());
    }

    public function testNonArrayPostSuperglobalIsIgnored(): void
    {
        $_POST = 'not-an-array';
        $request = $this->factory([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);
        self::assertNull($request->getParsedBody());

        // Regresi P-12 (issue #171): the is_array guard also protects the
        // superglobal adapter — a non-array $_POST degrades to "no parsed
        // body" instead of poisoning the typed argument.
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        self::assertNull(RequestFactory::fromGlobals()->getParsedBody());
    }

    public function testEmptyPostSuperglobalYieldsNullParsedBody(): void
    {
        $_POST = [];
        $request = $this->factory([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);
        self::assertNull($request->getParsedBody());

        // Regresi P-12 (issue #171): an empty $_POST is forwarded as "no
        // parsed body", never as an empty form result.
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        self::assertNull(RequestFactory::fromGlobals()->getParsedBody());
    }

    // ------------------------------------------------------------------
    // Content-Length guard: exact boundary against the body policy
    // ------------------------------------------------------------------

    public function testContentLengthEqualToLimitIsAccepted(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'CONTENT_LENGTH' => '10',
        ], new RequestBodyPolicy(10));
        self::assertSame('GET', $request->getMethod());
    }

    public function testContentLengthOneByteOverLimitIsRejected(): void
    {
        try {
            $this->factory([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'CONTENT_LENGTH' => '11',
            ], new RequestBodyPolicy(10));
            self::fail('Expected PayloadTooLargeException for content-length over the policy limit.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testNonNumericContentLengthIsIgnored(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'CONTENT_LENGTH' => '12abc',
        ], new RequestBodyPolicy(10));
        self::assertSame('GET', $request->getMethod());
    }

    public function testZeroAndNegativeContentLengthAreIgnored(): void
    {
        foreach (['0', '-5'] as $cl) {
            $request = $this->factory([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'CONTENT_LENGTH' => $cl,
            ], new RequestBodyPolicy(1));
            self::assertSame('GET', $request->getMethod(), "Content-Length {$cl} must not trip the guard");
        }
    }

    // ------------------------------------------------------------------
    // decodeJsonBody: seek restore, depth boundary, error identity
    // ------------------------------------------------------------------

    public function testDecodeJsonBodyRestoresStreamPositionAfterRead(): void
    {
        $body = Stream::fromString('{"a":1}');
        $body->seek(2);
        $request = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], $body);
        $decoded = RequestFactory::decodeJsonBody($request);
        self::assertSame(['a' => 1], $decoded);
        self::assertSame(2, $request->getBody()->tell(), 'Stream position must be restored after decoding.');
    }

    public function testDecodeJsonBodyAcceptsNestingUpToDepthLimit(): void
    {
        $deep = \str_repeat('[', 511) . '1' . \str_repeat(']', 511);
        $request = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], Stream::fromString($deep));
        $decoded = RequestFactory::decodeJsonBody($request);
        // Walk down without per-level PHPUnit asserts (they are slow); the
        // final depth check still proves the payload decoded to 511 levels.
        $depth = 0;
        $probe = $decoded;
        while (\is_array($probe)) {
            ++$depth;
            $probe = $probe[0] ?? false;
        }
        self::assertSame(511, $depth);
        self::assertSame(1, $probe);
    }

    public function testDecodeJsonBodyRejectsNestingBeyondDepthLimit(): void
    {
        $tooDeep = \str_repeat('[', 512) . '1' . \str_repeat(']', 512);
        $request = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], Stream::fromString($tooDeep));

        try {
            RequestFactory::decodeJsonBody($request);
            self::fail('Expected rejection of JSON nested beyond the 512 decode depth.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testDecodeJsonBodyWrapsMalformedPayload(): void
    {
        $request = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], Stream::fromString('{"a":'));

        try {
            RequestFactory::decodeJsonBody($request);
            self::fail('Expected malformed JSON rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Malformed JSON request body.', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testDecodeJsonBodyReturnsNullForEmptyBodyAndObjectsWhenRequested(): void
    {
        $empty = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], Stream::fromString(''));
        self::assertNull(RequestFactory::decodeJsonBody($empty));
        $object = new ServerRequest('POST', $this->uri('/'), [], [], [], [], null, [], Stream::fromString('{"a":1}'));
        $decoded = RequestFactory::decodeJsonBody($object, false);
        self::assertIsObject($decoded);
        self::assertSame(['a' => 1], (array) $decoded);
    }

    // ------------------------------------------------------------------
    // Scheme / forwarded headers / server port boundaries
    // ------------------------------------------------------------------

    public function testSchemeResolutionMatrix(): void
    {
        $matrix = [
            ['on', 'https'],
            ['1', 'https'],
            ['off', 'http'],
            ['', 'http'],
            ['0', 'http'],
        ];
        foreach ($matrix as [$https, $expected]) {
            $server = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'zef.test',
            ];
            if ($https !== '') {
                $server['HTTPS'] = $https;
            }
            self::assertSame($expected, $this->factory($server)->getUri()->getScheme(), "HTTPS={$https}");
        }
        self::assertSame('http', $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
        ])->getUri()->getScheme());
    }

    public function testForwardedProtoIsTrimmedLowercasedAndValidated(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'HTTP_X_FORWARDED_PROTO' => ' HTTPS , http',
            'HTTPS' => 'off',
            'REMOTE_ADDR' => '10.0.0.1',
        ], null, ['10.0.0.1']);
        self::assertSame('https', $request->getUri()->getScheme());

        try {
            $this->factory([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'zef.test',
                'HTTP_X_FORWARDED_PROTO' => 'ftp',
                'REMOTE_ADDR' => '10.0.0.1',
            ], null, ['10.0.0.1']);
            self::fail('Expected invalid forwarded protocol rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid forwarded protocol.', $e->getMessage());
        }
    }

    public function testForwardedHostTakesFirstValueAndTrims(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'HTTP_X_FORWARDED_HOST' => ' edge.example , inner.example',
            'REMOTE_ADDR' => '10.0.0.1',
        ], null, ['10.0.0.1']);
        self::assertSame('edge.example', $request->getUri()->getHost());
    }

    public function testServerNameIsUsedWhenHostHeaderMissing(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'fallback.test',
        ]);
        self::assertSame('fallback.test', $request->getUri()->getHost());

        $preferHeader = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'fallback.test',
            'HTTP_HOST' => 'header.test',
        ]);
        self::assertSame('header.test', $preferHeader->getUri()->getHost());
    }

    public function testServerPortBoundaries(): void
    {
        $portOf = static function (array $server): ?int {
            // @phpstan-ignore argument.type (mixed server values are intentional here)
            $request = RequestFactory::fromServer($server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
            assert($request instanceof ServerRequest);

            return $request->getUri()->getPort();
        };
        self::assertSame(8080, $portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '8080']));
        self::assertSame(1, $portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '1']));
        self::assertSame(65535, $portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '65535']));
        self::assertNull($portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '80']));
        self::assertNull($portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '0']));
        self::assertNull($portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => '65536']));
        self::assertNull($portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => 'abc']));
        self::assertSame(8080, $portOf(['HTTP_HOST' => 'zef.test', 'SERVER_PORT' => 8080]), 'Integer SERVER_PORT must still be honoured.');
    }

    public function testHttpsDefaultPortOmittedAndExplicitPortKept(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ]);
        self::assertNull($request->getUri()->getPort());
        $explicit = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'zef.test',
            'HTTPS' => 'on',
            'SERVER_PORT' => '8443',
        ]);
        self::assertSame(8443, $explicit->getUri()->getPort());
    }

    // ------------------------------------------------------------------
    // Front-controller script stripping
    // ------------------------------------------------------------------

    public function testFrontControllerStripVariants(): void
    {
        $base = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/srv/app/index.php',
        ];
        self::assertSame('/admin', $this->factory($base + ['REQUEST_URI' => '/index.php/admin'])->getUri()->getPath());
        self::assertSame('/', $this->factory($base + ['REQUEST_URI' => '/index.php'])->getUri()->getPath());
        self::assertSame('/', $this->factory($base + ['REQUEST_URI' => '/index.php/'])->getUri()->getPath());
        self::assertSame('/', $this->factory($base + ['REQUEST_URI' => '/index.php?q=1'])->getUri()->getPath());
        self::assertSame('q=1', $this->factory($base + ['REQUEST_URI' => '/index.php?q=1'])->getUri()->getQuery());
        self::assertSame('/index.phpx/admin', $this->factory($base + ['REQUEST_URI' => '/index.phpx/admin'])->getUri()->getPath(), 'Only exact script prefix followed by "/" may be stripped.');
        self::assertSame('/other/path', $this->factory($base + ['REQUEST_URI' => '/other/path'])->getUri()->getPath());
    }

    public function testFrontControllerRequiresBasenameMatch(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/app/index.php/x',
            'SCRIPT_NAME' => '/app/index.php',
            'SCRIPT_FILENAME' => '/srv/main.php',
        ]);
        self::assertSame('/app/index.php/x', $request->getUri()->getPath(), 'Script prefixes must not be stripped when basenames differ.');
    }

    // ------------------------------------------------------------------
    // splitRequestTarget / requestTarget
    // ------------------------------------------------------------------

    public function testAbsoluteAndAsteriskTargetsKeepRawTargetAndSplitParts(): void
    {
        $request = $this->factory([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'https://example.com:8443/path?x=1',
        ]);
        self::assertSame('/path', $request->getUri()->getPath());
        self::assertSame('x=1', $request->getUri()->getQuery());
        self::assertSame('https://example.com:8443/path?x=1', $request->getRequestTarget());

        $asterisk = $this->factory(['REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '*']);
        self::assertSame('/', $asterisk->getUri()->getPath());
        self::assertSame('*', $asterisk->getRequestTarget());
    }

    public function testMalformedNonPathRequestTargetIsRejected(): void
    {
        foreach (['http://', 'http://:80', ':'] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $bad]);
                self::fail("Expected malformed REQUEST_URI rejection for '{$bad}'.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Malformed REQUEST_URI.', $e->getMessage());
            }
        }
    }

    public function testFragmentIsStrippedFromOriginFormTarget(): void
    {
        $request = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/page#frag']);
        self::assertSame('/page', $request->getUri()->getPath());
        self::assertSame('', $request->getUri()->getFragment());
        self::assertSame('/page#frag', $request->getRequestTarget(), 'Raw target stays verbatim.');
    }

    public function testQueryExtractionBoundaries(): void
    {
        $request = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/a?b=c']);
        self::assertSame('/a', $request->getUri()->getPath());
        self::assertSame('b=c', $request->getUri()->getQuery());
        $emptyQuery = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/a?']);
        self::assertSame('', $emptyQuery->getUri()->getQuery());
    }

    public function testNewlineInAnyPositionOfTargetIsRejected(): void
    {
        foreach (["/a\r\nb", "/a\nb", "/a\rb"] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $bad]);
                self::fail('Expected rejection of request target containing line breaks.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------
    // Header caps (Env-tunable) — every boundary kills ±1 mutants
    // ------------------------------------------------------------------

    public function testHeaderCountDefaultBoundaries(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 128; ++$i) {
            $server['HTTP_X_H' . $i] = 'v';
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server), '128 headers accepted');

        $server['HTTP_X_H128'] = 'v';

        // (previous assertions: 128 headers accepted)
        try {
            $this->factory($server);
            self::fail('Expected header-count cap violation at 129 headers.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderCountMinClampBoundaries(): void
    {
        \putenv('ZEF_MAX_HEADER_COUNT=2');
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 8; ++$i) {
            $server['HTTP_X_H' . $i] = 'v';
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server), 'Clamped minimum (8) headers accepted');
        $server['HTTP_X_H8'] = 'v';

        try {
            $this->factory($server);
            self::fail('Expected header-count cap violation at 9 headers with clamped minimum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderCountMaxClampBoundaries(): void
    {
        \putenv('ZEF_MAX_HEADER_COUNT=99999');
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 4096; ++$i) {
            $server['HTTP_X_H' . $i] = 'v';
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server), 'Clamped maximum (4096) headers accepted');
        $server['HTTP_X_H4096'] = 'v';

        try {
            $this->factory($server);
            self::fail('Expected header-count cap violation beyond clamped maximum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderValueBytesDefaultBoundaries(): void
    {
        $ok = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 16384)]);
        self::assertSame([\str_repeat('v', 16384)], $ok->getHeader('X-Big'));

        try {
            $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 16385)]);
            self::fail('Expected per-value cap violation at 16385 bytes.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderValueBytesMinClampBoundaries(): void
    {
        \putenv('ZEF_MAX_HEADER_VALUE_BYTES=100');
        $ok = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 256)]);
        self::assertSame([\str_repeat('v', 256)], $ok->getHeader('X-Big'));

        try {
            $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 257)]);
            self::fail('Expected per-value cap violation beyond clamped minimum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderValueBytesMaxClampStillRejectsBeyondMaximum(): void
    {
        // NOTE: the OK side of the maximum clamp (exactly 1048576 bytes) is
        // unreachable through fromServer(): the total-bytes cap (max
        // 1048576, inclusive of name bytes) always fires first. Only the
        // rejection side is observable, and it must fire from the VALUE cap
        // (line before the total cap) for a 1048577-byte value.
        \putenv('ZEF_MAX_HEADER_VALUE_BYTES=99999999');
        \putenv('ZEF_MAX_HEADERS_TOTAL_BYTES=99999999');

        try {
            $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 1048577)]);
            self::fail('Expected per-value cap violation beyond clamped maximum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderTotalBytesDefaultBoundaries(): void
    {
        // 4 headers x (16380 value bytes + 4 name bytes) = 65536 exactly.
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 4; ++$i) {
            $server['HTTP_X_T' . $i] = \str_repeat('v', 16380);
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server), 'Total header bytes exactly at cap accepted');
        // One more (empty value, 1-byte name) pushes the total to 65537.
        $server['HTTP_X'] = '';

        try {
            $this->factory($server);
            self::fail('Expected total header bytes cap violation at 65537.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderTotalBytesMinClampBoundaries(): void
    {
        \putenv('ZEF_MAX_HEADERS_TOTAL_BYTES=100');
        // 8 headers x (124 value + 4 name) = 1024 exactly (clamped minimum).
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 8; ++$i) {
            $server['HTTP_X_T' . $i] = \str_repeat('v', 124);
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server));
        $server['HTTP_X'] = '';

        try {
            $this->factory($server);
            self::fail('Expected total header bytes cap violation beyond clamped minimum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testHeaderTotalBytesMaxClampBoundaries(): void
    {
        \putenv('ZEF_MAX_HEADERS_TOTAL_BYTES=99999999');
        \putenv('ZEF_MAX_HEADER_VALUE_BYTES=99999999');
        // 64 headers x (16379 value + 5 padded name) = 1048576 exactly.
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        for ($i = 0; $i < 64; ++$i) {
            $server['HTTP_X_T' . \str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = \str_repeat('v', 16379);
        }
        self::assertInstanceOf(ServerRequest::class, $this->factory($server));
        $server['HTTP_X'] = '';

        try {
            $this->factory($server);
            self::fail('Expected total header bytes cap violation beyond clamped maximum.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testValueCapAppliesIndependentlyOfCountCap(): void
    {
        \putenv('ZEF_MAX_HEADER_COUNT=999');
        \putenv('ZEF_MAX_HEADER_VALUE_BYTES=100');

        try {
            $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_BIG' => \str_repeat('v', 300)]);
            self::fail('Expected per-value cap to fire even when the count cap is far away.');
        } catch (PayloadTooLargeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testInvalidHeaderNameOrValueCharactersAreRejected(): void
    {
        foreach (['HTTP_X_(BAD)' => 'v', 'HTTP_X_OK' => "bad\nvalue"] as $key => $value) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', $key => $value]);
                self::fail("Expected invalid header rejection for {$key}.");
            } catch (InvalidHeaderException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------
    // Upload normalization edges
    // ------------------------------------------------------------------

    public function testGhostUploadWithoutTmpNameDowngradesToNoFile(): void
    {
        $request = $this->factoryWithUploads([
            'ghost' => ['name' => 'x.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => \UPLOAD_ERR_OK, 'size' => 100],
        ]);
        $upload = $request->getUploadedFiles()['ghost'];
        assert($upload instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $upload->getError(), 'OK upload without tmp_name is a ghost and must be downgraded.');
        self::assertSame(100, $upload->getSize(), 'Client-declared size is preserved; only the error state changes.');
    }

    public function testStringErrorCodesNormalizeAndInvalidStringsFallBackToNoFile(): void
    {
        $request = $this->factoryWithUploads([
            'ok' => ['name' => 'a.txt', 'type' => null, 'tmp_name' => $this->tmpFile, 'error' => '0', 'size' => '14'],
            'bad' => ['name' => 'b.txt', 'type' => null, 'tmp_name' => '', 'error' => 'not-a-code', 'size' => null],
            'null' => ['name' => 'c.txt', 'type' => null, 'tmp_name' => '', 'error' => null, 'size' => null],
        ]);
        $uploads = $request->getUploadedFiles();
        $ok = $uploads['ok'];
        assert($ok instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_OK, $ok->getError());
        self::assertSame(14, $ok->getSize());
        self::assertSame('upload-payload', (string) $ok->getStream());
        $bad = $uploads['bad'];
        assert($bad instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $bad->getError());
        $null = $uploads['null'];
        assert($null instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $null->getError());
        self::assertSame(0, $null->getSize(), 'Missing size falls back to the empty stream size.');
    }

    public function testErroredUploadKeepsErrorAndRefusesStreamAccess(): void
    {
        $request = $this->factoryWithUploads([
            'err' => ['name' => 'd.txt', 'type' => 'text/plain', 'tmp_name' => '/nonexistent/tmp', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 5],
        ]);
        $upload = $request->getUploadedFiles()['err'];
        assert($upload instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $upload->getError());
        $this->expectException(\RuntimeException::class);
        $upload->getStream();
    }

    public function testScalarUploadEntryIsRejectedByServerRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded files must contain only UploadedFileInterface leaves.');
        $this->factoryWithUploads(['junk' => 'keep-me']);
    }

    public function testPrebuiltUploadInstancesPassThroughNormalizationUntouched(): void
    {
        $prebuilt = new Psr17Factory()->createUploadedFile(
            Stream::fromString('preset'),
            null,
            \UPLOAD_ERR_OK,
            'pre.txt',
        );
        $request = $this->factoryWithUploads([
            'pre' => $prebuilt,
            'nested' => ['inner' => $prebuilt],
        ]);
        $files = $request->getUploadedFiles();
        self::assertSame($prebuilt, $files['pre'], 'Prebuilt instances skip normalization entirely.');
        self::assertSame(['inner' => $prebuilt], $files['nested']);
    }

    public function testUploadTreeNormalizesNestedLeaves(): void
    {
        $request = $this->factoryWithUploads([
            'group' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => ['', ''],
                'error' => [\UPLOAD_ERR_NO_FILE, \UPLOAD_ERR_NO_FILE],
                'size' => [null, null],
            ],
        ]);
        $files = $request->getUploadedFiles();
        self::assertIsArray($files['group']);
        $first = $files['group'][0];
        $second = $files['group'][1];
        assert($first instanceof UploadedFileInterface && $second instanceof UploadedFileInterface);
        self::assertSame(\UPLOAD_ERR_NO_FILE, $first->getError());
        self::assertSame(\UPLOAD_ERR_NO_FILE, $second->getError());
        self::assertSame('a.txt', $first->getClientFilename());
        self::assertSame('b.txt', $second->getClientFilename());
    }

    // ------------------------------------------------------------------
    // Protocol version normalization
    // ------------------------------------------------------------------

    public function testProtocolVersionNormalizationMatrix(): void
    {
        $matrix = [
            'HTTP/1.1' => '1.1',
            'HTTP/2' => '2',
            'HTTP/3' => '3',
            'HTTP/22' => '1.1',
            'HTTP/1.1.1' => '1.1',
            'XHTTP/2.0' => '1.1',
            'HTTP/2.0X' => '1.1',
            'HTTP/99' => '1.1',
            'http/2' => '1.1',
        ];
        foreach ($matrix as $raw => $expected) {
            $request = $this->factory([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'SERVER_PROTOCOL' => $raw,
            ]);
            self::assertSame($expected, $request->getProtocolVersion(), "SERVER_PROTOCOL={$raw}");
        }
    }

    // ------------------------------------------------------------------
    // Host authority parsing via HTTP_HOST
    // ------------------------------------------------------------------

    public function testHostAuthorityTrimmingAndCaseFolding(): void
    {
        $request = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => '  Example.COM  ']);
        self::assertSame('example.com', $request->getUri()->getHost());
    }

    public function testHostAuthorityRejectsForbiddenCharacters(): void
    {
        foreach (['exa@mple', 'exa/mple', 'exa?mple', 'exa#mple'] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $bad]);
                self::fail("Expected malformed Host rejection for '{$bad}'.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Malformed Host header.', $e->getMessage());
            }
        }
    }

    public function testIpv6HostParsingMatrix(): void
    {
        self::assertSame('::1', $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => '[::1]'])->getUri()->getHost());
        self::assertSame(8080, $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => '[::1]:8080'])->getUri()->getPort());
        foreach (['[::1', '[::1]:80x'] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $bad]);
                self::fail("Expected malformed IPv6 Host rejection for '{$bad}'.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Malformed Host header.', $e->getMessage());
            }
        }
    }

    public function testRegularHostPortMatrix(): void
    {
        self::assertSame(8080, $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'a.test:8080'])->getUri()->getPort());
        self::assertSame(1, $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'a.test:1'])->getUri()->getPort());
        self::assertSame(65535, $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'a.test:65535'])->getUri()->getPort());
        foreach (['a.test:0', 'a.test:65536', 'a.test:80x', 'a.test:', 'a:b:c'] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $bad]);
                self::fail("Expected malformed Host rejection for '{$bad}'.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDnsLabelGrammarBoundaries(): void
    {
        foreach (['a', 'z', '0', '9', 'a-b', 'az09', 'a.b', 'a.b.'] as $ok) {
            $request = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $ok]);
            self::assertSame(\strtolower(\rtrim($ok, '.')), $request->getUri()->getHost(), "Host {$ok} must be accepted");
        }
        foreach (['a_b', '-a', 'a-', 'a..b', 'a.b..', 'ab-', 'a!b'] as $bad) {
            try {
                $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $bad]);
                self::fail("Expected malformed Host rejection for '{$bad}'.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Malformed Host header.', $e->getMessage());
            }
        }
    }

    public function testHostLengthBoundary(): void
    {
        $ok = \str_repeat('a.', 126) . 'a'; // 253 chars, valid labels
        self::assertSame($ok, $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $ok])->getUri()->getHost());
        $tooLong = \str_repeat('a.', 126) . 'aa'; // 254 chars

        try {
            $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $tooLong]);
            self::fail('Expected rejection of 254-char host.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Malformed Host header.', $e->getMessage());
        }
    }

    public function testNonStringRemoteAddrIsCoercedSafely(): void
    {
        /** @var array<string,mixed> $server */
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => 123,
        ];
        $request = RequestFactory::fromServer($server);
        assert($request instanceof ServerRequest);
        self::assertSame('/', $request->getUri()->getPath());
    }

    public function testEmptyHostFallsBackToLocalhost(): void
    {
        $request = $this->factory(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x']);
        self::assertSame('localhost', $request->getUri()->getHost());
        self::assertSame('/x', $request->getUri()->getPath());
    }

    // ------------------------------------------------------------------
    // ApiVersionNegotiator construction guards
    // ------------------------------------------------------------------

    public function testApiVersionNegotiatorRejectsEmptySupportedList(): void
    {
        try {
            new ApiVersionNegotiator([]);
            self::fail('Expected empty supported-list rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('ApiVersionNegotiator requires at least one supported version.', $e->getMessage());
        }
    }

    public function testApiVersionNegotiatorRejectsInvalidTokens(): void
    {
        foreach ([[''], ['bad version!'], [123], ['ok', 'BAD~']] as $bad) {
            try {
                // @phpstan-ignore argument.type (invalid tokens are the scenario under test)
                new ApiVersionNegotiator($bad);
                self::fail('Expected invalid version-token rejection for ' . \json_encode($bad, \JSON_THROW_ON_ERROR));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $server
     * @param list<string> $trustedProxies
     */
    private function factory(array $server, ?RequestBodyPolicy $policy = null, array $trustedProxies = []): ServerRequest
    {
        $request = RequestFactory::fromServer($server, [], $trustedProxies, $policy);
        assert($request instanceof ServerRequest);

        return $request;
    }

    /** @param array<string,mixed> $files */
    private function factoryWithUploads(array $files): ServerRequest
    {
        $request = RequestFactory::fromServer(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'zef.test'],
            [],
            [],
            null,
            null,
            null,
            null,
            $files,
        );
        assert($request instanceof ServerRequest);

        return $request;
    }

    private function uri(string $target): Uri
    {
        return new Uri($target);
    }
}
