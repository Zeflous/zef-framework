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

    /**
     * Lowercase key suffixes whose `key: value` / `key=value` pairs must be
     * redacted. Mirrors the keyword vocabulary of the pair matcher below.
     */
    private const array REDACT_PAIR_KEYS = [
        'password', 'passwd', 'pwd', 'passphrase', 'secret', 'token',
        'authorization', 'credentials', 'credential', 'apikey', 'api-key',
        'api_key', 'clientsecret', 'client-secret', 'client_secret',
        'accesstoken', 'access-token', 'access_token', 'refreshtoken',
        'refresh-token', 'refresh_token', 'privatekey', 'private-key',
        'private_key',
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
        $value = self::ensureUtf8(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');
        if (strlen($value) <= $limit) {
            return $value;
        }

        return self::truncate($value, $limit);
    }

    public static function redact(string $value, int $limit = 2048): string
    {
        // P-21 (issue #172): pairs may quote the key and/or the value — JSON-style
        // log lines ("password":"x" or 'token'='y') previously passed through
        // UNREDACTED because the separator had to follow the bare keyword. The
        // vocabulary is widened (pwd, passphrase, credentials, private key) to
        // track the config-surface matcher used by the inspector.
        // The pair pattern is Unicode-aware ('u' flag), so scrub invalid UTF-8
        // first: raw bytes would make the preg_* call fail and skip redaction.
        $value = self::ensureUtf8($value);
        $value = self::redactSensitivePairs($value);
        // The pattern is Unicode-aware ('u' flag over UTF-8-scrubbed input,
        // mirroring redactSensitivePairs); the token charset stays the RFC
        // 6750 b64token ASCII grammar BY DESIGN (hex/base64 credentials).
        $value = preg_replace('/\bBearer\s+[a-z0-9._~+\/-]+=*/iu', 'Bearer [REDACTED]', $value) ?? $value;

        return self::string($value, $limit);
    }

    public static function value(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => self::string($value),
            is_int($value), is_bool($value), $value === null => $value,
            is_float($value) => self::floatValue($value),
            is_array($value) => self::arrayValue($value),
            default => get_debug_type($value),
        };
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

    /**
     * Invalid UTF-8 made json_encode(JSON_THROW_ON_ERROR) throw deep
     * inside the meter/exporter (JsonException leaking into the
     * request path, whole export batches dropped). Scrub instead.
     */
    private static function ensureUtf8(string $value): string
    {
        if (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($value, 'UTF-8');
        }
        $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return is_string($converted) ? $converted : '';
    }

    private static function truncate(string $value, int $limit): string
    {
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

    /**
     * Redacts `key=value` / `"key": "value"` pairs whose key ends with a
     * sensitive vocabulary word. Staged as a preg_replace_callback over one
     * flat pattern — the keyword vocabulary lives in PHP (REDACT_PAIR_KEYS),
     * keeping the regex complexity low while preserving the previous match
     * semantics (keyword as a suffix of the word before the separator).
     */
    private static function redactSensitivePairs(string $value): string
    {
        $redacted = preg_replace_callback(
            '/([\'"]?)(\w+(?:[-_]\w+)*)\1\s*[:=]\s*([\'"]?)([^\s,;\'"]+)\3/iu',
            static fn (array $m): string => self::isSensitivePairKey($m[2]) ? $m[2] . '=[REDACTED]' : $m[0],
            $value,
        );

        return $redacted ?? $value;
    }

    private static function isSensitivePairKey(string $word): bool
    {
        $needle = strtolower($word);

        return array_any(
            self::REDACT_PAIR_KEYS,
            static fn (string $suffix): bool => str_ends_with($needle, $suffix),
        );
    }

    private static function floatValue(float $value): float|string
    {
        // NaN/INF passed through unvalidated and made json_encode of
        // the OTLP payload throw → export() threw → the processor
        // dropped the whole batch. Emit the string form instead.
        return is_finite($value) ? $value : (string) $value;
    }

    /**
     * @param array<array-key,mixed> $value
     *
     * @return array<array-key,mixed>
     */
    private static function arrayValue(array $value): array
    {
        $out = [];
        foreach (array_slice($value, 0, 32, true) as $k => $v) {
            $key = (string) $k;
            $out[$key] = self::isSensitiveKey($key) ? '[REDACTED]' : self::value($v);
        }

        return $out;
    }
}
