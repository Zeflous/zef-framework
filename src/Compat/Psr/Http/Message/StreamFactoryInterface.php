<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(StreamFactoryInterface::class)) {
    interface StreamFactoryInterface
    {
        public function createStream(string $content = ''): StreamInterface;

        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;

        public function createStreamFromResource($resource): StreamInterface;
    }
}
