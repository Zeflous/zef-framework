<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(ServerRequestInterface::class)) {
    interface ServerRequestInterface extends RequestInterface
    {
        public function getServerParams(): array;

        public function getCookieParams(): array;

        public function withCookieParams(array $cookies): ServerRequestInterface;

        public function getQueryParams(): array;

        public function withQueryParams(array $query): ServerRequestInterface;

        public function getUploadedFiles(): array;

        public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface;

        public function getParsedBody(): mixed;

        public function withParsedBody($data): ServerRequestInterface;

        public function getAttributes(): array;

        public function getAttribute(string $name, mixed $default = null): mixed;

        public function withAttribute(string $name, mixed $value): ServerRequestInterface;

        public function withoutAttribute(string $name): ServerRequestInterface;
    }
}
