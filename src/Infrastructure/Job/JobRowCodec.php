<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: row codec of the durable JobQueueInterface adapter.
 */

namespace Zef\Framework\Job;

/**
 * Pure (de)serialization between PDO queue rows and {@see JobEnvelope}
 * values: JSON payload/header round-trips with loud failure modes, plus
 * the defensive row narrowing PDO drivers need (int columns on SQLite,
 * numeric strings on MySQL, NULL-able correlation/trace fields).
 *
 * Every method is pure, so the codec is a stateless static surface —
 * no instance state, no wiring, safe to call from anywhere.
 */
final class JobRowCodec
{
    /** Decimal digit alphabet of the order-preserving available_at encoding. */
    private const string DIGITS = '0123456789';

    /** The 9's complement digit alphabet — the negative-encoding mirror of {@see DIGITS}. */
    private const string DIGITS_COMPLEMENT = '9876543210';

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): JobEnvelope
    {
        return new JobEnvelope(
            self::str($row['job_id'] ?? null),
            self::str($row['job_type'] ?? null),
            self::decodePayload(self::str($row['payload'] ?? null)),
            self::intVal($row['available_at'] ?? null),
            self::intVal($row['priority'] ?? null),
            self::intVal($row['attempt'] ?? null),
            $row['correlation_id'] === null ? null : self::str($row['correlation_id']),
            $row['trace_parent'] === null ? null : self::str($row['trace_parent']),
            self::decodeHeaders(self::str($row['headers'] ?? null)),
        );
    }

    public static function encodePayload(mixed $payload): string
    {
        try {
            return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Job payload must be JSON-serializable.', 0, $error);
        }
    }

    /**
     * Row narrowing: PDO rows are array<string, mixed>; ids are strings.
     * Public for the queue's DELETE-claim (dequeue) WHERE binding, which
     * narrows the same freshly-selected job_id column value.
     */
    public static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Encode a nano timestamp as a 20-character string whose lexicographic
     * order equals numeric order over the entire signed 64-bit domain (the
     * parity contract pinned in docs/JOB-QUEUE-PARITY.md, issue #272).
     *
     * Lua numbers are doubles, exact only up to 2^53 — one nanosecond
     * timestamp (~1.7e18) already exceeds that, so two distinct deadlines
     * can collapse to the same double and silently swap order. Non-negative
     * deadlines are zero-padded to 20 digits — byte-identical to the
     * v2.32-v2.34 storage, so existing entries and the Lua comparisons keep
     * their exact bytes. Negative deadlines are '-' plus the 9's complement
     * of the 19-digit zero-padded magnitude: the leading '-' (0x2D) sorts
     * before every digit (0x30), so all negatives precede all positives, and
     * the per-digit complement restores magnitude order inside the negatives.
     */
    public static function encodeNano(int $value): string
    {
        if ($value >= 0) {
            return sprintf('%020d', $value);
        }
        $magnitude = str_pad(ltrim((string) $value, '-'), 19, '0', \STR_PAD_LEFT);

        return '-' . strtr($magnitude, self::DIGITS, self::DIGITS_COMPLEMENT);
    }

    /**
     * Decode the stored 20-character representation back to the plain
     * numeric string {@see hydrate()} casts — mirroring the complement
     * transform of {@see encodeNano()} ('-9223372036854775808' round-trips
     * PHP_INT_MIN exactly, the magnitude never touching a float).
     *
     * Anything that is not one of the two well-formed shapes — 20 digits,
     * or '-' plus 19 digits — is a corrupt field: an unpadded negative
     * written by a pre-v2.34.1 release, or raw bytes that never came from
     * {@see encodeNano()}. The claim has already destroyed the entry, so the
     * hydrate failure surfaces exactly once and the queue keeps flowing —
     * the documented corrupt-entry doctrine (docs/JOB-QUEUE-PARITY.md row
     * 10); realistic queues (non-negative deadlines) are unaffected because
     * their stored bytes are unchanged.
     */
    public static function decodeNano(string $stored): string
    {
        if (strlen($stored) === 20 && strspn($stored, self::DIGITS) === 20) {
            return $stored;
        }
        if (strlen($stored) === 20 && $stored[0] === '-' && strspn(substr($stored, 1), self::DIGITS) === 19) {
            $magnitude = ltrim(strtr(substr($stored, 1), self::DIGITS_COMPLEMENT, self::DIGITS), '0');

            return '-' . ($magnitude === '' ? '0' : $magnitude);
        }

        throw new RedisJobQueueException('Job queue entry has a corrupt available_at field.');
    }

    private static function decodePayload(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw JobExecutionException::corruptPayload($error);
        }
    }

    /**
     * Header round-trip narrowing: entries that lost their string type in
     * storage cannot satisfy the envelope contract and are dropped.
     *
     * @return array<string, string>
     */
    private static function decodeHeaders(string $payload): array
    {
        $decoded = self::decodePayload($payload);
        $headers = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** Row narrowing: numeric columns (int on SQLite, string on MySQL PDO). */
    private static function intVal(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
