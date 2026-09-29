<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Zef\Framework\Http\Response;

final class ErrorResponseFactory
{
    public function __construct(private readonly bool $devMode = false) {}

    public function isDebug(): bool
    {
        return $this->devMode;
    }

    public function create(int $status, string $message, string $correlationId): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                    'error' => true,
                    'status' => $status,
                    // Dev-mode diagnostics intentionally expose the
                    // exception message — but never credential material
                    // that a handler happened to embed in it (v2.7.0:
                    // raw messages leaked "password=..." to clients).
                    'message' => $this->devMode ? $this->redactSecrets($message) : 'An error occurred',
                    'correlation_id' => $correlationId,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * Masks values of common credential keys embedded in exception
     * messages ("password=hunter2", "Bearer abc...", "api_key: xyz").
     * Conservative: only [A-Za-z0-9._-] values are matched so normal
     * prose is left untouched.
     */
    private function redactSecrets(string $message): string
    {
        $redacted = preg_replace(
            '/\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key|authorization|bearer|private[_-]?key)\b(\s*[=:]\s*|\s+)(["\']?)[A-Za-z0-9._\/-]{4,}\3/i',
            '$1$2$3[REDACTED]$3',
            $message,
        );

        return $redacted ?? $message;
    }
}
