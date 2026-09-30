<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(RequestInterface::class)) {
    interface RequestInterface extends MessageInterface
    {
        public function getRequestTarget(): string;

        public function withRequestTarget(string $requestTarget): RequestInterface;

        public function getMethod(): string;

        public function withMethod(string $method): RequestInterface;

        public function getUri(): UriInterface;

        public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface;
    }
}
