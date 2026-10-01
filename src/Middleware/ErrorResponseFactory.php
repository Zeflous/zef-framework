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

    /**
     * Auth scheme keywords: when the first value token of a credential key
     * is one of these ("Authorization: Bearer abc123xyz"), the credential
     * material continues past the whitespace ending the candidate match, so
     * the whole remaining region is consumed (issue #307).
     */
    private const array AUTH_SCHEME_KEYWORDS = ['bearer', 'basic', 'digest'];

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
     *
     * Once a credential key matches and its first value token is an auth
     * scheme keyword ("Authorization: Bearer abc123xyz"), the WHOLE
     * remaining region on that line is consumed (issue #307): the candidate
     * value class stops at whitespace, so every token after the keyword
     * used to leak. Single-token values keep the historical behaviour of
     * preserving trailing prose ("password=hunter2 gone" ->
     * "password=[REDACTED] gone") so diagnostics stay readable.
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
                $end = $m[0][1] + strlen($m[0][0]);
                // Issue #307: a scheme keyword as the first value token
                // ("Authorization: Bearer abc123xyz") means the credential
                // value continues past the whitespace that ended the match —
                // consume the rest of the region (to the closing quote or
                // end of line), otherwise every token after the keyword
                // leaks. Prose after single-token values ("password=hunter2
                // gone") is deliberately preserved: only the value is
                // masked, keeping diagnostics readable.
                $valueToken = strtolower(substr(
                    $m[0][0],
                    strlen($m[1][0]) + strlen($m[2][0]) + strlen($m[3][0]),
                ));
                if ($m[3][0] !== '' && str_ends_with($valueToken, $m[3][0])) {
                    $valueToken = substr($valueToken, 0, -strlen($m[3][0]));
                }
                // Only unquoted scheme-keyword values extend: quoted regions
                // already end at their closing quote, and single-token
                // values keep the historical prose-preserving behaviour.
                if ($quote === '' && in_array($valueToken, self::AUTH_SCHEME_KEYWORDS, true)) {
                    $end += strcspn($message, "\r\n", $end);
                }
                $edits[] = [$m[0][1], $end - $m[0][1], $key . $sep . $quote . '[REDACTED]' . $quote];
                $offset = $end;

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
