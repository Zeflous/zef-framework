<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — fase 4 (HTTP): adversarial tests distilled from
 * escaped mutants in chunk adapters-http (baseline 277 escapes, MSI 75).
 *
 * Every test here represents a real-world adversarial scenario documented
 * in docs/EDGE-CASE-MATRIX.md §6: percent-encoding boundaries, stream
 * lifecycle after close/detach, CIDR prefix arithmetic, conditional-GET
 * grammar, upload atomicity, and PSR-7 immutability contracts.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Exception\ApiVersionUnsupportedException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\ApiVersion;
use Zef\Framework\Http\ApiVersionNegotiator;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\MessageBase;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\TrustedProxyMatcher;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;

/**
 * @internal
 */
final class EdgeMatrixHttpTest extends TestCase
{
    // ------------------------------------------------------------------
    // Uri: parse + serialization composition
    // ------------------------------------------------------------------

    public function testUriFullRoundTripKeepsEveryComponent(): void
    {
        $uri = new Uri('https://user:p%40ss@Example.COM:8443/a%20b/c?x=1&y=%2F#frag');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:p%40ss', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/a%20b/c', $uri->getPath());
        self::assertSame('x=1&y=%2F', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
        self::assertSame('user:p%40ss@example.com:8443', $uri->getAuthority());
        self::assertSame('https://user:p%40ss@example.com:8443/a%20b/c?x=1&y=%2F#frag', (string) $uri);
    }

    public function testUriToStringWithoutAuthorityKeepsLeadingPath(): void
    {
        $uri = new Uri('/only/path?q#f');

        self::assertSame('/only/path?q#f', (string) $uri);
        self::assertSame('', $uri->getScheme());
        self::assertNull($uri->getPort());
    }

    public function testUriToStringWithAuthorityAndRelativePathInsertsSlash(): void
    {
        $uri = new Uri('http://h.example')
            ->withPath('rel')
            ->withPort(9090)
        ;

        self::assertSame('http://h.example:9090/rel', (string) $uri);
    }

    public function testUriAuthorityWrapsIpv6AndOmitsDefaultishPort(): void
    {
        $uri = new Uri('http://user@[2001:db8::1]/x');

        self::assertSame('user@[2001:db8::1]', $uri->getAuthority());
        self::assertSame('2001:db8::1', $uri->getHost());
        self::assertSame('http://user@[2001:db8::1]/x', (string) $uri);
    }

    public function testUriUnparseableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unable to parse URI 'http:///path'.");

        new Uri('http:///path');
    }

    // ------------------------------------------------------------------
    // Uri: with* validators and encoding boundaries
    // ------------------------------------------------------------------

    public function testWithSchemeLowercasesAndAcceptsEmpty(): void
    {
        $uri = new Uri('http://h.example')->withScheme('HTTPS');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('', new Uri('http://h.example')->withScheme('')->getScheme());
    }

    public function testWithSchemeRejectsMalformedAndControlChars(): void
    {
        $uri = new Uri('http://h.example');

        foreach (['1http', 'ht tp', "a\x07b"] as $bad) {
            try {
                $uri->withScheme($bad);
                self::fail("Scheme '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(
                    $e->getMessage() === 'Invalid URI scheme.'
                    || str_contains($e->getMessage(), 'URI scheme'),
                    "Unexpected message for '{$bad}': {$e->getMessage()}",
                );
            }
        }
    }

    public function testWithUserInfoEncodesUserAndPasswordExactly(): void
    {
        $uri = new Uri('http://h.example');
        $both = $uri->withUserInfo('u@x', 'p:1');

        self::assertSame('u%40x:p%3A1', $both->getUserInfo(), 'userinfo @ must be encoded; password colon must be encoded');
        self::assertSame('u%40x', $uri->withUserInfo('u@x')->getUserInfo(), 'no password must not append a colon');
        self::assertSame('u%40x@example.com', $uri->withHost('example.com')->withUserInfo('u@x')->getAuthority());
    }

    public function testWithUserInfoRejectsControlCharacters(): void
    {
        $uri = new Uri('http://h.example');

        try {
            $uri->withUserInfo("u\x00x");
            self::fail('Control char in user must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('URI user info', $e->getMessage());
        }

        try {
            $uri->withUserInfo('u', "p\x1F");
            self::fail('Control char in password must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('URI user info', $e->getMessage());
        }
    }

    public function testWithHostTrimsLowercasesAndStripsIpv6Brackets(): void
    {
        $uri = new Uri('http://h.example');

        self::assertSame('example.com', $uri->withHost('  Example.COM ')->getHost());
        self::assertSame('::1', $uri->withHost('[::1]')->getHost());
        self::assertSame('[::1]', $uri->withHost('[::1]')->getAuthority());
    }

    public function testWithHostRejectsUntrustedAndMalformedHosts(): void
    {
        $locked = new Uri('http://a.example', ['a.example']);

        try {
            $locked->withHost('b.example');
            self::fail('Host outside the trusted list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Untrusted host', $e->getMessage());
        }

        try {
            $uri = new Uri('http://h.example');
            $uri->withHost("a\x7Fb");
            self::fail('Control chars in host must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid URI host control characters.', $e->getMessage());
        }

        foreach (['bad host', 'h//x'] as $bad) {
            try {
                $uri = new Uri('http://h.example');
                $uri->withHost($bad);
                self::fail("Host '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid URI host.', $e->getMessage(), "Unexpected message for '{$bad}'");
            }
        }
    }

    public function testWithPortBoundaryEnforcedByValidator(): void
    {
        $uri = new Uri('http://h.example:8080');

        self::assertSame(65535, $uri->withPort(65535)->getPort());
        self::assertNull($uri->withPort(null)->getPort(), 'withPort(null) clears the port');
        self::assertSame(8080, $uri->getPort(), 'withPort must not mutate the receiver');

        foreach ([0, -1, 65536, 70000] as $bad) {
            try {
                $uri->withPort($bad);
                self::fail("Port {$bad} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid port', $e->getMessage());
            }
        }
    }

    #[DataProvider('providePercentSequences')]
    public function testPathAndQueryPercentSequencesAreCanonicalized(string $raw, string $path, string $query): void
    {
        $uri = new Uri('http://h.example');

        self::assertSame($path, $uri->withPath($raw)->getPath());
        self::assertSame($query, $uri->withQuery($raw)->getQuery());
    }

    /** @return iterable<array{string, string, string}> */
    public static function providePercentSequences(): iterable
    {
        // Valid %XY survives (upper-cased); incomplete/invalid sequences are
        // re-encoded; space is always encoded; '?' is legal inside query only.
        yield ['a%2fb', 'a%2Fb', 'a%2Fb'];

        yield ['a%2', 'a%252', 'a%252'];

        yield ['%G4', '%25G4', '%25G4'];

        yield ['a b', 'a%20b', 'a%20b'];

        yield ['%41%42', '%41%42', '%41%42'];
    }

    public function testPathComponentPreservesSubDelimsAndSlash(): void
    {
        $uri = new Uri('http://h.example');

        self::assertSame("/a!$&'()*+,;=-._~/b", $uri->withPath("/a!$&'()*+,;=-._~/b")->getPath());
    }

    public function testUriComponentMutatorsRejectControlCharacters(): void
    {
        $uri = new Uri('http://h.example');

        foreach ([
            'path' => static fn (): UriInterface => $uri->withPath("a\x00b"),
            'query' => static fn (): UriInterface => $uri->withQuery("a\x1Bb"),
            'fragment' => static fn (): UriInterface => $uri->withFragment("a\x7Fb"),
        ] as $component => $call) {
            try {
                $call();
                self::fail("Control character in {$component} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("URI {$component}", $e->getMessage());
            }
        }
    }

    public function testUriWithersAreImmutableAcrossAllComponents(): void
    {
        $original = new Uri('https://u@orig.example:8000/orig?q=orig#orig');

        $modified = $original
            ->withScheme('http')
            ->withUserInfo('v', 'w')
            ->withHost('other.example')
            ->withPort(9000)
            ->withPath('/other')
            ->withQuery('q=other')
            ->withFragment('other')
        ;

        self::assertSame('https', $original->getScheme());
        self::assertSame('u', $original->getUserInfo());
        self::assertSame('orig.example', $original->getHost());
        self::assertSame(8000, $original->getPort());
        self::assertSame('/orig', $original->getPath());
        self::assertSame('q=orig', $original->getQuery());
        self::assertSame('orig', $original->getFragment());

        self::assertSame('http', $modified->getScheme());
        self::assertSame('v:w', $modified->getUserInfo());
        self::assertSame('other.example', $modified->getHost());
        self::assertSame(9000, $modified->getPort());
        self::assertSame('/other', $modified->getPath());
        self::assertSame('q=other', $modified->getQuery());
        self::assertSame('other', $modified->getFragment());
    }

    // ------------------------------------------------------------------
    // Stream: lifecycle after close/detach and mode-driven capabilities
    // ------------------------------------------------------------------

    public function testClosedStreamReadOperationsThrowAndCapabilitiesCollapse(): void
    {
        $stream = Stream::fromString('payload');
        $stream->close();
        $stream->close(); // idempotent

        self::assertSame('', (string) $stream, 'A closed stream stringifies to an empty string');
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertTrue($stream->eof());
        self::assertNull($stream->getSize());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('seekable'));

        foreach (['tell', 'seek'] as $op) {
            try {
                if ($op === 'tell') {
                    $stream->tell();
                } else {
                    $stream->seek(0);
                }
                self::fail("{$op} on a closed stream must throw.");
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDetachedStreamThrowsOnTellAndLosesCapabilities(): void
    {
        $stream = Stream::fromString('payload');
        $resource = $stream->detach();

        self::assertIsResource($resource);
        self::assertNull($stream->detach(), 'Second detach yields null');

        try {
            $stream->tell();
            self::fail('tell() on a detached stream must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Stream detached.', $e->getMessage());
        }

        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->eof());
    }

    public function testWriteOnlyStreamRejectsReadsAndStringifiesEmpty(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zefwo');
        $resource = fopen($path, 'w');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        self::assertTrue($stream->isWritable());
        self::assertFalse($stream->isReadable(), "mode 'w' must not be readable");
        self::assertSame('', (string) $stream, 'A write-only stream must not stringify its contents');

        try {
            $stream->read(4);
            self::fail('read() on a write-only stream must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Stream is not readable.', $e->getMessage());
        }

        fclose($resource);
        unlink($path); // nosemgrep: php.lang.security.unlink-use
    }

    public function testReadOnlyStreamRejectsWrites(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zefro');
        $resource = fopen($path, 'r');
        self::assertIsResource($resource);
        $stream = new Stream($resource);

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());

        try {
            $stream->write('x');
            self::fail('write() on a read-only stream must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Stream is not writable.', $e->getMessage());
        }

        self::assertSame('', $stream->read(0), 'read(0) returns an empty string');
        fclose($resource);
        unlink($path); // nosemgrep: php.lang.security.unlink-use
    }

    public function testReadStreamLengthBoundaries(): void
    {
        $stream = Stream::fromString('abcdef');

        try {
            $stream->read(-1);
            self::fail('read(-1) must throw.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Length must be non-negative.', $e->getMessage());
        }

        self::assertSame('abc', $stream->read(3));
        self::assertSame('def', $stream->getContents());
        self::assertTrue($stream->eof());
    }

    public function testLimitedStreamAdmitsBodyUpToLimitAndThrowsOneByteOver(): void
    {
        $exact = new LimitedInputStream(Stream::fromString(str_repeat('x', 64)), $this->limitedPolicy(64));
        self::assertSame(str_repeat('x', 64), $exact->getContents());

        $over = new LimitedInputStream(Stream::fromString(str_repeat('x', 65)), $this->limitedPolicy(64));

        try {
            $over->getContents();
            self::fail('Body one byte over the limit must be rejected.');
        } catch (PayloadTooLargeException $e) {
            self::assertSame('Request body exceeds configured size limit.', $e->getMessage());
        }
    }

    public function testLimitedStreamSeekResetsTheObservationCounter(): void
    {
        $limited = new LimitedInputStream(Stream::fromString('abc'), $this->limitedPolicy(3));
        self::assertSame('abc', $limited->getContents());
        $limited->seek(0);
        self::assertSame('abc', $limited->getContents(), 'Re-reads after seek must not double-count observed bytes');
        $limited->rewind();
        self::assertSame('abc', $limited->read(3));
    }

    public function testLimitedStreamCapsReportedSizeAndIsReadOnly(): void
    {
        $limited = new LimitedInputStream(Stream::fromString('abcdefgh'), $this->limitedPolicy(4));

        self::assertSame(4, $limited->getSize(), 'Reported size must be capped at the policy limit');
        self::assertFalse($limited->isWritable());
        self::assertTrue($limited->isReadable());

        try {
            $limited->write('x');
            self::fail('The limited stream is read-only.');
        } catch (\RuntimeException $e) {
            self::assertSame('Limited input stream is read-only.', $e->getMessage());
        }
    }

    public function testLimitedStreamStringifiesEmptyOnOversizeAndBrokenInner(): void
    {
        $tooLarge = new LimitedInputStream(Stream::fromString(str_repeat('x', 33)), $this->limitedPolicy(32));
        self::assertSame('', (string) $tooLarge, 'Oversized body stringifies to empty instead of throwing');

        $broken = new BrokenReadStream(Stream::fromString('inner'));
        self::assertSame('', (string) new LimitedInputStream($broken, $this->limitedPolicy(32)), 'Broken inner streams stringifies to empty');
    }

    public function testLimitedStreamReadBoundaries(): void
    {
        $limited = new LimitedInputStream(Stream::fromString('abcdef'), $this->limitedPolicy(16));

        self::assertSame('', $limited->read(0));

        try {
            $limited->read(-1);
            self::fail('read(-1) must throw.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Length must be non-negative.', $e->getMessage());
        }
        self::assertSame('abc', $limited->read(3));
    }

    public function testMoveToEmptyTargetAndErrorStateAndDoubleMoveGuards(): void
    {
        $file = new UploadedFile(Stream::fromString('x'));

        try {
            $file->moveTo('');
            self::fail('Empty target path must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Target path must not be empty.', $e->getMessage());
        }

        $errored = new UploadedFile(Stream::fromString('x'), null, UPLOAD_ERR_INI_SIZE);

        try {
            $errored->getStream();
            self::fail('getStream on an errored upload must throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('error ' . UPLOAD_ERR_INI_SIZE, $e->getMessage());
        }

        try {
            $errored->moveTo('/tmp/whatever');
            self::fail('moveTo on an errored upload must throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('error code ' . UPLOAD_ERR_INI_SIZE, $e->getMessage());
        }
    }

    public function testMoveToMissingDirectoryFailsClosedWithExactMessage(): void
    {
        $dir = $this->tempDir() . '/does-not-exist';
        $file = new UploadedFile(Stream::fromString('x'));

        try {
            $file->moveTo($dir . '/target');
            self::fail('A missing destination directory must fail the move.');
        } catch (\RuntimeException $e) {
            self::assertSame("Destination directory does not exist: '{$dir}'.", $e->getMessage());
        }
    }

    public function testSuccessfulMoveWritesContentCleansUpAndClosesStream(): void
    {
        $dir = $this->tempDir();
        $stream = Stream::fromString('uploaded-bytes');
        $file = new UploadedFile($stream, 14);
        $target = $dir . '/target.txt';

        $file->moveTo($target);

        self::assertFileExists($target);
        self::assertSame('uploaded-bytes', (string) file_get_contents($target));
        self::assertSame([], glob($dir . '/*.zef-tmp-*'), 'No temp artefacts may survive a successful move');
        self::assertFalse($stream->isReadable(), 'The stream is closed after a successful move');

        try {
            $file->getStream();
            self::fail('getStream after a completed move must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Uploaded file has already been moved.', $e->getMessage());
        }

        try {
            $file->moveTo($dir . '/again');
            self::fail('A second moveTo must throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Uploaded file has already been moved.', $e->getMessage());
        }
    }

    public function testFailedFinalizeLeavesNoTempArtifactsAndRestoresStreamPosition(): void
    {
        $dir = $this->tempDir();
        self::assertTrue(mkdir($dir . '/existing-dir', 0o777, true));
        $stream = Stream::fromString(str_repeat('y', 40));
        $stream->seek(12);
        $file = new UploadedFile($stream);

        try {
            $file->moveTo($dir . '/existing-dir'); // rename onto a directory fails
            self::fail('Renaming onto a directory must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Unable to finalize uploaded file to '{$dir}/existing-dir'", $e->getMessage());
        }

        self::assertSame([], glob($dir . '/existing-dir.zef-tmp-*'), 'Failed finalization must clean up its temp target');
        self::assertTrue($stream->isReadable(), 'The stream stays open after a failed move');
        self::assertSame(12, $stream->tell(), 'The stream position must be restored after a failed move');
    }

    public function testUploadedFileMultiChunkRoundTripAndSizeAccessors(): void
    {
        $dir = $this->tempDir();
        $payload = random_bytes(20 * 8192 + 11); // spans many 8192-byte chunks
        $stream = Stream::fromString($payload);
        $file = new UploadedFile($stream, null, UPLOAD_ERR_OK, 'photo.bin', 'application/octet-stream');

        self::assertSame(strlen($payload), $file->getSize());
        self::assertSame('photo.bin', $file->getClientFilename());
        self::assertSame('application/octet-stream', $file->getClientMediaType());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());

        $file->moveTo($dir . '/big.bin');
        self::assertSame($payload, (string) file_get_contents($dir . '/big.bin'));
    }

    public function testUploadedFileSizeMustNotBeNegative(): void
    {
        try {
            new UploadedFile(Stream::fromString('x'), -1);
            self::fail('A negative size must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Uploaded file size must not be negative.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // TrustedProxyMatcher: CIDR arithmetic edges
    // ------------------------------------------------------------------

    /** @param list<string> $proxies */
    #[DataProvider('provideCidrCases')]
    public function testProxyCidrMatrix(string $ip, array $proxies, bool $expected): void
    {
        assert(array_is_list($proxies));
        self::assertSame($expected, TrustedProxyMatcher::matches($ip, $proxies));
    }

    /** @return iterable<array{string, list<string>, bool}> */
    public static function provideCidrCases(): iterable
    {
        yield ['10.0.0.5', ['10.0.0.0/8'], true];

        yield ['10.0.0.5', ['10.1.0.0/16'], false, 'The masked network prefix must match byte for byte'];

        yield ['10.0.0.5', ['10.0.0.5/32'], true];

        yield ['10.0.0.5', ['10.0.0.6/32'], false];

        yield ['10.0.0.5', ['10.0.0.5/0'], true, 'A /0 prefix matches the whole family'];

        yield ['8.8.8.8', ['10.0.0.5/0'], true];

        yield ['10.0.0.5', ['10.0.0.5/33'], false, 'Prefix beyond the family width must not match'];

        yield ['10.0.0.5', ['10.0.0.5/-1'], false, 'Negative prefixes are skipped as garbage'];

        yield ['10.0.0.5', ['::1'], false, 'Family mismatch must not match'];

        yield ['::1', ['::1/128'], true];

        yield ['::2', ['::1/128'], false];

        yield ['2001:db8::1', ['2001:db8::/32'], true];

        yield ['2001:db9::1', ['2001:db8::/32'], false];

        yield ['2001:db8::1', ['2001:db8::1/129'], false];

        yield [' 10.0.0.5 ', ['10.0.0.0/8'], true, 'The evaluated IP is trimmed'];

        yield ['10.0.0.5', [' 10.0.0.0/8 '], true, 'Proxy entries are trimmed'];

        yield ['not-an-ip', ['not-an-ip'], false, 'Invalid evaluated IP is never trusted'];
    }

    public function testProxyListSkipsGarbageEntriesAndKeepsMatching(): void
    {
        // continue-vs-break: a malformed entry must not stop the scan.
        self::assertTrue(TrustedProxyMatcher::matches('10.0.0.5', ['garbage', '10.0.0.0/8']));
        self::assertTrue(TrustedProxyMatcher::matches('10.0.0.5', ['1.2.3.4/notdigits', '10.0.0.0/8']));
        self::assertTrue(TrustedProxyMatcher::matches('10.0.0.5', ['1.2.3.4/8/9', '10.0.0.0/8']), 'Triple-slash entries are malformed and skipped');
        self::assertTrue(TrustedProxyMatcher::matches('10.0.0.5', ['::1/128', '10.0.0.0/8']), 'A family-mismatched entry must not stop the scan');
        self::assertFalse(TrustedProxyMatcher::matches('10.0.0.5', ['garbage', '192.168.0.0/16']));
    }

    public function testStrongEtagIsIssuedAndIfNoneMatchShortCircuitsTo304(): void
    {
        $middleware = new ETagMiddleware();
        $body = 'conditional-body';
        $etag = $this->etagFor($body);

        $fresh = $middleware->process($this->serverRequest(), $this->handler(new Response(200, [], $body)));
        self::assertSame($etag, $fresh->getHeaderLine('ETag'));

        $conditional = $middleware->process(
            $this->serverRequest(['If-None-Match' => $etag]),
            $this->handler(new Response(200, [], $body)),
        );
        self::assertSame(304, $conditional->getStatusCode());
        self::assertSame('', (string) $conditional->getBody());
        self::assertSame($etag, $conditional->getHeaderLine('ETag'));
    }

    public function testIfNoneMatchGrammarVariants(): void
    {
        $middleware = new ETagMiddleware();
        $body = 'payload';
        $etag = $this->etagFor($body);
        $respond = fn (string $inm): ResponseInterface => $middleware->process(
            $this->serverRequest(['If-None-Match' => $inm]),
            $this->handler(new Response(200, [], $body)),
        );

        self::assertSame(304, $respond(' * ')->getStatusCode(), 'Star matches any validator, even with padding');
        self::assertSame(304, $respond('W/' . $etag)->getStatusCode(), 'Weak comparison matches a strong validator');
        self::assertSame(304, $respond('w/' . $etag)->getStatusCode(), 'Lowercase weak prefix is accepted');
        self::assertSame(304, $respond('"zzz", ' . $etag)->getStatusCode(), 'Comma-separated candidate lists are supported');
        self::assertSame(304, $respond(',  , ' . $etag . ' ,')->getStatusCode(), 'Empty candidates are skipped');
        self::assertSame(200, $respond('"zzz"')->getStatusCode(), 'A non-matching candidate list must not 304');
    }

    public function testConditionalGetAppliesToCaseInsensitiveLowercaseMethod(): void
    {
        $middleware = new ETagMiddleware();
        $request = new LowercaseMethodRequest(
            new ServerRequest('GET', new Uri('http://h.example/x')),
        );

        $response = $middleware->process(
            $request,
            $this->handler(new Response(200, [], 'b')),
        );

        self::assertTrue($response->hasHeader('ETag'), 'A lowercase "get" must still be treated as a conditional-GET method');
    }

    public function testEtagPipelineSkipsNonConditionalResponses(): void
    {
        $middleware = new ETagMiddleware();
        $handler = $this->handler(new Response(201, [], 'created'));
        $response = $middleware->process($this->serverRequest(), $handler);

        self::assertFalse($response->hasHeader('ETag'), 'Non-200 responses must pass through untouched');
    }

    public function testEmptyBodyAndLastModifiedPassThroughWithoutEtag(): void
    {
        $middleware = new ETagMiddleware();
        $response = $middleware->process($this->serverRequest(), $this->handler(new Response(200)));

        self::assertFalse($response->hasHeader('ETag'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testHandlerSuppliedEtagIsNeverOverwritten(): void
    {
        $middleware = new ETagMiddleware();
        $response = $middleware->process(
            $this->serverRequest(),
            $this->handler(new Response(200, ['ETag' => '"handler-etag"'], 'body')),
        );

        self::assertSame('"handler-etag"', $response->getHeaderLine('ETag'));
    }

    public function testIfModifiedSinceAppliesToAnyResponseCarryingLastModified(): void
    {
        $middleware = new ETagMiddleware();
        $lastModified = 'Mon, 01 Jan 2024 10:00:00 GMT';

        // Regresi P-13 (issue #171): a body-carrying response with
        // Last-Modified must 304 an IMS-only request — the old `$etag === ''`
        // guard kept this path dead and the client always paid the full 200.
        $withBody = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => $lastModified]),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified], 'b')),
        );
        self::assertSame(304, $withBody->getStatusCode(), 'IMS equal to Last-Modified must 304 even when a body is present');
        self::assertTrue($withBody->hasHeader('ETag'), 'the computed validator travels with the 304');
        self::assertSame('', (string) $withBody->getBody());

        // Body present but changed since the client's copy → full 200.
        $stale = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => 'Mon, 01 Jan 2024 09:59:59 GMT']),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified], 'b')),
        );
        self::assertSame(200, $stale->getStatusCode());
        self::assertTrue($stale->hasHeader('ETag'));

        // Empty body + Last-Modified → IMS equal to Last-Modified must 304 (≤ semantics).
        $equal = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => $lastModified]),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified])),
        );
        self::assertSame(304, $equal->getStatusCode(), 'Equal timestamps must be treated as not modified');
        self::assertFalse($equal->hasHeader('ETag'));

        // Client newer than server → 304; client older → 200.
        $newer = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => 'Mon, 01 Jan 2024 10:00:01 GMT']),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified])),
        );
        self::assertSame(304, $newer->getStatusCode());

        $older = $middleware->process(
            $this->serverRequest(['If-Modified-Since' => 'Mon, 01 Jan 2024 09:59:59 GMT']),
            $this->handler(new Response(200, ['Last-Modified' => $lastModified])),
        );
        self::assertSame(200, $older->getStatusCode());
    }

    public function testMalformedIfModifiedSinceIsIgnoredNotFatal(): void
    {
        $middleware = new ETagMiddleware();
        $lastModified = 'Mon, 01 Jan 2024 10:00:00 GMT';

        foreach (['not-a-date', 'Mon, 01 Jan 2024 10:00:00 GMT junk', 'Mon, 32 Jan 2024 10:00:00 GMT'] as $bad) {
            $response = $middleware->process(
                $this->serverRequest(['If-Modified-Since' => $bad]),
                $this->handler(new Response(200, ['Last-Modified' => $lastModified])),
            );
            self::assertSame(200, $response->getStatusCode(), "Invalid IMS '{$bad}' must be treated as no precondition");
        }

        self::assertFalse(ETagMiddleware::notModifiedSince('not-a-date', $lastModified));
        self::assertFalse(ETagMiddleware::notModifiedSince($lastModified, 'not-a-date'));
    }

    public function testIfNoneMatchMatcherUnitEdges(): void
    {
        self::assertTrue(ETagMiddleware::ifNoneMatchMatches('*', '"a"'));
        self::assertTrue(ETagMiddleware::ifNoneMatchMatches('"a"', '"a"'));
        self::assertTrue(ETagMiddleware::ifNoneMatchMatches('W/"a"', '"a"'));
        self::assertFalse(ETagMiddleware::ifNoneMatchMatches('"b"', '"a"'));
        self::assertTrue(ETagMiddleware::ifNoneMatchMatches('  "a"  ', '"a"'), 'Whitespace around candidates is trimmed');
    }

    // ------------------------------------------------------------------
    // ApiVersionNegotiator: token grammar + priority chain
    // ------------------------------------------------------------------

    public function testNegotiatorConstructorGuards(): void
    {
        try {
            new ApiVersionNegotiator([]);
            self::fail('An empty supported list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('ApiVersionNegotiator requires at least one supported version.', $e->getMessage());
        }

        try {
            // The validator must reject non-string entries at runtime.
            // @phpstan-ignore-next-line (intentional type violation under test)
            new ApiVersionNegotiator(['1', ['nested']]);
            self::fail('A non-scalar version must be rejected with a debug-type message.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Supported versions must match [A-Za-z0-9._-]{1,16}, got: array', $e->getMessage());
        }

        try {
            // @phpstan-ignore-next-line (intentional type violation under test)
            new ApiVersionNegotiator(['1', 5]);
            self::fail('A scalar-but-non-string version must be rejected with its string form.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Supported versions must match [A-Za-z0-9._-]{1,16}, got: 5', $e->getMessage());
        }

        try {
            new ApiVersionNegotiator(['1', 'abcdefgh012345678']); // 17 bytes
            self::fail('An oversized token must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Supported versions must match [A-Za-z0-9._-]{1,16}, got: abcdefgh012345678', $e->getMessage());
        }

        try {
            new ApiVersionNegotiator(['1'], default: '9');
            self::fail('A default outside the supported list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Default version '9' must be part of the supported list.", $e->getMessage());
        }
    }

    public function testNegotiationPriorityPathThenHeaderThenQueryThenDefault(): void
    {
        $negotiator = new ApiVersionNegotiator(['1', '2', 'beta.1'], default: '1');

        $viaPath = $negotiator->negotiate('/v2/users');
        self::assertSame('2', $viaPath->version);
        self::assertSame(ApiVersion::SOURCE_PATH, $viaPath->source);

        $viaHeader = $negotiator->negotiate('/users', headerValue: ' beta.1 ');
        self::assertSame('beta.1', $viaHeader->version);
        self::assertSame(ApiVersion::SOURCE_HEADER, $viaHeader->source);

        $viaQuery = $negotiator->negotiate('/users', queryValue: '2');
        self::assertSame('2', $viaQuery->version);
        self::assertSame(ApiVersion::SOURCE_QUERY, $viaQuery->source);

        $viaDefault = $negotiator->negotiate('/users');
        self::assertSame('1', $viaDefault->version);
        self::assertSame(ApiVersion::SOURCE_DEFAULT, $viaDefault->source);
    }

    public function testNegotiatorUnsupportedTokenMessageContainsTheFullList(): void
    {
        $negotiator = new ApiVersionNegotiator(['1', '2']);

        try {
            $negotiator->negotiate('/v9/users');
            self::fail('An unsupported path version must raise the dedicated exception.');
        } catch (ApiVersionUnsupportedException $e) {
            self::assertSame("API version '9' is not supported. Supported versions: 1, 2.", $e->getMessage());
        }

        try {
            $negotiator->negotiate('/users', headerValue: 'v3');
            self::fail('An unsupported header token must raise the same outcome.');
        } catch (ApiVersionUnsupportedException $e) {
            self::assertSame("API version 'v3' is not supported. Supported versions: 1, 2.", $e->getMessage());
        }
    }

    public function testNegotiatorWithoutAnyInputAndWithoutDefaultExplainsItself(): void
    {
        $negotiator = new ApiVersionNegotiator(['1'], headerName: 'X-Version', queryKey: 'ver');

        try {
            $negotiator->negotiate('/users');
            self::fail('Missing version without default must raise the dedicated exception.');
        } catch (ApiVersionUnsupportedException $e) {
            self::assertSame(
                'No API version provided (path /v{n} prefix, X-Version header or ?ver= query).',
                $e->getMessage(),
            );
        }
    }

    public function testPathPrefixBoundarySixteenByteTokenSplitsExactly(): void
    {
        $negotiator = new ApiVersionNegotiator(['abcdefgh0123456']); // exactly 16 bytes

        [$token, $rest] = $negotiator->splitPathPrefix('/vabcdefgh0123456/users');
        self::assertSame('abcdefgh0123456', $token);
        self::assertSame('/users', $rest);

        [$token] = $negotiator->splitPathPrefix('/vabcdefgh0123456');
        self::assertSame('abcdefgh0123456', $token);

        [$token, $rest] = $negotiator->splitPathPrefix('/plain');
        self::assertNull($token);
        self::assertSame('/plain', $rest);

        // 17-byte token in the path cannot match the bounded regex.
        [$token] = $negotiator->splitPathPrefix('/vabcdefgh012345678/users');
        self::assertNull($token);
    }

    public function testOversizedHeaderAndQueryTokensAreDroppedSilently(): void
    {
        $negotiator = new ApiVersionNegotiator(['1'], default: '1');

        $longHeader = str_repeat('a', 257);
        $version = $negotiator->negotiate('/users', headerValue: $longHeader, queryValue: '1');
        self::assertSame(ApiVersion::SOURCE_QUERY, $version->source, 'A >256-byte header must be dropped, not fatal');

        $longQuery = str_repeat('b', 65);
        $version = $negotiator->negotiate('/users', queryValue: $longQuery);
        self::assertSame(ApiVersion::SOURCE_DEFAULT, $version->source, 'A >64-byte query token must be dropped');

        $invalid = $negotiator->negotiate('/users', headerValue: 'not valid!');
        self::assertSame(ApiVersion::SOURCE_DEFAULT, $version->source, 'Grammar-violating tokens are dropped');
        self::assertSame('1', $invalid->version);
    }

    public function testSupportedVersionsReturnsTheOriginalTokenList(): void
    {
        $negotiator = new ApiVersionNegotiator(['2', '1']);

        self::assertSame(['2', '1'], $negotiator->supportedVersions());
    }

    // ------------------------------------------------------------------
    // Psr17Factory: creation guards
    // ------------------------------------------------------------------

    public function testFactoryCreateResponseDefaultsTo200(): void
    {
        $factory = new Psr17Factory();

        self::assertSame(200, $factory->createResponse()->getStatusCode());
        self::assertSame(404, $factory->createResponse(404)->getStatusCode());
        self::assertSame('OK', $factory->createResponse(200, 'OK')->getReasonPhrase());
    }

    public function testFactoryStreamFileGuards(): void
    {
        $factory = new Psr17Factory();

        try {
            $factory->createStreamFromFile('');
            self::fail('An empty filename must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Filename must not be empty.', $e->getMessage());
        }

        foreach (['zz', 'r!', 'q+r', 'wbx'] as $badMode) {
            try {
                $factory->createStreamFromFile('/tmp/whatever', $badMode);
                self::fail("Mode '{$badMode}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame("Invalid stream mode '{$badMode}'.", $e->getMessage());
            }
        }

        try {
            $factory->createStreamFromFile(sys_get_temp_dir() . '/zef-definitely-missing-' . bin2hex(random_bytes(4)));
            self::fail('A missing file must raise a runtime error.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unable to open', $e->getMessage());
            self::assertStringContainsString('No such file or directory', $e->getMessage(), 'The underlying warning text is preserved');
        }
    }

    public function testFactoryStreamFileHappyPathAndResourceGuards(): void
    {
        $factory = new Psr17Factory();
        $path = (string) tempnam(sys_get_temp_dir(), 'zefsf');
        file_put_contents($path, 'from-file');

        $stream = $factory->createStreamFromFile($path);
        self::assertSame('from-file', (string) $stream);
        $detached = $stream->detach();
        assert(is_resource($detached));
        fclose($detached);
        unlink($path); // nosemgrep: php.lang.security.unlink-use

        try {
            // @phpstan-ignore-next-line (intentional type violation under test)
            $factory->createStreamFromResource('not-a-resource');
            self::fail('A non-resource must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Resource required.', $e->getMessage());
        }

        $resource = fopen((string) tempnam(sys_get_temp_dir(), 'zefwo'), 'w');
        self::assertIsResource($resource);

        try {
            $factory->createStreamFromResource($resource);
            self::fail('A write-only resource must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Resource must be readable.', $e->getMessage());
        }
        fclose($resource);
    }

    public function testFactoryUploadedFileGuards(): void
    {
        $factory = new Psr17Factory();

        $closed = Stream::fromString('x');
        $closed->close();

        try {
            $factory->createUploadedFile($closed);
            self::fail('A closed upload stream must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Uploaded file stream must be readable.', $e->getMessage());
        }

        try {
            $factory->createUploadedFile(Stream::fromString('x'), -5);
            self::fail('A negative size must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Uploaded file size must not be negative.', $e->getMessage());
        }

        $upload = $factory->createUploadedFile(Stream::fromString('abc'), 3, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        self::assertSame(3, $upload->getSize());
        self::assertSame('a.txt', $upload->getClientFilename());
    }

    public function testFactoryRequestAndServerRequestWiring(): void
    {
        $factory = new Psr17Factory();

        $request = $factory->createRequest('GET', 'http://h.example/x');
        self::assertSame('h.example', $request->getUri()->getHost());

        $server = $factory->createServerRequest('POST', 'http://h.example/y', ['REMOTE_ADDR' => '10.0.0.1']);
        self::assertSame('10.0.0.1', $server->getServerParams()['REMOTE_ADDR']);

        $withUri = $factory->createRequest('GET', new Uri('http://h.example/z'));
        self::assertSame('/z', $withUri->getUri()->getPath());
    }

    // ------------------------------------------------------------------
    // ServerRequest + MessageBase: header/immutability contracts
    // ------------------------------------------------------------------

    public function testExplicitHostHeaderWinsOverDerivedUriHost(): void
    {
        $request = new ServerRequest(
            'GET',
            new Uri('http://derived.example/path'),
            headers: ['Host' => 'explicit.example'],
        );

        self::assertSame('explicit.example', $request->getHeaderLine('Host'));
        self::assertFalse($request->hasHeader('X-Nothing'));
    }

    public function testUriWithoutHostProducesNoHostHeader(): void
    {
        $request = new ServerRequest('GET', new Uri('/path/only'));

        self::assertFalse($request->hasHeader('Host'));
    }

    public function testServerRequestTreeAndBodyMutatorsAreImmutable(): void
    {
        $upload = new UploadedFile(Stream::fromString('x'));
        $request = new ServerRequest('GET', new Uri('http://h.example/'));

        $withCookies = $request->withCookieParams(['sid' => '1']);
        self::assertSame(['sid' => '1'], $withCookies->getCookieParams());
        self::assertSame([], $request->getCookieParams());

        $withQuery = $request->withQueryParams(['q' => 'z']);
        self::assertSame(['q' => 'z'], $withQuery->getQueryParams());
        self::assertSame([], $request->getQueryParams());

        $withUploads = $request->withUploadedFiles(['f' => ['nested' => $upload]]);
        $tree = $withUploads->getUploadedFiles();
        $branch = $tree['f'] ?? null;
        assert(is_array($branch));
        self::assertSame($upload, $branch['nested'] ?? null);
        self::assertSame([], $request->getUploadedFiles());

        $withBody = $request->withParsedBody(['a' => 1]);
        self::assertSame(['a' => 1], $withBody->getParsedBody());
        self::assertNull($request->getParsedBody());

        $object = new \stdClass();
        self::assertSame($object, $request->withParsedBody($object)->getParsedBody());

        try {
            $request->withParsedBody('string-not-allowed');
            self::fail('A scalar parsed body must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Parsed body must be array, object or null.', $e->getMessage());
        }
    }

    public function testUploadedTreeRejectsNonLeafValuesAtAnyDepth(): void
    {
        $upload = new UploadedFile(Stream::fromString('x'));
        $request = new ServerRequest('GET', new Uri('http://h.example/'));

        $valid = $request->withUploadedFiles(['a' => ['b' => $upload]]);
        $tree = $valid->getUploadedFiles();
        $branch = $tree['a'] ?? null;
        assert(is_array($branch));
        self::assertSame($upload, $branch['b'] ?? null);

        foreach ([
            ['a' => $upload, 'junk-after-valid-leaf' => 'not-an-upload'],
            ['nested-valid' => ['b' => $upload], 'junk-after-nested' => 42],
        ] as $badTree) {
            try {
                $request->withUploadedFiles($badTree);
                self::fail('A non-upload leaf anywhere must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Uploaded files must contain only UploadedFileInterface leaves.', $e->getMessage());
            }
        }
    }

    public function testAttributeMutatorsMergeOverwriteAndRemove(): void
    {
        $request = new ServerRequest('GET', new Uri('http://h.example/'));

        $withA = $request->withAttribute('a', 1)->withAttribute('b', 2);
        self::assertSame(['a' => 1, 'b' => 2], $withA->getAttributes());
        self::assertSame(1, $withA->getAttribute('a'));
        self::assertSame('fallback', $withA->getAttribute('missing', 'fallback'));

        $overwritten = $withA->withAttribute('a', 10);
        self::assertSame(10, $overwritten->getAttribute('a'), 'Re-adding an attribute must overwrite it');
        self::assertSame(1, $withA->getAttribute('a'), 'The original instance is untouched');

        $removed = $withA->withoutAttribute('a');
        self::assertSame(['b' => 2], $removed->getAttributes());
        self::assertSame(['a' => 1, 'b' => 2], $withA->getAttributes(), 'withoutAttribute must not mutate the receiver');
    }

    public function testHeaderContractsReplaceAppendRemoveCaseInsensitive(): void
    {
        $message = new class(null, ['X-First' => 'one']) extends MessageBase {};

        // withHeader replaces any prior value (case-insensitive lookup).
        $replaced = $message->withHeader('x-first', 'two');
        self::assertSame('two', $replaced->getHeaderLine('X-First'));
        self::assertSame(['two'], $replaced->getHeader('x-FIRST'));

        // withAddedHeader appends to the value list.
        $appended = $replaced->withAddedHeader('X-First', 'three');
        self::assertSame('two, three', $appended->getHeaderLine('X-First'));
        self::assertSame(['two', 'three'], $appended->getHeader('X-First'));

        // Array values are stored as separate list entries.
        $multi = $message->withHeader('X-Multi', ['a', 'b']);
        self::assertSame(['a', 'b'], $multi->getHeader('X-Multi'));

        // withoutHeader removes the pair; unknown headers are a no-op.
        $removed = $multi->withoutHeader('x-MULTI');
        self::assertFalse($removed->hasHeader('X-Multi'));
        self::assertSame([], $removed->getHeader('X-Multi'));
        self::assertSame([], $multi->withoutHeader('X-Absent')->getHeader('X-Absent'));

        // The receiver is never mutated.
        self::assertSame(['one'], $message->getHeader('X-First'));
    }

    public function testProtocolVersionGuards(): void
    {
        $message = new class extends MessageBase {};
        $upgraded = $message->withProtocolVersion('2');

        self::assertSame('1.1', $message->getProtocolVersion());
        self::assertSame('2', $upgraded->getProtocolVersion());

        try {
            $message->withProtocolVersion('9.9.x');
            self::fail('An invalid protocol version must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid HTTP protocol version.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // LimitedInputStream: body-size admission control
    // ------------------------------------------------------------------

    private function limitedPolicy(int $max): RequestBodyPolicy
    {
        return new RequestBodyPolicy($max);
    }

    // ------------------------------------------------------------------
    // UploadedFile: atomic move lifecycle
    // ------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/zef-edge-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0o777, true));

        return $dir;
    }

    // ------------------------------------------------------------------
    // ETagMiddleware: conditional GET grammar (RFC 9110 §13)
    // ------------------------------------------------------------------

    private function etagFor(string $body): string
    {
        return '"' . bin2hex(hash('sha256', $body, true)) . '"';
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

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

/**
 * Fixture: a foreign ServerRequestInterface implementation whose getMethod()
 * returns a lowercase token — middleware must not assume canonical casing.
 */
final class LowercaseMethodRequest implements ServerRequestInterface
{
    public function __construct(private readonly ServerRequestInterface $inner) {}

    #[\Override]
    public function getMethod(): string
    {
        return 'get';
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->inner->getRequestTarget();
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): static
    {
        $next = $this->inner->withRequestTarget($requestTarget);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function withMethod(string $method): static
    {
        $next = $this->inner->withMethod($method);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        return $this->inner->getUri();
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $next = $this->inner->withUri($uri, $preserveHost);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        $next = $this->inner->withProtocolVersion($version);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->inner->hasHeader($name);
    }

    /** @return array<mixed> */
    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->inner->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return $this->inner->getHeaderLine($name);
    }

    /** @return array<mixed> */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->inner->getHeaders();
    }

    /** @param mixed $value */
    #[\Override]
    public function withHeader(string $name, $value): static
    {
        $next = $this->inner->withHeader($name, $value);
        assert($next instanceof self);

        return $next;
    }

    /** @param mixed $value */
    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        $next = $this->inner->withAddedHeader($name, $value);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        $next = $this->inner->withoutHeader($name);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        $next = $this->inner->withBody($body);
        assert($next instanceof self);

        return $next;
    }

    /** @return array<mixed> */
    #[\Override]
    public function getServerParams(): array
    {
        return $this->inner->getServerParams();
    }

    /** @return array<mixed> */
    #[\Override]
    public function getCookieParams(): array
    {
        return $this->inner->getCookieParams();
    }

    /** @param array<mixed> $cookies */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        $next = $this->inner->withCookieParams($cookies);
        assert($next instanceof self);

        return $next;
    }

    /** @return array<mixed> */
    #[\Override]
    public function getQueryParams(): array
    {
        return $this->inner->getQueryParams();
    }

    /** @param array<mixed> $query */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        $next = $this->inner->withQueryParams($query);
        assert($next instanceof self);

        return $next;
    }

    /** @return array<mixed> */
    #[\Override]
    public function getUploadedFiles(): array
    {
        return $this->inner->getUploadedFiles();
    }

    /** @param array<mixed> $uploadedFiles */
    #[\Override]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $next = $this->inner->withUploadedFiles($uploadedFiles);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function getParsedBody(): mixed
    {
        return $this->inner->getParsedBody();
    }

    /** @param mixed $data */
    #[\Override]
    public function withParsedBody($data): static
    {
        $next = $this->inner->withParsedBody($data);
        assert($next instanceof self);

        return $next;
    }

    /** @return array<mixed> */
    #[\Override]
    public function getAttributes(): array
    {
        return $this->inner->getAttributes();
    }

    #[\Override]
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->inner->getAttribute($name, $default);
    }

    #[\Override]
    public function withAttribute(string $name, mixed $value): static
    {
        $next = $this->inner->withAttribute($name, $value);
        assert($next instanceof self);

        return $next;
    }

    #[\Override]
    public function withoutAttribute(string $name): static
    {
        $next = $this->inner->withoutAttribute($name);
        assert($next instanceof self);

        return $next;
    }
}

/**
 * Fixture: an inner stream whose read() fails at the transport level — the
 * limited wrapper must degrade gracefully instead of bubbling the fault.
 */
final class BrokenReadStream implements StreamInterface
{
    public function __construct(private readonly Stream $inner) {}

    #[\Override]
    public function __toString(): string
    {
        return (string) $this->inner;
    }

    #[\Override]
    public function read(int $length): string
    {
        throw new \RuntimeException('inner boom');
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[\Override]
    public function detach(): mixed
    {
        return $this->inner->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->inner->rewind();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Broken stream is read-only.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function getContents(): string
    {
        throw new \RuntimeException('inner boom');
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
