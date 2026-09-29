<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\StreamInterface;
use Zef\Framework\Exception\PayloadTooLargeException;

/**
 * Bug fix #1: seek/rewind now reset $observedBytes so read+rewind+read
 * does not double-count and throw PayloadTooLargeException on small bodies.
 */
final class LimitedInputStream implements StreamInterface
{
    private int $observedBytes = 0;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly RequestBodyPolicy $policy,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (PayloadTooLargeException|\Throwable) {
            return '';
        }
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

    /**
     * N-8 (issue #176): reports the EFFECTIVE size — min(inner size,
     * policy limit) — never the raw underlying size. PSR-7 consumers use
     * getSize() to reason about how many bytes the stream can actually
     * deliver, and this wrapper refuses to deliver anything past the
     * limit; an oversized body therefore reports exactly maxBytes. Null
     * still means "unknown" (delegated to the inner stream), and note
     * that a read past the limit throws PayloadTooLargeException rather
     * than truncating, so the effective size is an upper bound on what
     * getContents() will return for a compliant body.
     */
    #[\Override]
    public function getSize(): ?int
    {
        $size = $this->inner->getSize();

        return $size === null ? null : min($size, $this->policy->maxBytes);
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

    /**
     * Bug fix #1: reset $observedBytes after seek so re-reads don't double-count.
     */
    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
        $this->observedBytes = max(0, $this->inner->tell());
    }

    /**
     * Bug fix #1: reset $observedBytes after rewind.
     */
    #[\Override]
    public function rewind(): void
    {
        $this->inner->rewind();
        $this->observedBytes = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Limited input stream is read-only.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    #[\Override]
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative.');
        }
        if ($length === 0) {
            return '';
        }
        $remaining = $this->policy->maxBytes - $this->observedBytes;
        $probe = min($length, max(1, $remaining + 1));
        $data = $this->inner->read($probe);
        $len = strlen($data);
        if ($this->observedBytes + $len > $this->policy->maxBytes) {
            $this->observedBytes = $this->policy->maxBytes + 1;

            throw new PayloadTooLargeException('Request body exceeds configured size limit.');
        }
        $this->observedBytes += $len;

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $chunk = $this->read(8192);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
