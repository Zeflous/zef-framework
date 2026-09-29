<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class TelemetrySanitizer
{
    private const array SENSITIVE = [
        'authorization', 'cookie', 'set-cookie', 'password', 'passwd', 'token',
        'secret', 'api-key', 'api_key', 'apikey', 'access-token', 'refresh-token', 'client-secret',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', $key));

        return array_any(
            self::SENSITIVE,
            fn (string $needle): bool => $normalized === $needle || str_contains($normalized, $needle),
        );
    }

    /**
     * Bug fix #6: uses mb_strcut when available for UTF-8 safe truncation.
     */
    public static function string(string $value, int $limit = 2048): string
    {
        if ($limit < 1) {
            return '';
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        // Invalid UTF-8 made json_encode(JSON_THROW_ON_ERROR) throw deep
        // inside the meter/exporter (JsonException leaking into the
        // request path, whole export batches dropped). Scrub instead.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $value = function_exists('mb_scrub')
                ? mb_scrub($value, 'UTF-8')
                : (is_string($converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8')) ? $converted : '');
        }
        if (strlen($value) <= $limit) {
            return $value;
        }
        if ($limit <= 3) {
            // mb_strcut with a negative length (limit-3 < 0) would keep
            // bytes from the END and bypass the cap entirely.
            return substr($value, 0, $limit);
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $limit - 3, 'UTF-8') . '…';
        }
        // Manual UTF-8-safe cut: walk back from $limit until we find a valid boundary.
        $cut = $limit;
        while ($cut > 0 && (ord($value[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }

        return substr($value, 0, $cut) . '…';
    }

    public static function redact(string $value, int $limit = 2048): string
    {
        // P-21 (issue #172): pairs may quote the key and/or the value — JSON-style
        // log lines ("password":"x" or 'token'='y') previously passed through
        // UNREDACTED because the separator had to follow the bare keyword. The
        // vocabulary is widened (pwd, passphrase, credentials, private key) to
        // track the config-surface matcher used by the inspector.
        $value = preg_replace(
            '/(["\']?)(password|passwd|pwd|passphrase|secret|token|api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|authorization|credentials|credential|private[_-]?key)\1\s*[:=]\s*(["\']?)([^\s,;"\']+)\3/i',
            '$2=[REDACTED]',
            $value,
        ) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;

        return self::string($value, $limit);
    }

    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_float($value)) {
            // NaN/INF passed through unvalidated and made json_encode of
            // the OTLP payload throw → export() threw → the processor
            // dropped the whole batch. Emit the string form instead.
            //
            // The literals are spelled out rather than produced by a cast:
            // PHP 8.5 raises "unexpected NAN value was coerced to string"
            // for (string) NAN, which failOnWarning turns into a red suite.
            if (is_nan($value)) {
                return 'NAN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'INF' : '-INF';
            }

            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 32, true) as $k => $v) {
                $key = (string) $k;
                if (self::isSensitiveKey($key)) {
                    $out[$key] = '[REDACTED]';

                    continue;
                }
                $out[$key] = self::value($v);
            }

            return $out;
        }

        return get_debug_type($value);
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return array<string,mixed>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if (!self::isSensitiveKey($key)) {
                $out[$key] = self::value($value);
            }
        }

        return $out;
    }
}
