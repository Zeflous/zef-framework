<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(StreamInterface::class)) {
    interface StreamInterface
    {
        public function __toString(): string;

        public function close(): void;

        public function detach(): mixed;

        public function getSize(): ?int;

        public function tell(): int;

        public function eof(): bool;

        public function isSeekable(): bool;

        public function seek(int $offset, int $whence = SEEK_SET): void;

        public function rewind(): void;

        public function isWritable(): bool;

        public function write(string $string): int;

        public function isReadable(): bool;

        public function read(int $length): string;

        public function getContents(): string;

        public function getMetadata(?string $key = null): mixed;
    }
}
