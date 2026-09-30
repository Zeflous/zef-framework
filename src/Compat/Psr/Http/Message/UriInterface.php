<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(UriInterface::class)) {
    interface UriInterface
    {
        public function __toString(): string;

        public function getScheme(): string;

        public function getAuthority(): string;

        public function getUserInfo(): string;

        public function getHost(): string;

        public function getPort(): ?int;

        public function getPath(): string;

        public function getQuery(): string;

        public function getFragment(): string;

        public function withScheme(string $scheme): UriInterface;

        public function withUserInfo(string $user, ?string $password = null): UriInterface;

        public function withHost(string $host): UriInterface;

        public function withPort(?int $port): UriInterface;

        public function withPath(string $path): UriInterface;

        public function withQuery(string $query): UriInterface;

        public function withFragment(string $fragment): UriInterface;
    }
}
