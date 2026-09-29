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
