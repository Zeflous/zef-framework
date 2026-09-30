<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Log;

if (!interface_exists(LoggerInterface::class)) {
    interface LoggerInterface
    {
        public function emergency(string|\Stringable $message, array $context = []): void;

        public function alert(string|\Stringable $message, array $context = []): void;

        public function critical(string|\Stringable $message, array $context = []): void;

        public function error(string|\Stringable $message, array $context = []): void;

        public function warning(string|\Stringable $message, array $context = []): void;

        public function notice(string|\Stringable $message, array $context = []): void;

        public function info(string|\Stringable $message, array $context = []): void;

        public function debug(string|\Stringable $message, array $context = []): void;

        public function log($level, string|\Stringable $message, array $context = []): void;
    }
}
