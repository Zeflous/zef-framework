<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.30.0 Ecosystem Ports: object storage (Local +
 * S3-compatible with in-house SigV4), the in-memory message transport and
 * the durable PDO job queue / idempotency store.
 *
 * Everything is deterministic: the S3 suite runs against a recording fake
 * transport (assertions pin URLs, signed header sets and response-to-
 * exception mapping, plus the two published AWS SigV4 vectors), the PDO
 * adapters run against in-memory SQLite, and the key grammar is pinned
 * char-by-char so a mutant in validation can never reach a backend.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobExecutionException;
use Zef\Framework\Job\PdoJobIdempotencyStore;
use Zef\Framework\Job\PdoJobQueue;
use Zef\Framework\Message\InMemoryMessageTransport;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\ReceivedMessage;
use Zef\Framework\Storage\CurlS3HttpTransport;
use Zef\Framework\Storage\LocalStorage;
use Zef\Framework\Storage\ObjectNotFoundException;
use Zef\Framework\Storage\ObjectStat;
use Zef\Framework\Storage\S3CompatibleStorage;
use Zef\Framework\Storage\S3HttpResponse;
use Zef\Framework\Storage\ShadowCurlState;
use Zef\Framework\Storage\SigV4;
use Zef\Framework\Storage\StorageException;
use Zef\Framework\Storage\StorageKeys;

/**
 * @internal
 */
final class EcosystemPortsV30Test extends TestCase
{
    // ------------------------------------------------------------------
    // PdoJobQueue (in-memory SQLite)
    // ------------------------------------------------------------------

    private const int NANO = 1_700_000_000_000_000_000;

    // ------------------------------------------------------------------
    // CurlS3HttpTransport — namespace shadow of curl_* (deterministic)
    // ------------------------------------------------------------------

    private static bool $shadowLoaded = false;

    private string $tmpBase;

    private string $rootPath;

    protected function tearDown(): void
    {
        if (isset($this->tmpBase) && is_dir($this->tmpBase)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) {
                    continue;
                }
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use (test sandbox, per doctor-test convention)
                }
            }
            @rmdir($this->tmpBase);
        }
    }

    public function testCurlTransportRequiresHttpMethod(): void
    {
        $transport = new CurlS3HttpTransport();

        try {
            $transport->request('', 'https://example.com', [], '');
            self::fail('Empty method accepted.');
        } catch (StorageException $error) {
            self::assertSame('Object storage transport requires an HTTP method.', $error->getMessage());
        }
    }

    public function testCurlTransportMapsMissingExtensionToStorageException(): void
    {
        require_once __DIR__ . '/ShadowCurl.php';
        ShadowCurlState::$enabled = false; // mimics a host without ext-curl
        $transport = new CurlS3HttpTransport();

        try {
            $transport->request('GET', 'https://example.com/k', [], '');
            self::fail('Missing ext-curl must map to StorageException.');
        } catch (StorageException $error) {
            self::assertSame('The cURL extension is required by the S3-compatible storage transport.', $error->getMessage());
        }
    }

    public function testCurlTransportMapsInitFailure(): void
    {
        $this->loadShadow();
        ShadowCurlState::$initReturnsFalse = true;
        $transport = new CurlS3HttpTransport();

        try {
            $transport->request('GET', 'https://example.com/k', [], '');
            self::fail('curl_init false must map to StorageException.');
        } catch (StorageException $error) {
            self::assertSame('Unable to initialise the cURL handle for object storage.', $error->getMessage());
        }
    }

    public function testCurlTransportMapsExecFailureWithCurlError(): void
    {
        $this->loadShadow();
        ShadowCurlState::$execResult = false;
        $transport = new CurlS3HttpTransport();

        try {
            $transport->request('GET', 'https://example.com/k', [], '');
            self::fail('curl_exec false must map to StorageException.');
        } catch (StorageException $error) {
            self::assertSame('Object storage transport error: boom-shadow', $error->getMessage());
        }
    }

    public function testCurlTransportRejectsNonStringBody(): void
    {
        $this->loadShadow();
        ShadowCurlState::$execResult = true; // e.g. HEAD: no body returned
        $transport = new CurlS3HttpTransport();
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('returned a non-string body');
        $transport->request('HEAD', 'https://example.com/k', [], '');
    }

    public function testCurlTransportSuccessParsesStatusHeadersAndBody(): void
    {
        $this->loadShadow();
        ShadowCurlState::$execResult = 'the-body';
        ShadowCurlState::$responseCode = 207;
        $transport = new CurlS3HttpTransport();
        $response = $transport->request('PUT', 'https://example.com/bucket/k', ['X-Test' => '1'], 'payload');
        self::assertSame(207, $response->status);
        self::assertSame('the-body', $response->body);
        self::assertSame('12', $response->header('content-length'));
        self::assertSame('Wed, 01 Mar 2023 12:00:00 GMT', $response->header('LAST-MODIFIED'));
        // shadow captured options: pinned exactly
        self::assertSame('PUT', ShadowCurlState::$opts[CURLOPT_CUSTOMREQUEST]);
        self::assertTrue(ShadowCurlState::$opts[CURLOPT_RETURNTRANSFER]);
        self::assertFalse(ShadowCurlState::$opts[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(5, ShadowCurlState::$opts[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(30, ShadowCurlState::$opts[CURLOPT_TIMEOUT]);
        self::assertSame(['X-Test: 1'], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);
        self::assertSame('payload', ShadowCurlState::$opts[CURLOPT_POSTFIELDS]);
        self::assertIsCallable(ShadowCurlState::$opts[CURLOPT_HEADERFUNCTION]);
        // the header function returned byte counts and fed the raw headers
        self::assertSame([17, 20, 46, 2], ShadowCurlState::$headerReturns);
    }

    public function testCurlTransportOmitsPostfieldsForEmptyBody(): void
    {
        $this->loadShadow();
        ShadowCurlState::$execResult = '';
        $transport = new CurlS3HttpTransport();
        $transport->request('DELETE', 'https://example.com/bucket/k', [], '');
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, ShadowCurlState::$opts);
    }

    public function testCurlHeaderLinesAndParseHeadersArePure(): void
    {
        self::assertSame(['A: 1', 'B: 2'], CurlS3HttpTransport::headerLines(['A' => '1', 'B' => '2']));
        self::assertSame([], CurlS3HttpTransport::headerLines([]));
        $parsed = CurlS3HttpTransport::parseHeaders(["HTTP/1.1 404 Not Found\r\n", "Content-Length: 3\r\n", "X-Multi: a\r\n", "X-Multi: b\r\n", "\r\n", 'nocolon']);
        self::assertSame(['3'], $parsed['content-length']);
        self::assertSame(['a', 'b'], $parsed['x-multi']);
        self::assertArrayNotHasKey('http/1.1 404 not found', $parsed);
    }

    // ------------------------------------------------------------------
    // LocalStorage — hostile fixtures + exact messages
    // ------------------------------------------------------------------

    public function testLocalStorageRootIsAFile(): void
    {
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        $file = $base . '/blocker';
        mkdir($base, 0o777, true);
        file_put_contents($file, 'x');

        try {
            new LocalStorage($file);
            self::fail('Root-as-file accepted.');
        } catch (StorageException $error) {
            self::assertSame("Unable to create storage root '{$file}'.", $error->getMessage());
        } finally {
            @unlink($file); // nosemgrep: php.lang.security.unlink-use
            @rmdir($base);
        }
    }

    public function testLocalStorageMkdirFailureInSubdirectory(): void
    {
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        mkdir($base . '/store', 0o777, true);
        file_put_contents($base . '/store/blocked', 'x'); // a FILE where a directory is needed
        $storage = new LocalStorage($base . '/store');
        // LocalStorage canonicalises its root through realpath(), which on
        // Windows expands 8.3 short names (RUNNER~1 -> runneradmin) and
        // returns backslash separators — the expected message must be built
        // from the same canonical form (issue #110).
        $canonicalRoot = (string) realpath($base . '/store');

        try {
            $storage->put('blocked/nested.txt', 'v');
            self::fail('mkdir over a file must fail.');
        } catch (StorageException $error) {
            self::assertSame("Unable to create object directory '{$canonicalRoot}/blocked'.", $error->getMessage());
        } finally {
            @unlink($base . '/store/blocked'); // nosemgrep: php.lang.security.unlink-use
            @rmdir($base . '/store');
            @rmdir($base);
        }
    }

    public function testLocalStorageWriteFailureIsMapped(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // chmod-based failure injection is a POSIX contract (issue #110):
            // the test exercises LocalStorage's ERROR MAPPING, not the happy
            // path, and Windows' ACL emulation does not honor 0o555 on a
            // directory as "tmp write fails".
            self::markTestSkipped('chmod failure injection is POSIX-only.');
        }
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        mkdir($base . '/store', 0o777, true);
        chmod($base . '/store', 0o555); // read-only directory: tmp write fails
        $storage = new LocalStorage($base . '/store');

        try {
            $storage->put('locked.txt', 'v');
            self::fail('Unwritable directory must fail the write.');
        } catch (StorageException $error) {
            self::assertSame("Failed to write object 'locked.txt'.", $error->getMessage());
        } finally {
            chmod($base . '/store', 0o777);
            @rmdir($base . '/store');
            @rmdir($base);
        }
    }

    public function testLocalStorageRenameFailureIsMapped(): void
    {
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        mkdir($base . '/store', 0o777, true);
        mkdir($base . '/store/target.txt', 0o777, true); // DIRECTORY as object target
        $storage = new LocalStorage($base . '/store');

        try {
            $storage->put('target.txt', 'v');
            self::fail('rename onto a directory must fail.');
        } catch (StorageException $error) {
            self::assertSame("Failed to persist object 'target.txt'.", $error->getMessage());
        } finally {
            @rmdir($base . '/store/target.txt');
            @rmdir($base . '/store');
            @rmdir($base);
        }
    }

    public function testLocalStorageReadFailureIsMapped(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // chmod-based failure injection is a POSIX contract (issue #110).
            self::markTestSkipped('chmod failure injection is POSIX-only.');
        }
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        mkdir($base . '/store', 0o777, true);
        file_put_contents($base . '/store/secret.txt', 'v');
        chmod($base . '/store/secret.txt', 0o000); // unreadable file
        $storage = new LocalStorage($base . '/store');

        try {
            $storage->get('secret.txt');
            self::fail('Unreadable file must fail the read.');
        } catch (StorageException $error) {
            self::assertSame("Failed to read object 'secret.txt'.", $error->getMessage());
        } finally {
            chmod($base . '/store/secret.txt', 0o644);
            @unlink($base . '/store/secret.txt'); // nosemgrep: php.lang.security.unlink-use
            @rmdir($base . '/store');
            @rmdir($base);
        }
    }

    public function testLocalStorageDeleteFailureIsMapped(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            // chmod-based failure injection is a POSIX contract (issue #110).
            self::markTestSkipped('chmod failure injection is POSIX-only.');
        }
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        mkdir($base . '/store', 0o777, true);
        file_put_contents($base . '/store/stuck.txt', 'v');
        chmod($base . '/store', 0o555); // directory not writable: unlink fails
        $storage = new LocalStorage($base . '/store');

        try {
            $storage->delete('stuck.txt');
            self::fail('Unlink in a read-only directory must fail.');
        } catch (StorageException $error) {
            self::assertSame("Failed to delete object 'stuck.txt'.", $error->getMessage());
        } finally {
            chmod($base . '/store', 0o777);
            @unlink($base . '/store/stuck.txt'); // nosemgrep: php.lang.security.unlink-use
            @rmdir($base . '/store');
            @rmdir($base);
        }
    }

    // ------------------------------------------------------------------
    // S3CompatibleStorage — ctor boundaries, exact messages, pins
    // ------------------------------------------------------------------

    public function testS3CtorBoundariesAndExactMessages(): void
    {
        $t = new FakeS3Transport();
        $this->assertThrowsWithMessage(
            'Bucket name must be a valid S3 bucket (3..63 lowercase chars).',
            fn (): bool => $this->s3Ctor($t, 'ZEF-BUCKET', 'r', 'k', 's'),
        );
        $this->assertThrowsWithMessage(
            'Bucket name must be a valid S3 bucket (3..63 lowercase chars).',
            fn (): bool => $this->s3Ctor($t, 'ab', 'r', 'k', 's'),
        );
        $this->assertThrowsWithMessage(
            'Region must be 1..64 characters without whitespace.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', '', 'k', 's'),
        );
        $this->assertThrowsWithMessage(
            'Region must be 1..64 characters without whitespace.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', str_repeat('r', 65), 'k', 's'),
        );
        $this->s3Ctor($t, 'zef-bucket', str_repeat('r', 64), str_repeat('k', 256), str_repeat('s', 256));
        $this->addToAssertionCount(1);
        $this->assertThrowsWithMessage(
            'Access key ID must be 1..256 bytes.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', 'r', str_repeat('k', 257), 's'),
        );
        $this->assertThrowsWithMessage(
            'Secret access key must be 1..256 bytes.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', 'r', 'k', str_repeat('s', 257)),
        );
        $this->assertThrowsWithMessage(
            'Endpoint must be an absolute http(s) URL.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', 'r', 'k', 's', 'not a url'),
        );
        $this->assertThrowsWithMessage(
            'Endpoint must be an absolute http(s) URL.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', 'r', 'k', 's', 'ftp://x.example.com'),
        );
        $this->assertThrowsWithMessage(
            'Endpoint must not carry a path component.',
            fn (): bool => $this->s3Ctor($t, 'zef-bucket', 'r', 'k', 's', 'http://host.example.com/base'),
        );
    }

    public function testS3LowercasesEndpointHostHeader(): void
    {
        $transport = new FakeS3Transport();
        $storage = new S3CompatibleStorage('zef-bucket', 'r', 'k', 's', $transport, 'http://MiNiO.Local:9000');
        $storage->get('k');
        $request = $transport->single();
        self::assertSame('minio.local:9000', $request['headers']['host']);
    }

    public function testS3RejectsInvalidKeysBeforeAnyTransportCall(): void
    {
        $transport = new FakeS3Transport();
        $storage = $this->s3($transport);
        $expected = 'Storage key contains an invalid path segment (..).';

        $this->assertThrowsWithMessage($expected, function () use ($storage): void { $storage->put('../evil.txt', 'v'); });
        $this->assertThrowsWithMessage($expected, function () use ($storage): void { $storage->get('../evil.txt'); });
        $this->assertThrowsWithMessage($expected, function () use ($storage): void { $storage->delete('../evil.txt'); });
        $this->assertThrowsWithMessage($expected, function () use ($storage): void { $storage->exists('../evil.txt'); });
        $this->assertThrowsWithMessage($expected, function () use ($storage): void { $storage->stat('../evil.txt'); });
        $this->assertThrowsWithMessage(
            'Storage prefix contains an invalid path segment (..).',
            function () use ($storage): void { $storage->list('a/../b'); },
        );
        self::assertSame([], $transport->requests, 'Validation must happen before any transport call.');
    }

    public function testS3Status300IsNotSuccess(): void
    {
        $transport = new FakeS3Transport([new S3HttpResponse(300, [], 'multi')]);

        try {
            $this->s3($transport)->put('k.txt', 'v');
            self::fail('Status 300 must not count as success.');
        } catch (StorageException $error) {
            self::assertSame("PUT object 'k.txt' failed with HTTP status 300: multi", $error->getMessage());
        }
    }

    public function testS3DeleteFailureMessageIsExact(): void
    {
        $transport = new FakeS3Transport([new S3HttpResponse(503, [], 'slow')]);

        try {
            $this->s3($transport)->delete('k.txt');
            self::fail('Delete on 503 must fail.');
        } catch (StorageException $error) {
            self::assertSame("DELETE object 'k.txt' failed with HTTP status 503: slow", $error->getMessage());
        }
    }

    public function testS3GetFailureMessageIsExact(): void
    {
        $transport = new FakeS3Transport([new S3HttpResponse(500, [], 'oops')]);

        try {
            $this->s3($transport)->get('k.txt');
            self::fail('Get on 500 must fail.');
        } catch (StorageException $error) {
            self::assertSame("GET object 'k.txt' failed with HTTP status 500: oops", $error->getMessage());
        }
    }

    public function testS3ListFailureAndUrlAreExact(): void
    {
        $transport = new FakeS3Transport([new S3HttpResponse(503, [], 'down')]);

        try {
            $this->s3($transport)->list('logs/', 10);
            self::fail('List on 503 must fail.');
        } catch (StorageException $error) {
            self::assertSame('LIST objects failed with HTTP status 503: down', $error->getMessage());
        }
        $ok = new FakeS3Transport([new S3HttpResponse(200, [], '<?xml version="1.0"?><ListBucketResult/>')]);
        $this->s3($ok)->list('logs/', 10);
        self::assertSame(
            'https://s3.us-east-1.amazonaws.com/zef-bucket?list-type=2&max-keys=10&prefix=logs%2F',
            $ok->single()['url'],
        );
    }

    public function testS3StatMessagesAreExact(): void
    {
        $noLength = new FakeS3Transport([new S3HttpResponse(200, ['last-modified' => ['Wed, 01 Mar 2023 12:00:00 GMT']], '')]);

        try {
            $this->s3($noLength)->stat('k.txt');
            self::fail('Missing Content-Length must fail.');
        } catch (StorageException $error) {
            self::assertSame("HEAD response for 'k.txt' is missing metadata headers.", $error->getMessage());
        }
        $badDate = new FakeS3Transport([new S3HttpResponse(200, [
            'content-length' => ['12'],
            'last-modified' => ['not a date'],
        ], '')]);

        try {
            $this->s3($badDate)->stat('k.txt');
            self::fail('Unparsable Last-Modified must fail.');
        } catch (StorageException $error) {
            self::assertSame("HEAD response for 'k.txt' carries an unparsable Last-Modified.", $error->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // InMemoryMessageTransport — default capacity boundaries
    // ------------------------------------------------------------------

    public function testInMemoryTransportDefaultCapacityBoundary(): void
    {
        $transport = new InMemoryMessageTransport();
        $envelope = $this->envelope('m-00000001', 'bulk');
        $context = new MessageContext();
        for ($i = 0; $i < 10_000; ++$i) {
            $transport->send($envelope, $context);
        }
        self::assertSame(10_000, $transport->size());
        $this->expectException(\OverflowException::class);
        $transport->send($envelope, $context);
    }

    public function testInMemoryTransportCapacityOfOneIsValid(): void
    {
        $transport = new InMemoryMessageTransport(1);
        $result = $transport->send($this->envelope('m-00000001', 'one'), new MessageContext());
        self::assertSame('mem-000001', $result->transportId);
    }

    public function testValidKeysPass(): void
    {
        foreach (['a', 'report-2026.csv', 'a/b/c.txt', 'dir name/2026/x y+z ($).bin'] as $key) {
            StorageKeys::assertValidKey($key);
        }
        $this->addToAssertionCount(1);
    }

    public function testKeyBoundariesAreExact(): void
    {
        StorageKeys::assertValidKey(str_repeat('a', 1024)); // exactly MAX_BYTES: accepted
        $this->assertThrowsWithMessage(
            'Storage key cannot be empty.',
            StorageKeys::assertValidKey(...),
            [''],
        );
        $this->assertThrowsWithMessage(
            'Storage key exceeds 1024 bytes.',
            StorageKeys::assertValidKey(...),
            [str_repeat('a', 1025)],
        );
    }

    /**
     * @dataProvider controlCharacterKeyProvider
     */
    public function testControlCharactersAreRejected(string $key): void
    {
        $this->assertThrowsWithMessage('Storage key must not contain control characters.', StorageKeys::assertValidKey(...), [$key]);
    }

    /** @return list<array{0: string}> */
    public static function controlCharacterKeyProvider(): array
    {
        return [["a\nb"], ["a\0b"], ["a\rb"], ["a\eb"]];
    }

    public function testBackslashIsRejected(): void
    {
        $this->assertThrowsWithMessage('Storage key must not contain backslashes.', StorageKeys::assertValidKey(...), ['dir\file.txt']);
    }

    /**
     * @dataProvider slashKeyProvider
     */
    public function testLeadingAndTrailingSlashesAreRejected(string $key, string $message): void
    {
        $this->assertThrowsWithMessage($message, StorageKeys::assertValidKey(...), [$key]);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function slashKeyProvider(): array
    {
        return [['/lead', 'Storage key must not start with a slash.'], ['trail/', 'Storage key must not end with a slash.'], ['/both/', 'Storage key must not start with a slash.']];
    }

    /**
     * @dataProvider traversalKeyProvider
     */
    public function testTraversalSegmentsAreRejected(string $key, string $segment): void
    {
        $this->assertThrowsWithMessage("Storage key contains an invalid path segment ({$segment}).", StorageKeys::assertValidKey(...), [$key]);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function traversalKeyProvider(): array
    {
        return [['..', '..'], ['.', '.'], ['a/../b', '..'], ['a/./b', '.'], ['a//b', '']];
    }

    public function testPrefixAllowsEmptyAndSingleTrailingSlash(): void
    {
        foreach (['', 'logs/', 'logs/2026', 'a'] as $prefix) {
            StorageKeys::assertValidPrefix($prefix);
        }
        $this->addToAssertionCount(1);
    }

    public function testPrefixBoundariesAndMessages(): void
    {
        $this->assertThrowsWithMessage('Storage prefix must not contain backslashes.', StorageKeys::assertValidPrefix(...), ['logs\\\2026']);
        $this->assertThrowsWithMessage('Storage prefix exceeds 1024 bytes.', StorageKeys::assertValidPrefix(...), [str_repeat('a', 1025)]);
    }

    /**
     * @dataProvider invalidPrefixProvider
     */
    public function testInvalidPrefixesAreRejected(string $prefix, string $message): void
    {
        $this->assertThrowsWithMessage($message, StorageKeys::assertValidPrefix(...), [$prefix]);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function invalidPrefixProvider(): array
    {
        return [['a//', 'Storage prefix contains an invalid path segment ().'], ['a/./', 'Storage prefix contains an invalid path segment (.).']];
    }

    // ------------------------------------------------------------------
    // ObjectStat — boundaries pinned
    // ------------------------------------------------------------------

    public function testObjectStatHoldsMetadata(): void
    {
        $stat = new ObjectStat('a/b.txt', 123, 1_700_000_000_000_000_000);
        self::assertSame('a/b.txt', $stat->key);
        self::assertSame(123, $stat->sizeBytes);
        self::assertSame(1_700_000_000_000_000_000, $stat->lastModifiedUnixNano);
        new ObjectStat('k', 0, 0); // zero boundaries are legal
        new ObjectStat(str_repeat('a', 1024), 1, 1); // exactly MAX key length
        $this->addToAssertionCount(2);
    }

    /**
     * @dataProvider invalidStatProvider
     */
    public function testObjectStatRejectsInvalidInput(string $key, int $size, int $nano, string $message): void
    {
        $this->assertThrowsWithMessage($message, $this->statCtor(...), [$key, $size, $nano]);
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: string}> */
    public static function invalidStatProvider(): array
    {
        return [
            ['', 1, 1, 'Object stat key must be 1..1024 bytes.'],
            [str_repeat('a', 1025), 1, 1, 'Object stat key must be 1..1024 bytes.'],
            ['k', -1, 1, 'Object size cannot be negative.'],
            ['k', 1, -1, 'Object last-modified cannot be negative.'],
        ];
    }

    // ------------------------------------------------------------------
    // LocalStorage
    // ------------------------------------------------------------------

    public function testLocalStoragePutGetRoundTripPreservesBinaryContents(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $contents = "text\n\tbits\0and bytes \xFF\x00binary";
        $storage->put('a/b/c.bin', $contents);
        self::assertSame($contents, $storage->get('a/b/c.bin'));
    }

    public function testLocalStorageOverwriteReplacesContents(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $storage->put('k.txt', 'old');
        $storage->put('k.txt', 'new content');
        self::assertSame('new content', $storage->get('k.txt'));
    }

    public function testLocalStorageGetMissingThrowsObjectNotFound(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $this->expectException(ObjectNotFoundException::class);
        $storage->get('nope.txt');
    }

    public function testLocalStorageExistsAndStat(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        self::assertFalse($storage->exists('s.txt'));
        self::assertNull($storage->stat('s.txt'));
        $storage->put('s.txt', '0123456789');
        self::assertTrue($storage->exists('s.txt'));
        $stat = $storage->stat('s.txt');
        self::assertNotNull($stat);
        self::assertSame('s.txt', $stat->key);
        self::assertSame(10, $stat->sizeBytes);
        self::assertSame($stat->lastModifiedUnixNano, (int) filemtime($this->rootPath . '/s.txt') * 1_000_000_000);
    }

    public function testLocalStorageDeleteIsIdempotent(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $storage->put('d.txt', 'x');
        $storage->delete('d.txt');
        self::assertFalse($storage->exists('d.txt'));
        $storage->delete('d.txt'); // absent: still no error
        $this->addToAssertionCount(1);
    }

    public function testLocalStorageRejectsOversizedObjects(): void
    {
        $storage = new LocalStorage($this->tmpDir(), 8);
        $this->expectException(StorageException::class);
        $storage->put('big.bin', str_repeat('x', 9));
    }

    public function testLocalStorageRejectsNonPositiveMaxObjectBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalStorage($this->tmpDir(), 0);
    }

    public function testLocalStoragePutRejectsTraversalKeys(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $this->expectException(\InvalidArgumentException::class);
        $storage->put('../evil.txt', 'nope');
    }

    public function testLocalStorageCreatesRootAutomatically(): void
    {
        $this->tmpDir();
        $root = $this->tmpBase . '/nested/deeper/root';
        $storage = new LocalStorage($root);
        $storage->put('x.txt', 'v');
        self::assertSame('v', $storage->get('x.txt'));
    }

    public function testLocalStorageListIsSortedAndPrefixBounded(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        foreach (['logs/2026/c.txt', 'logs/2026/a.txt', 'logs/2025/b.txt', 'root.txt'] as $key) {
            $storage->put($key, 'v');
        }
        self::assertSame(
            ['logs/2025/b.txt', 'logs/2026/a.txt', 'logs/2026/c.txt', 'root.txt'],
            $storage->list(),
        );
        self::assertSame(['logs/2025/b.txt', 'logs/2026/a.txt', 'logs/2026/c.txt'], $storage->list('logs/'));
        self::assertSame(['logs/2026/a.txt'], $storage->list('logs/2026/a', 10));
        self::assertSame([], $storage->list('missing/'));
        self::assertSame(['logs/2026/a.txt', 'logs/2026/c.txt'], $storage->list('logs/2026/', 2));
    }

    /**
     * @dataProvider invalidListLimitProvider
     */
    public function testLocalStorageListLimitBounds(int $limit): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $this->expectException(\InvalidArgumentException::class);
        $storage->list('', $limit);
    }

    /** @return list<array{0: int}> */
    public static function invalidListLimitProvider(): array
    {
        return [[0], [1001], [-3]];
    }

    public function testLocalStoragePutLeavesNoTempFilesBehind(): void
    {
        $storage = new LocalStorage($this->tmpDir());
        $storage->put('clean.txt', 'data');
        self::assertSame(['clean.txt'], $storage->list());
    }

    // ------------------------------------------------------------------
    // SigV4 — pinned against the published AWS signing examples
    // ------------------------------------------------------------------

    public function testSigV4GetVanillaAwsVector(): void
    {
        // AWS docs "S3 Signature Version 4 signing process", GET Object example.
        $authorization = SigV4::authorization(
            'GET',
            '/test.txt',
            [],
            [
                'host' => 'examplebucket.s3.amazonaws.com',
                'range' => 'bytes=0-9',
                'x-amz-content-sha256' => SigV4::EMPTY_SHA256,
                'x-amz-date' => '20130524T000000Z',
            ],
            SigV4::payloadHash(''),
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1',
            '20130524T000000Z',
        );
        self::assertSame(
            'AWS4-HMAC-SHA256'
            . ' Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request'
            . ', SignedHeaders=host;range;x-amz-content-sha256;x-amz-date'
            . ', Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $authorization,
        );
    }

    public function testSigV4PutObjectAwsVector(): void
    {
        // AWS docs "S3 Signature Version 4 signing process", PUT Object example.
        $body = 'Welcome to Amazon S3.';
        $authorization = SigV4::authorization(
            'PUT',
            '/test%24file.text',
            [],
            [
                'host' => 'examplebucket.s3.amazonaws.com',
                'date' => 'Fri, 24 May 2013 00:00:00 GMT',
                'x-amz-content-sha256' => SigV4::payloadHash($body),
                'x-amz-date' => '20130524T000000Z',
                'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
            ],
            SigV4::payloadHash($body),
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1',
            '20130524T000000Z',
        );
        self::assertSame(
            'AWS4-HMAC-SHA256'
            . ' Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request'
            . ', SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class'
            . ', Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            $authorization,
        );
    }

    public function testSigV4EmptyPayloadHashMatchesConstant(): void
    {
        self::assertSame(hash('sha256', ''), SigV4::payloadHash(''));
        self::assertSame(SigV4::EMPTY_SHA256, SigV4::payloadHash(''));
    }

    public function testSigV4CanonicalQuerySortsAndEncodesOnce(): void
    {
        self::assertSame(
            'list-type=2&max-keys=10&prefix=logs%2F2026%20x',
            SigV4::canonicalQuery(['prefix' => 'logs/2026 x', 'list-type' => '2', 'max-keys' => '10']),
        );
    }

    public function testS3CtorValidation(): void
    {
        $transport = new FakeS3Transport();
        $mutants = [
            ['ZEF-BUCKET', 'us-east-1', 'AKI', 'SECRET', null],      // uppercase bucket
            ['ab', 'us-east-1', 'AKI', 'SECRET', null],              // too-short bucket
            ['zef-bucket', '', 'AKI', 'SECRET', null],               // empty region
            ['zef-bucket', 'us east', 'AKI', 'SECRET', null],        // whitespace region
            ['zef-bucket', 'us-east-1', '', 'SECRET', null],         // empty access key
            ['zef-bucket', 'us-east-1', 'AKI', '', null],            // empty secret
            ['zef-bucket', 'us-east-1', 'AKI', 'SECRET', 'not a url'],
            ['zef-bucket', 'us-east-1', 'AKI', 'SECRET', 'ftp://x.example.com'],
            ['zef-bucket', 'us-east-1', 'AKI', 'SECRET', 'http://host.example.com/base'],
            [str_repeat('a', 64), 'us-east-1', 'AKI', 'SECRET', null], // bucket length
        ];
        foreach ($mutants as $mutant) {
            try {
                new S3CompatibleStorage(...array_values(array_merge(array_slice($mutant, 0, 4), [$transport], array_slice($mutant, 4))));
                self::fail('S3CompatibleStorage accepted invalid ctor input.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testS3DefaultEndpointDerivedFromRegion(): void
    {
        $transport = new FakeS3Transport();
        $this->s3($transport)->put('k.txt', 'v');
        $request = $transport->single();
        self::assertSame('PUT', $request['method']);
        self::assertSame('https://s3.us-east-1.amazonaws.com/zef-bucket/k.txt', $request['url']);
    }

    public function testS3CustomEndpointPreservesHostAndPort(): void
    {
        $transport = new FakeS3Transport();
        $storage = new S3CompatibleStorage('zef-bucket', 'us-east-1', 'AKI', 'SECRET', $transport, 'http://127.0.0.1:9000');
        $storage->get('a/b.txt');
        $request = $transport->single();
        self::assertSame('http://127.0.0.1:9000/zef-bucket/a/b.txt', $request['url']);
        self::assertSame('127.0.0.1:9000', $request['headers']['host']);
    }

    public function testS3PutSendsSignedBodyAndSha256Header(): void
    {
        $transport = new FakeS3Transport();
        $this->s3($transport)->put('dir/a b.txt', 'payload!');
        $request = $transport->single();
        self::assertTrue(str_ends_with($request['url'], '/dir/a%20b.txt'));
        self::assertSame('payload!', $request['body']);
        self::assertSame(hash('sha256', 'payload!'), $request['headers']['x-amz-content-sha256']);
        self::assertMatchesRegularExpression('/^AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE\/\d{8}\/us-east-1\/s3\/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$/', $request['headers']['Authorization']);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $request['headers']['x-amz-date']);
    }

    public function testS3PutNonSuccessThrowsWithStatusAndSnippet(): void
    {
        $transport = new FakeS3Transport([new S3HttpResponse(503, [], "<Error><Message>Slow down\nplease</Message></Error>")]);

        try {
            $this->s3($transport)->put('k.txt', 'v');
            self::fail('Expected StorageException for HTTP 503.');
        } catch (StorageException $error) {
            self::assertStringContainsString('503', $error->getMessage());
            self::assertStringContainsString('Slow down please', $error->getMessage());
        }
    }

    public function testS3GetMapsResponses(): void
    {
        $ok = new FakeS3Transport([new S3HttpResponse(200, [], 'contents')]);
        self::assertSame('contents', $this->s3($ok)->get('k.txt'));
        $missing = new FakeS3Transport([new S3HttpResponse(404, [], '')]);

        try {
            $this->s3($missing)->get('k.txt');
            self::fail('Expected ObjectNotFoundException for HTTP 404.');
        } catch (ObjectNotFoundException) {
        }
        $boom = new FakeS3Transport([new S3HttpResponse(500, [], 'oops')]);
        $this->expectException(StorageException::class);
        $this->s3($boom)->get('k.txt');
    }

    public function testS3DeleteIsIdempotentAcrossStatusCodes(): void
    {
        $gone = new FakeS3Transport([new S3HttpResponse(204, [], '')]);
        $this->s3($gone)->delete('k.txt');
        self::assertSame('DELETE', $gone->single()['method']);
        $absent = new FakeS3Transport([new S3HttpResponse(404, [], '')]);
        $this->s3($absent)->delete('k.txt'); // 404 tolerated
        $broken = new FakeS3Transport([new S3HttpResponse(503, [], '')]);
        $this->expectException(StorageException::class);
        $this->s3($broken)->delete('k.txt');
    }

    public function testS3ExistsAndStatParseMetadataHeaders(): void
    {
        $yes = new FakeS3Transport([new S3HttpResponse(200, [], '')]);
        self::assertTrue($this->s3($yes)->exists('k.txt'));
        self::assertSame('HEAD', $yes->single()['method']);
        $no = new FakeS3Transport([new S3HttpResponse(404, [], '')]);
        self::assertFalse($this->s3($no)->exists('k.txt'));

        $statTransport = new FakeS3Transport([new S3HttpResponse(200, [
            'content-length' => ['12'],
            'last-modified' => ['Wed, 01 Mar 2023 12:00:00 GMT'],
        ], '')]);
        $stat = $this->s3($statTransport)->stat('k.txt');
        self::assertNotNull($stat);
        self::assertSame('k.txt', $stat->key);
        self::assertSame(12, $stat->sizeBytes);
        self::assertSame(1677672000 * 1_000_000_000, $stat->lastModifiedUnixNano);

        $absentStat = new FakeS3Transport([new S3HttpResponse(404, [], '')]);
        self::assertNull($this->s3($absentStat)->stat('k.txt'));
    }

    public function testS3StatRejectsMissingOrUnparsableMetadata(): void
    {
        $noLength = new FakeS3Transport([new S3HttpResponse(200, ['last-modified' => ['Wed, 01 Mar 2023 12:00:00 GMT']], '')]);

        try {
            $this->s3($noLength)->stat('k.txt');
            self::fail('Expected StorageException for missing Content-Length.');
        } catch (StorageException) {
        }
        $badDate = new FakeS3Transport([new S3HttpResponse(200, [
            'content-length' => ['12'],
            'last-modified' => ['not a date'],
        ], '')]);
        $this->expectException(StorageException::class);
        $this->s3($badDate)->stat('k.txt');
    }

    public function testS3ListParsesNamespacedAndPlainXml(): void
    {
        $namespaced = new FakeS3Transport([new S3HttpResponse(200, [], '<?xml version="1.0"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>false</IsTruncated>'
            . '<Contents><Key>logs/2026/b.txt</Key><Size>1</Size></Contents>'
            . '<Contents><Key>logs/2026/a.txt</Key><Size>2</Size></Contents>'
            . '</ListBucketResult>')]);
        self::assertSame(['logs/2026/a.txt', 'logs/2026/b.txt'], $this->s3($namespaced)->list('logs/', 100));
        $request = $namespaced->single();
        self::assertStringContainsString('list-type=2', $request['url']);
        self::assertStringContainsString('prefix=logs%2F', $request['url']);
        self::assertStringContainsString('max-keys=100', $request['url']);

        $plain = new FakeS3Transport([new S3HttpResponse(200, [], '<?xml version="1.0"?>'
            . '<ListBucketResult><Contents><Key>root.txt</Key></Contents></ListBucketResult>')]);
        self::assertSame(['root.txt'], $this->s3($plain)->list());
    }

    public function testS3ListRejectsMalformedXml(): void
    {
        $broken = new FakeS3Transport([new S3HttpResponse(200, [], '<not-xml')]);
        $this->expectException(StorageException::class);
        $this->s3($broken)->list();
    }

    /**
     * @dataProvider invalidS3ListLimitProvider
     */
    public function testS3ListRejectsInvalidLimits(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s3(new FakeS3Transport())->list('', $limit);
    }

    /** @return list<array{0: int}> */
    public static function invalidS3ListLimitProvider(): array
    {
        return [[0], [1001]];
    }

    public function testInMemoryTransportSendReturnsAcceptedResultWithSequentialIds(): void
    {
        $transport = new InMemoryMessageTransport();
        $first = $transport->send($this->envelope('m-00000001', 'one'), $this->context());
        $second = $transport->send($this->envelope('m-00000002', 'two'), $this->context());
        self::assertTrue($first->accepted);
        self::assertSame('m-00000001', $first->messageId);
        self::assertSame('mem-000001', $first->transportId);
        self::assertSame('mem-000002', $second->transportId);
    }

    public function testInMemoryTransportReceivePreservesEnvelopeAndContextFifo(): void
    {
        $transport = new InMemoryMessageTransport();
        $transport->send($this->envelope('m-00000001', 'one'), $this->context());
        $transport->send($this->envelope('m-00000002', 'two'), $this->context());
        self::assertSame(2, $transport->size());
        $first = $transport->receive();
        self::assertInstanceOf(ReceivedMessage::class, $first);
        self::assertSame('m-00000001', $first->envelope->messageId);
        self::assertSame('one', $first->envelope->payload);
        self::assertSame('toko', $first->context->attributes['tenant']);
        self::assertSame('mem-000001', $first->transportId);
        $second = $transport->receive();
        self::assertSame('m-00000002', $second?->envelope->messageId);
        self::assertNull($transport->receive());
        self::assertSame(0, $transport->size());
    }

    public function testInMemoryTransportDrainEmptiesInOrder(): void
    {
        $transport = new InMemoryMessageTransport();
        $transport->send($this->envelope('m-00000001', 'one'), $this->context());
        $transport->send($this->envelope('m-00000002', 'two'), $this->context());
        $drained = $transport->drain();
        self::assertCount(2, $drained);
        self::assertSame('m-00000001', $drained[0]->envelope->messageId);
        self::assertSame([], $transport->drain());
    }

    public function testInMemoryTransportCapacityOverflow(): void
    {
        $transport = new InMemoryMessageTransport(2);
        $transport->send($this->envelope('m-00000001', 'one'), $this->context());
        $transport->send($this->envelope('m-00000002', 'two'), $this->context());
        $this->expectException(\OverflowException::class);
        $transport->send($this->envelope('m-00000003', 'three'), $this->context());
    }

    public function testInMemoryTransportRejectsNonPositiveCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryMessageTransport(0);
    }

    public function testPdoJobQueueRoundTripPreservesEnvelope(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_v30');
        $queue->createSchema();
        $queue->enqueue($this->job('j-aaaaaaaa'));
        self::assertSame(1, $queue->size());
        $dequeued = $queue->dequeue();
        self::assertNotNull($dequeued);
        self::assertSame('j-aaaaaaaa', $dequeued->jobId);
        self::assertSame('mail.send', $dequeued->jobType);
        self::assertSame(['to' => 'user@example.com', 'n' => 10], $dequeued->payload);
        self::assertSame(self::NANO, $dequeued->availableAtUnixNano);
        self::assertSame('corr-12345678', $dequeued->correlationId);
        self::assertSame(['queue' => 'mail'], $dequeued->headers);
        self::assertSame(0, $queue->size());
        self::assertNull($queue->dequeue());
    }

    public function testPdoJobQueueOrdersByPriorityThenAvailabilityThenSequence(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_order');
        $queue->createSchema();
        $queue->enqueue($this->job('j-prioritylow', priority: 1));           // seq 1, pri 1
        $queue->enqueue($this->job('j-priorityhigh', priority: 5));          // seq 2, pri 5
        $queue->enqueue($this->job('j-highlater', availableAt: self::NANO + 1, priority: 5)); // seq 3
        $first = $queue->dequeue();
        self::assertNotNull($first);
        self::assertSame('j-priorityhigh', $first->jobId);
        $second = $queue->dequeue();
        self::assertNotNull($second);
        self::assertSame('j-highlater', $second->jobId);
        $third = $queue->dequeue();
        self::assertNotNull($third);
        self::assertSame('j-prioritylow', $third->jobId);
        self::assertNull($queue->dequeue());
    }

    public function testPdoJobQueueHonoursDelayedAvailability(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_delay', static fn (): int => self::NANO);
        $queue->createSchema();
        $queue->enqueue($this->job('j-future', availableAt: self::NANO + 5000));
        self::assertNull($queue->dequeue());
        self::assertSame(1, $queue->size());
        self::assertSame('j-future', $queue->dequeue(self::NANO + 5000)?->jobId);
    }

    public function testPdoJobQueueDuplicateJobIdFailsLoudly(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_dup');
        $queue->createSchema();
        $queue->enqueue($this->job('j-duplicate'));
        $this->expectException(QueryException::class);
        $queue->enqueue($this->job('j-duplicate'));
    }

    public function testPdoJobQueueCapacityGuard(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_cap', null, 1);
        $queue->createSchema();
        $queue->enqueue($this->job('job-first'));
        $this->expectException(\OverflowException::class);
        $queue->enqueue($this->job('job-second'));
    }

    public function testPdoJobQueueRejectsNonPositiveCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoJobQueue($this->sqliteConn(), 'zef_jobs_cap0', null, 0);
    }

    public function testPdoJobQueueRejectsNonJsonPayload(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_json');
        $queue->createSchema();
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        $job = new JobEnvelope('j-resource', 'mail.send', $resource, self::NANO);
        $this->expectException(\InvalidArgumentException::class);
        $queue->enqueue($job);
    }

    public function testPdoJobQueueJoinsAmbientTransaction(): void
    {
        $connection = $this->sqliteConn();
        $queue = new PdoJobQueue($connection, 'zef_jobs_ambient');
        $queue->createSchema();
        $connection->beginTransaction();
        $queue->enqueue($this->job('j-ambient'));
        self::assertSame(1, $queue->size()); // visible inside the ambient tx
        $connection->rollBack();
        self::assertSame(0, $queue->size()); // rolled back together with the caller's work
    }

    public function testPdoJobQueueRacedClaimSkipsToNextCandidate(): void
    {
        $connection = $this->sqliteConn();
        $queue = new PdoJobQueue($connection, 'zef_jobs_race');
        $queue->createSchema();
        $queue->enqueue($this->job('job-winner'));
        $queue->enqueue($this->job('job-loser'));
        // Every DELETE claim "loses" (affected rows 0) and a candidate stays
        // visible, so the loop must keep scanning through the full bounded
        // attempt budget and eventually report an empty queue without
        // hanging.
        $spy = new RaceLosingConnection($connection, 'zef_jobs_race');
        $racing = new PdoJobQueue($spy, 'zef_jobs_race');
        self::assertNull($racing->dequeue());
        self::assertSame(2, $queue->size()); // nothing was actually removed
        self::assertSame(8, $spy->transactions); // a lost race justifies each rescan
    }

    public function testPdoJobQueueEmptyQueueStopsAfterSingleScan(): void
    {
        $spy = new RaceLosingConnection($this->sqliteConn(), 'zef_jobs_empty');
        $queue = new PdoJobQueue($spy, 'zef_jobs_empty');
        $queue->createSchema();
        // An empty SELECT proves there is nothing to claim — the scan must
        // not burn the remaining claim attempts on pure nothingness.
        self::assertNull($queue->dequeue());
        self::assertSame(1, $spy->transactions);
    }

    public function testPdoJobQueueRejectsCorruptedStoredPayload(): void
    {
        $connection = $this->sqliteConn();
        $queue = new PdoJobQueue($connection, 'zef_jobs_corrupt');
        $queue->createSchema();
        $queue->enqueue($this->job('j-corrupt'));
        $connection->execute(new SqlQuery(
            'UPDATE "zef_jobs_corrupt" SET "payload" = ?',
            ['{not-json}'],
        ));
        $this->expectException(JobExecutionException::class);
        $queue->dequeue();
    }

    // ------------------------------------------------------------------
    // PdoJobIdempotencyStore (in-memory SQLite)
    // ------------------------------------------------------------------

    public function testPdoIdempotencyCachesProducerResultWhileLive(): void
    {
        $store = new PdoJobIdempotencyStore($this->sqliteConn(), 'zef_idem_v30', static fn (): int => 1000);
        $store->createSchema();
        $calls = 0;
        $produce = static function () use (&$calls): array {
            ++$calls;

            return ['side-effect' => $calls];
        };
        $first = $store->remember('idem-key-1', $produce, 600);
        $second = $store->remember('idem-key-1', $produce, 600);
        self::assertSame(['side-effect' => 1], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls);
    }

    public function testPdoIdempotencyCachesNullUntilExpiry(): void
    {
        $now = 1000;
        $store = new PdoJobIdempotencyStore($this->sqliteConn(), 'zef_idem_null', static function () use (&$now): int {
            return $now;
        });
        $store->createSchema();
        $calls = 0;
        $produce = static function () use (&$calls): null {
            ++$calls;

            return null;
        };

        self::assertNull($store->remember('null-key', $produce, 100));
        $now = 1099;
        self::assertNull($store->remember('null-key', $produce, 100));
        self::assertSame(1, $calls);

        $now = 1100;
        self::assertNull($store->remember('null-key', $produce, 100));
        self::assertSame(2, $calls);
        self::assertNull($store->remember('null-key', $produce, 100));
        self::assertSame(2, $calls);
    }

    public function testPdoIdempotencyExpiryRerunsProducerAndSweeps(): void
    {
        $now = 1000;
        $store = new PdoJobIdempotencyStore($this->sqliteConn(), 'zef_idem_exp', static function () use (&$now): int {
            return $now;
        });
        $store->createSchema();
        $calls = 0;
        $produce = static function () use (&$calls): string {
            ++$calls;

            return 'run-' . $calls;
        };
        self::assertSame('run-1', $store->remember('idem-key-2', $produce, 100));
        $now = 1100; // entry expired (expires_at = 1100, now >= expires_at)
        self::assertSame('run-2', $store->remember('idem-key-2', $produce, 100));
        self::assertSame(2, $calls);
    }

    public function testPdoIdempotencyProducerFailureIsNotCached(): void
    {
        $store = new PdoJobIdempotencyStore($this->sqliteConn(), 'zef_idem_fail', static fn (): int => 1000);
        $store->createSchema();
        $calls = 0;

        try {
            $store->remember('idem-key-3', static function () use (&$calls): never {
                ++$calls;

                throw new \RuntimeException('handler exploded');
            }, 600);
            self::fail('Producer exception must propagate.');
        } catch (\RuntimeException) {
        }
        $value = $store->remember('idem-key-3', static fn (): string => 'recovered', 600);
        self::assertSame('recovered', $value);
        self::assertSame(1, $calls);
    }

    public function testPdoIdempotencyLostRaceReturnsWinnerValue(): void
    {
        $connection = $this->sqliteConn();
        $store = new PdoJobIdempotencyStore($connection, 'zef_idem_race', static fn (): int => 1000);
        $store->createSchema();
        // The producer itself plants the winning row (simulating a concurrent
        // worker that completed first); the loser's INSERT hits the UNIQUE
        // constraint and must adopt the winner's value.
        $produce = static function () use ($connection): string {
            $connection->execute(new SqlQuery(
                'INSERT INTO "zef_idem_race" ("idem_key", "value", "expires_at") VALUES (?, ?, ?)',
                ['idem-key-4', json_encode('winner'), 2000],
            ));

            return 'loser';
        };
        self::assertSame('winner', $store->remember('idem-key-4', $produce, 600));
    }

    public function testPdoIdempotencyLostRaceReturnsNullWinnerValue(): void
    {
        $connection = $this->sqliteConn();
        $store = new PdoJobIdempotencyStore($connection, 'zef_idem_null_race', static fn (): int => 1000);
        $store->createSchema();
        $calls = 0;
        $produce = static function () use ($connection, &$calls): string {
            ++$calls;
            // Simulate another worker committing null before our INSERT.
            $connection->execute(new SqlQuery(
                'INSERT INTO "zef_idem_null_race" ("idem_key", "value", "expires_at") VALUES (?, ?, ?)',
                ['null-winner-key', 'null', 2000],
            ));

            return 'loser';
        };

        self::assertNull($store->remember('null-winner-key', $produce, 600));
        self::assertNull($store->remember('null-winner-key', $produce, 600));
        self::assertSame(1, $calls);
    }

    public function testPdoIdempotencyExpiresAtIsComputedAtInsertTime(): void
    {
        $now = 1000;
        $store = new PdoJobIdempotencyStore($this->sqliteConn(), 'zef_idem_ttl', static function () use (&$now): int {
            return $now;
        });
        $store->createSchema();
        $calls = 0;
        $produce = static function () use (&$now, &$calls): string {
            // The producer outlasts most of the TTL…
            $now = 1500;
            ++$calls;

            return 'slow-producer';
        };
        self::assertSame('slow-producer', $store->remember('idem-key-6', $produce, 100));
        // …yet expires_at is anchored to insert time (1600, not 1100), so
        // the value stays live at 1550 and the producer must NOT re-run.
        $now = 1550;
        self::assertSame('slow-producer', $store->remember('idem-key-6', $produce, 100));
        self::assertSame(1, $calls);
    }

    public function testPdoIdempotencyExpiredWinnerIsNotAdopted(): void
    {
        $connection = $this->sqliteConn();
        $now = 1000;
        $store = new PdoJobIdempotencyStore($connection, 'zef_idem_stale', static function () use (&$now): int {
            return $now;
        });
        $store->createSchema();
        // The concurrent winner commits with a 5-second TTL and the loser's
        // producer outlasts it: by the time the loser's INSERT fails the
        // winner's entry is dead, so the loser must rethrow its INSERT error
        // instead of adopting a value that no longer honours its TTL.
        $produce = static function () use ($connection, &$now): string {
            $connection->execute(new SqlQuery(
                'INSERT INTO "zef_idem_stale" ("idem_key", "value", "expires_at") VALUES (?, ?, ?)',
                ['idem-key-7', json_encode('winner'), $now + 5],
            ));
            $now = 2000;

            return 'loser';
        };
        $this->expectException(QueryException::class);
        $store->remember('idem-key-7', $produce, 600);
    }

    public function testPdoIdempotencyValidationAndCorruption(): void
    {
        $connection = $this->sqliteConn();
        $store = new PdoJobIdempotencyStore($connection, 'zef_idem_val', static fn (): int => 1000);
        $store->createSchema();
        foreach ([
            ['', 600],
            [str_repeat('k', 256), 600],
            ['key', 0],
            ['key', 604_801],
        ] as [$key, $ttl]) {
            try {
                $store->remember($key, static fn (): string => 'x', $ttl);
                self::fail("Idempotency validation accepted key='{$key}' ttl={$ttl}.");
            } catch (\InvalidArgumentException) {
            }
        }
        $connection->execute(new SqlQuery(
            'INSERT INTO "zef_idem_val" ("idem_key", "value", "expires_at") VALUES (?, ?, ?)',
            ['idem-key-5', '{corrupt', 2000],
        ));
        $this->expectException(\RuntimeException::class);
        $store->remember('idem-key-5', static fn (): string => 'fresh', 600);
    }

    private function loadShadow(): void
    {
        if (!self::$shadowLoaded) {
            require_once __DIR__ . '/ShadowCurl.php';
            self::$shadowLoaded = true;
        }
        ShadowCurlState::$enabled = true;
        ShadowCurlState::$initReturnsFalse = false;
        ShadowCurlState::$execResult = 'shadow-body';
        ShadowCurlState::$responseCode = 200;
        ShadowCurlState::$curlError = 'boom-shadow';
    }
    // ------------------------------------------------------------------
    // StorageKeys — the shared key grammar (messages pinned exactly)
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $args
     */
    private function assertThrowsWithMessage(string $expected, callable $fn, array $args = []): void
    {
        try {
            $fn(...$args);
            self::fail("Expected InvalidArgumentException with message: {$expected}");
        } catch (\InvalidArgumentException $error) {
            self::assertSame($expected, $error->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // S3CompatibleStorage — over the recording fake transport
    // ------------------------------------------------------------------

    private function s3(FakeS3Transport $transport): S3CompatibleStorage
    {
        return new S3CompatibleStorage(
            'zef-bucket',
            'us-east-1',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            $transport,
        );
    }

    private function statCtor(string $key, int $size, int $nano): bool
    {
        new ObjectStat($key, $size, $nano);

        return true;
    }

    /**
     * Construct with arbitrary args just to exercise the throwing guards
     * (always returns true so void-arrow closures stay legal).
     */
    private function s3Ctor(FakeS3Transport $t, string $bucket, string $region, string $keyId, string $secret, ?string $endpoint = null): bool
    {
        // The result is intentionally discarded: the constructor must throw
        // for every argument set routed through assertThrowsWithMessage().
        new S3CompatibleStorage($bucket, $region, $keyId, $secret, $t, $endpoint);

        return true;
    }

    // ------------------------------------------------------------------
    // InMemoryMessageTransport
    // ------------------------------------------------------------------

    private function envelope(string $id, string $payload): MessageEnvelope
    {
        return new MessageEnvelope($id, 'order.placed', $payload, ['source' => 'v30-test']);
    }

    private function context(): MessageContext
    {
        return new MessageContext(
            'corr-12345678',
            '00-46b7e3f5c1a94b2e8d0f6a1c2b3d4e5f-0a1b2c3d4e5f6a7b-01',
            ['tenant' => 'toko'],
        );
    }

    private function sqliteConn(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }

    private function job(string $id, int $availableAt = self::NANO, int $priority = 0, int $attempt = 1): JobEnvelope
    {
        return new JobEnvelope($id, 'mail.send', ['to' => 'user@example.com', 'n' => strlen($id)], $availableAt, $priority, $attempt, 'corr-12345678', '00-46b7e3f5c1a94b2e8d0f6a1c2b3d4e5f-0a1b2c3d4e5f6a7b-01', ['queue' => 'mail']);
    }

    private function tmpDir(): string
    {
        $base = sys_get_temp_dir() . '/zef-v30-' . bin2hex(random_bytes(4));
        $this->tmpBase = $base;
        $this->rootPath = $base . '/store';
        mkdir($this->rootPath, 0o777, true);

        return $this->rootPath;
    }
}
