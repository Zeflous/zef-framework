<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(UploadedFileInterface::class)) {
    interface UploadedFileInterface
    {
        public function getStream(): StreamInterface;

        public function moveTo(string $targetPath): void;

        public function getSize(): ?int;

        public function getError(): int;

        public function getClientFilename(): ?string;

        public function getClientMediaType(): ?string;
    }
}
