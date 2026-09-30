<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(ResponseInterface::class)) {
    interface ResponseInterface extends MessageInterface
    {
        public function getStatusCode(): int;

        public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface;

        public function getReasonPhrase(): string;
    }
}
