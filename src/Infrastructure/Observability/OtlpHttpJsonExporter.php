<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class OtlpHttpJsonExporter implements SpanExporterInterface, MetricExporterInterface, LogExporterInterface
{
    private bool $shutdownCalled = false;

    /** @param array<string,mixed> $resource */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $resource,
        private readonly int $timeoutMs = 500,
    ) {
        if ($timeoutMs < 1 || $timeoutMs > 10000) {
            throw new \InvalidArgumentException('timeoutMs is outside its allowed range.');
        }
    }

    /**
     * Bug fix #9: export() now calls postJson() instead of duplicating it.
     */
    #[\Override]
    public function export(array $spans): void
    {
        if ($spans === []) {
            return;
        }
        $payload = [
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => 'zef-observability'],
                    'spans' => array_map($this->span(...), $spans),
                ]],
            ]],
        ];
        $this->postJson($this->endpointFor('/v1/traces'), $payload);
    }

    #[\Override]
    public function exportMetrics(array $metrics): void
    {
        if ($metrics === []) {
            return;
        }
        $list = [];
        foreach ($metrics as $name => $metric) {
            $list[] = [
                'name' => $name,
                'sum' => [
                    'aggregationTemporality' => 2,
                    // CounterMeter series are monotonically increasing.
                    'isMonotonic' => true,
                    'dataPoints' => [[
                        'asDouble' => (float) $metric['sum'],
                        'timeUnixNano' => (string) TelemetryClock::nowUnixNano(),
                        'attributes' => $this->attributes($metric['attributes']),
                    ]],
                ],
            ];
        }
        $this->postJson($this->endpointFor('/v1/metrics'), [
            'resourceMetrics' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'zef-observability'],
                    'metrics' => $list,
                ]],
            ]],
        ]);
    }

    #[\Override]
    public function exportLogs(array $records): void
    {
        if ($records === []) {
            return;
        }
        $list = [];
        foreach ($records as $record) {
            $list[] = [
                'timeUnixNano' => (string) $record->timeUnixNano,
                'severityText' => $record->severity,
                'body' => ['stringValue' => $record->body],
                'attributes' => $this->attributes($record->attributes),
            ];
        }
        $this->postJson($this->endpointFor('/v1/logs'), [
            'resourceLogs' => [[
                'resource' => ['attributes' => $this->attributes($this->resource)],
                'scopeLogs' => [[
                    'scope' => ['name' => 'zef-observability'],
                    'logRecords' => $list,
                ]],
            ]],
        ]);
    }

    #[\Override]
    public function shutdown(): void
    {
        // Idempotent: Telemetry::shutdown() can reach the same exporter
        // object through the span processor AND the metric/log exporter
        // roles; a future real shutdown body must not run twice.
        if ($this->shutdownCalled) {
            return;
        }
        $this->shutdownCalled = true;
    }

    /** Extracted endpoint helper (dedup). */
    private function endpointFor(string $signalPath): string
    {
        $url = rtrim($this->endpoint, '/');

        return str_ends_with($url, $signalPath) ? $url : $url . $signalPath;
    }

    /** @return array<string,mixed> */
    private function span(SpanData $span): array
    {
        $out = [
            'traceId' => $span->context->traceId,
            'spanId' => $span->context->spanId,
            'name' => $span->name,
            'kind' => 'SPAN_KIND_SERVER',
            'startTimeUnixNano' => (string) $span->startUnixNano,
            'endTimeUnixNano' => (string) $span->endUnixNano,
            'attributes' => $this->attributes($span->attributes),
            'status' => [
                'code' => match ($span->status) {
                    'OK' => 'STATUS_CODE_OK',
                    'ERROR' => 'STATUS_CODE_ERROR',
                    default => 'STATUS_CODE_UNSET',
                },
            ],
        ];
        if ($span->parent?->isValid()) {
            $out['parentSpanId'] = $span->parent->spanId;
        }
        if ($span->statusDescription !== null) {
            $out['status']['message'] = TelemetrySanitizer::string($span->statusDescription, 1024);
        }
        if ($span->events !== []) {
            $out['events'] = array_map(
                fn (array $event): array => [
                    'name' => TelemetrySanitizer::string($event['name'], 256),
                    // OTLP/JSON field names are lowerCamelCase; the
                    // internal event shape keeps snake_case keys.
                    'timeUnixNano' => (string) $event['time_unix_nano'],
                    // Event attributes are KeyValue messages, exactly like
                    // span/resource attributes — not a plain value map.
                    'attributes' => $this->attributes($event['attributes'] ?? []),
                ],
                $span->events,
            );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return list<array{key:string,value:array<string,mixed>}>
     */
    private function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[] = ['key' => $key, 'value' => $this->anyValue($value)];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function anyValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) => $this->arrayValue($value),
            default => $this->scalarValue($value),
        };
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string,mixed>
     */
    private function arrayValue(array $value): array
    {
        return ['arrayValue' => ['values' => array_map($this->anyValue(...), array_values($value))]];
    }

    /** @return array<string,mixed> */
    private function scalarValue(mixed $value): array
    {
        return ['stringValue' => TelemetrySanitizer::string(is_string($value) ? $value : get_debug_type($value))];
    }

    /**
     * Bug fix #9: replaced @file_get_contents with scoped set_error_handler;
     * transport error message included in OtlpExporterException.
     *
     * @param array<string,mixed> $payload
     */
    private function postJson(string $url, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = "Content-Type: application/json\r\n"
            . "Accept: application/json\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $json,
                'timeout' => max(.01, $this->timeoutMs / 1000),
                'ignore_errors' => true,
            ],
        ]);
        $transportErrors = [];
        set_error_handler(static function (int $severity, string $message) use (&$transportErrors): bool {
            // Both engine arguments are consumed so the handler mirrors the
            // set_error_handler contract; the diagnostic message (not the
            // severity) is what the failure report carries.
            $transportErrors[] = [$severity, $message];

            return true;
        });

        try {
            $result = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m) === 1) {
                $status = (int) $m[1];

                break;
            }
        }
        if ($result === false) {
            $last = $transportErrors === [] ? null : $transportErrors[count($transportErrors) - 1][1];
            $reason = $last !== null ? ': ' . TelemetrySanitizer::redact($last) : '.';

            throw new OtlpExporterException('OTLP exporter transport failure' . $reason);
        }
        if ($status >= 400 && $status < 500) {
            throw new \InvalidArgumentException('OTLP exporter permanent rejection.');
        }
        if ($status >= 500) {
            throw new OtlpExporterException('OTLP exporter transient rejection.');
        }
    }
}
