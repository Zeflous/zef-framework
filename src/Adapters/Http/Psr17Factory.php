<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Zef\Framework\Exception\StreamOpenException;

/**
 * PSR-17 factory for the framework's HTTP message implementations.
 */
final class Psr17Factory implements RequestFactoryInterface, ResponseFactoryInterface, ServerRequestFactoryInterface, StreamFactoryInterface, UploadedFileFactoryInterface, UriFactoryInterface
{
    /**
     * @param mixed $uri validated at runtime; string or UriInterface accepted
     */
    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        $u = $uri instanceof UriInterface ? $uri : new Uri((string) $uri);

        return new Request($method, $u);
    }

    #[\Override]
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, reasonPhrase: $reasonPhrase);
    }

    /**
     * @param mixed $uri validated at runtime; string or UriInterface accepted
     * @param array<string, mixed> $serverParams
     */
    #[\Override]
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        $u = $uri instanceof UriInterface ? $uri : new Uri((string) $uri);

        return new ServerRequest($method, $u, $serverParams);
    }

    #[\Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::fromString($content);
    }

    #[\Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        if ($filename === '') {
            throw new \InvalidArgumentException('Filename must not be empty.');
        }
        if (
            preg_match('/^[rwaxtc](?:[bt])?(?:\+)?e?\z/', $mode) !== 1
            && preg_match('/^[rwaxtc](?:\+)?[bt]e?\z/', $mode) !== 1
        ) {
            throw new \InvalidArgumentException("Invalid stream mode '{$mode}'.");
        }
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            // Only warning-grade diagnostics carry a useful fopen() failure
            // reason; lower-severity noise must not overwrite the real cause.
            if (($severity & (E_WARNING | E_NOTICE)) !== 0) {
                $warning = $message;
            }

            return true;
        });

        try {
            $h = fopen($filename, $mode);
        } finally {
            restore_error_handler();
        }
        if ($h === false) {
            throw new StreamOpenException("Unable to open '{$filename}'" . ($warning !== null ? ": {$warning}" : '.'));
        }

        return new Stream($h);
    }

    /**
     * @param resource $resource
     */
    #[\Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Resource required.');
        }
        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'] ?? '';
        if ($mode === '' || preg_match('/[r+]/', $mode) !== 1) {
            throw new \InvalidArgumentException('Resource must be readable.');
        }

        return new Stream($resource);
    }

    #[\Override]
    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        if (!$stream->isReadable()) {
            throw new \InvalidArgumentException('Uploaded file stream must be readable.');
        }
        if ($size !== null && $size < 0) {
            throw new \InvalidArgumentException('Uploaded file size must not be negative.');
        }

        return new UploadedFile($stream, $size ?? $stream->getSize(), $error, $clientFilename, $clientMediaType);
    }

    #[\Override]
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
