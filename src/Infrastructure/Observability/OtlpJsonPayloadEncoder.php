<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * OTLP/JSON attribute/KeyValue encoder for {@see OtlpHttpJsonExporter},
 * extracted so the exporter stays inside the class size budget
 * (php:S2042). Encoding logic is byte-identical to the exporter's former
 * private helpers; the encoder is stateless, so every entry point is
 * static (no constructor, no instantiation site).
 */

namespace Zef\Framework\Observability;

final class OtlpJsonPayloadEncoder
{
    private function __construct()
    {
        // Intentionally empty: stateless encoder — the private constructor
        // only exists to prevent instantiation of this all-static class.
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return list<array{key:string,value:array<string,mixed>}>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[] = ['key' => $key, 'value' => self::anyValue($value)];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function anyValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) => self::arrayValue($value),
            default => self::scalarValue($value),
        };
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string,mixed>
     */
    private static function arrayValue(array $value): array
    {
        return ['arrayValue' => ['values' => array_map(self::anyValue(...), array_values($value))]];
    }

    /** @return array<string,mixed> */
    private static function scalarValue(mixed $value): array
    {
        return ['stringValue' => TelemetrySanitizer::string(is_string($value) ? $value : get_debug_type($value))];
    }
}
