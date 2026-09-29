<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Zef\Framework\Http\Response;

final readonly class ErrorResponseFactory
{
    /**
     * Credential key tokens (lowercase) whose embedded values must never
     * reach a client. Checked on the PHP side so the regex stays flat
     * (php:S5843) instead of a 14-branch alternation.
     */
    private const array CREDENTIAL_KEYS = [
        'pass', 'password', 'passwd', 'pwd',
        'secret', 'token',
        'apikey', 'api_key', 'api-key',
        'authorization', 'bearer',
        'privatekey', 'private_key', 'private-key',
    ];

    /**
     * Candidate `key (separator) value` regions; the key is verified against
     * CREDENTIAL_KEYS in PHP. Conservative ASCII classes (the /i flag covers
     * case) so normal prose is left untouched — the explicit ASCII classes
     * are the contract (php:S5867 by-design).
     */
    private const string REDACTION_CANDIDATE_RE
        = '/\b([a-z][a-z0-9_-]{2,})\b(\s*[=:]\s*|\s+)(["\']?)[a-z0-9._\/-]{4,}\3/i';

    public function __construct(private bool $devMode = false) {}

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
     * Staged scanning (php:S5843): the flat candidate regex finds `key sep
     * value` regions, PHP verifies the key against CREDENTIAL_KEYS, and a
     * non-credential match advances one character only so a credential key
     * inside its value region stays scannable (a consuming replace would
     * miss it). Edits are applied right-to-left and never overlap.
     */
    private function redactSecrets(string $message): string
    {
        /** @var list<array{int,int,string}> $edits [byte offset, byte length, replacement] */
        $edits = [];
        $offset = 0;
        while ($offset < strlen($message)) {
            $found = preg_match(self::REDACTION_CANDIDATE_RE, $message, $m, PREG_OFFSET_CAPTURE, $offset);
            if ($found !== 1) {
                break;
            }
            [$key, $sep, $quote] = [$m[1][0], $m[2][0], $m[3][0]];
            if (in_array(strtolower($key), self::CREDENTIAL_KEYS, true)) {
                $edits[] = [$m[0][1], strlen($m[0][0]), $key . $sep . $quote . '[REDACTED]' . $quote];
                $offset = $m[0][1] + strlen($m[0][0]);

                continue;
            }
            $offset = $m[0][1] + 1;
        }
        foreach (array_reverse($edits) as [$start, $length, $replacement]) {
            $message = substr_replace($message, $replacement, $start, $length);
        }

        return $message;
    }
}
