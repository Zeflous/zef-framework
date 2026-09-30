<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(MessageInterface::class)) {
    interface MessageInterface
    {
        public function getProtocolVersion(): string;

        public function withProtocolVersion(string $version): MessageInterface;

        public function getHeaders(): array;

        public function hasHeader(string $name): bool;

        public function getHeader(string $name): array;

        public function getHeaderLine(string $name): string;

        public function withHeader(string $name, $value): MessageInterface;

        public function withAddedHeader(string $name, $value): MessageInterface;

        public function withoutHeader(string $name): MessageInterface;

        public function getBody(): StreamInterface;

        public function withBody(StreamInterface $body): MessageInterface;
    }
}
