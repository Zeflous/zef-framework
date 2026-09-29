<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Renders a MeterInterface snapshot into the Prometheus text exposition
 * format (version 0.0.4) consumed by Prometheus / VictoriaMetrics / etc.
 *
 * Semantics (N-20, issue #176 — aligned with the code, not ahead of it):
 * - counter series (increment)  -> `<name>{labels} count` ONLY. A pure
 *   counter has count == sum, so `_sum` would duplicate the value and is
 *   not emitted.
 * - histogram-style observations (observe) where sum != count ->
 *   `<name>` plus the classic histogram members `<name>_count` and
 *   `<name>_sum`
 * - metric names are sanitized to `[a-zA-Z0-9_:]`, label names to
 *   `[a-zA-Z0-9_]`; label values are escaped per the exposition format
 * - series with no attributes omit the `{...}` block entirely
 *
 * The renderer is stateless and performs no I/O; the HTTP surface lives in
 * the calling module (e.g. `Zef\Module\Health\MetricsHandler`).
 */
final class PrometheusRenderer
{
    private const string METRIC_NAME_DEFAULT = 'zef_unnamed_metric';
    private const string LABEL_NAME_DEFAULT = 'zef_unnamed_label';
    private const int MAX_SERIES = 4096;

    /** @param array<string,string> $staticLabels appended to every series */
    public function render(MeterInterface $meter, array $staticLabels = []): string
    {
        $lines = [];
        $emittedTypes = [];
        $seriesCount = 0;
        foreach ($meter->snapshot() as $rawKey => $series) {
            ++$seriesCount;
            if ($seriesCount > self::MAX_SERIES) {
                break;
            }
            $identity = $this->seriesIdentity($rawKey, $series);
            $labelBlock = $this->labelBlock($identity['attributes'], $staticLabels);
            $count = is_numeric($series['count'] ?? null) ? (float) $series['count'] : 0.0;
            $sum = is_numeric($series['sum'] ?? null) ? (float) $series['sum'] : 0.0;
            $this->appendSeriesLines(
                $lines,
                $emittedTypes,
                $identity['metric'],
                $labelBlock,
                $count,
                $sum,
            );
        }
        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * CounterMeter snapshot keys are composites: "<metric>|<json attrs>";
     * histogram-style series keep their attributes on the series itself.
     *
     * @param array{count?:float|int,sum?:float,attributes?:array<string,mixed>} $series
     *
     * @return array{metric:string,attributes:array<mixed,mixed>}
     */
    private function seriesIdentity(mixed $rawKey, array $series): array
    {
        $rawKey = is_string($rawKey) ? $rawKey : '';
        $pipe = strpos($rawKey, '|');
        if ($pipe !== false) {
            $decoded = json_decode(substr($rawKey, $pipe + 1), true);

            return [
                'metric' => $this->sanitizeName(substr($rawKey, 0, $pipe)),
                'attributes' => is_array($decoded) ? $decoded : [],
            ];
        }

        return [
            'metric' => $this->sanitizeName($rawKey),
            'attributes' => is_array($series['attributes'] ?? null) ? $series['attributes'] : [],
        ];
    }

    /**
     * Appends one series (and its classic histogram members when the
     * observations diverge: count != sum) to the output lines.
     *
     * @param list<string>        $lines        output buffer
     * @param array<string, true> $emittedTypes TYPE header lines already emitted
     */
    private function appendSeriesLines(
        array &$lines,
        array &$emittedTypes,
        string $metricName,
        string $labelBlock,
        float $count,
        float $sum,
    ): void {
        if (!isset($emittedTypes[$metricName])) {
            $emittedTypes[$metricName] = true;
            $lines[] = "# TYPE {$metricName} counter";
        }
        $lines[] = sprintf('%s%s %s', $metricName, $labelBlock, $this->number($count));
        // Observation series (count != sum): expose classic histogram members.
        // The comparison deliberately stays in POSITIVE form: with $sum = NAN
        // every float comparison is false, so a NaN series renders count-only
        // (the historical NaN contract). An inverted `if (<= eps) return;`
        // guard is NOT equivalent — it would flip NaN to emit _sum = NaN.
        if (abs($sum - $count) > PHP_FLOAT_EPSILON) {
            if (!isset($emittedTypes[$metricName . '_sum'])) {
                $emittedTypes[$metricName . '_sum'] = true;
                $lines[] = "# TYPE {$metricName}_sum counter";
                $lines[] = "# TYPE {$metricName}_count counter";
            }
            $lines[] = sprintf('%s_sum%s %s', $metricName, $labelBlock, $this->number($sum));
            $lines[] = sprintf('%s_count%s %s', $metricName, $labelBlock, $this->number($count));
        }
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return self::METRIC_NAME_DEFAULT;
        }
        $clean = preg_replace('/[^\w:]/', '_', $name);
        $clean = is_string($clean) ? $clean : self::METRIC_NAME_DEFAULT;
        if (preg_match('/^[[:alpha:]_:]/', $clean) !== 1) {
            return 'zef_' . $clean;
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,string> $staticLabels
     */
    private function labelBlock(array $attributes, array $staticLabels): string
    {
        $pairs = [];
        foreach ($staticLabels as $name => $value) {
            $label = $this->sanitizeLabelName((string) $name);
            $pairs[$label] = is_scalar($value) ? (string) $value : '';
        }
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (!is_scalar($value)) {
                // Non-scalar dimensions are dropped, never stringified blindly.
                continue;
            }
            $pairs[$this->sanitizeLabelName($name)] = (string) $value;
        }
        if ($pairs === []) {
            return '';
        }
        ksort($pairs);
        $encoded = [];
        foreach ($pairs as $name => $value) {
            $encoded[] = sprintf('%s="%s"', $name, $this->escapeValue($value));
        }

        return '{' . implode(',', $encoded) . '}';
    }

    private function sanitizeLabelName(string $name): string
    {
        $clean = preg_replace('/\W/', '_', $name);
        $clean = is_string($clean) ? $clean : self::LABEL_NAME_DEFAULT;
        if ($clean === '' || preg_match('/^[[:alpha:]_]/', $clean) !== 1) {
            return 'lbl_' . $clean;
        }

        return $clean;
    }

    private function escapeValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    private function number(float $value): string
    {
        if (!is_finite($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }

            return $value > 0 ? '+Inf' : '-Inf';
        }
        $formatted = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($formatted) ? $formatted : '0.0';
    }
}
