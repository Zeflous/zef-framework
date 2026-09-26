<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Storage\CurlS3HttpTransport;
use Zef\Framework\Storage\S3CompatibleStorage;

/**
 * @internal
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CurlS3HttpTransportTest extends TestCase
{
    public function testHeadReadsMetadataWithoutExpectingAnObjectBody(): void
    {
        // A fresh process keeps the namespace cURL shadows used by
        // EcosystemPortsV30Test out of this real transport regression test.
        if (!extension_loaded('curl') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('The wire test requires ext-curl and pcntl_fork.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "Cannot bind the S3 test server: {$errstr}");
        $address = stream_socket_get_name($server, false);
        self::assertNotFalse($address);
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            self::fail('Cannot fork the S3 test server.');
        }
        if ($pid === 0) {
            $this->serveRequests($server);

            exit(0);
        }

        try {
            $endpoint = 'http://' . $address;
            $transport = new CurlS3HttpTransport();
            $response = $transport->request('HEAD', $endpoint . '/bucket/object', [], '');
            self::assertSame(200, $response->status);
            self::assertSame('', $response->body);
            self::assertSame('12', $response->header('Content-Length'));
            self::assertSame('Wed, 01 Mar 2023 12:00:00 GMT', $response->header('Last-Modified'));

            // Body suppression must be limited to HEAD.
            $response = $transport->request('GET', $endpoint . '/bucket/object', [], '');
            self::assertSame(200, $response->status);
            self::assertSame('hello world!', $response->body);

            $storage = new S3CompatibleStorage('bucket', 'us-east-1', 'test-key', 'test-secret', $transport, $endpoint);
            self::assertTrue($storage->exists('object'));
            $stat = $storage->stat('object');
            self::assertNotNull($stat);
            self::assertSame('object', $stat->key);
            self::assertSame(12, $stat->sizeBytes);
            self::assertSame(1_677_672_000_000_000_000, $stat->lastModifiedUnixNano);
            self::assertFalse($storage->exists('missing'));
            self::assertNull($storage->stat('missing'));
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
        self::assertIsInt($status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    /**
     * @param resource $server
     */
    private function serveRequests($server): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $connection = @stream_socket_accept($server, 10);
            if ($connection === false) {
                exit(1);
            }
            stream_set_timeout($connection, 2);
            $requestLine = fgets($connection);
            if ($requestLine === false) {
                exit(1);
            }
            while (($line = fgets($connection)) !== false && $line !== "\r\n") {
                // Consume the request headers before replying.
            }
            $status = str_contains($requestLine, '/missing ') ? '404 Not Found' : '200 OK';
            $body = str_starts_with($requestLine, 'HEAD ') ? '' : 'hello world!';
            fwrite($connection, "HTTP/1.1 {$status}\r\nContent-Length: 12\r\nLast-Modified: Wed, 01 Mar 2023 12:00:00 GMT\r\nConnection: close\r\n\r\n" . $body);
            // Closing immediately makes a broken HEAD fail with a truncated
            // body error instead of waiting for the production 30s timeout.
            fclose($connection);
        }
        fclose($server);
    }
}
