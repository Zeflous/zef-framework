<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\StreamInterface;

final class Stream implements StreamInterface
{
    /**
     * @var null|resource
     */
    private $resource;

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Stream resource required.');
        }
        $this->resource = $resource;
    }

    #[\Override]
    public function __toString(): string
    {
        try {
            if (!is_resource($this->resource) || !$this->isReadable()) {
                return '';
            }
            if ($this->isSeekable()) {
                $this->rewind();
            }
            $contents = stream_get_contents($this->resource);

            return $contents === false ? '' : $contents;
        } catch (\Throwable) {
            return '';
        }
    }

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new StreamException('Unable to create memory stream.');
        }
        $stream = new self($resource);
        if ($content !== '') {
            $stream->write($content);
        }
        $stream->rewind();

        return $stream;
    }

    #[\Override]
    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->resource = null;
    }

    #[\Override]
    public function detach(): mixed
    {
        $detached = $this->resource;
        $this->resource = null;

        return $detached;
    }

    #[\Override]
    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }
        $stats = fstat($this->resource);

        return is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : null;
    }

    #[\Override]
    public function tell(): int
    {
        if (!is_resource($this->resource)) {
            throw new StreamException('Stream detached.');
        }
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new StreamException('Unable to determine stream position.');
        }

        return $pos;
    }

    #[\Override]
    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return is_resource($this->resource) && (bool) $this->metadata('seekable');
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (
            !is_resource($this->resource)
            || !$this->isSeekable()
            || fseek($this->resource, $offset, $whence) !== 0
        ) {
            throw new StreamException('Stream is not seekable.');
        }
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Bug fix #7: is_resource check moved before metadata() call.
     */
    #[\Override]
    public function isWritable(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }
        $mode = (string) $this->metadata('mode');

        return $mode !== '' && preg_match('/[waxc+]/', $mode) === 1;
    }

    #[\Override]
    public function write(string $string): int
    {
        if (!is_resource($this->resource) || !$this->isWritable()) {
            throw new StreamException('Stream is not writable.');
        }
        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new StreamException('Unable to write to stream.');
        }

        return $written;
    }

    /**
     * Bug fix #7: is_resource check moved before metadata() call.
     */
    #[\Override]
    public function isReadable(): bool
    {
        if (!is_resource($this->resource)) {
            return false;
        }
        $mode = (string) $this->metadata('mode');

        return $mode !== '' && preg_match('/[r+]/', $mode) === 1;
    }

    #[\Override]
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative.');
        }
        if (!is_resource($this->resource) || !$this->isReadable()) {
            throw new StreamException('Stream is not readable.');
        }
        if ($length === 0) {
            return '';
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new StreamException('Unable to read stream.');
        }

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        if (!is_resource($this->resource) || !$this->isReadable()) {
            throw new StreamException('Stream is not readable.');
        }
        $data = stream_get_contents($this->resource);
        if ($data === false) {
            throw new StreamException('Unable to read stream contents.');
        }

        return $data;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->metadata($key);
    }

    private function metadata(?string $key = null): mixed
    {
        if (!is_resource($this->resource)) {
            return $key === null ? [] : null;
        }
        $meta = stream_get_meta_data($this->resource);

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
