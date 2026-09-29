<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class CounterMeter implements MeterInterface
{
    private const int MAX_SERIES = 1024;

    /**
     * @var array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}>
     */
    private array $data = [];

    #[\Override]
    public function increment(string $name, float|int $value = 1, array $attributes = []): void
    {
        // Counters are monotonic (OTel spec): negative deltas previously
        // drove counts NEGATIVE, and int overflow silently became float.
        if ($value < 0 || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Counter delta must be a finite non-negative number.');
        }
        [$key, $normalized] = $this->resolveSeries($name, $attributes);
        $this->data[$key] ??= ['count' => 0, 'sum' => 0.0, 'attributes' => $normalized];
        $count = $this->data[$key]['count'];
        $next = $count + $value;
        if (is_int($count) && is_int($value) && !is_int($next)) {
            $next = PHP_INT_MAX;
        }
        $this->data[$key]['count'] = $next;
        $this->data[$key]['sum'] += (float) $value;
    }

    #[\Override]
    public function observe(string $name, float $value, array $attributes = []): void
    {
        [$key, $normalized] = $this->resolveSeries($name, $attributes);
        $this->data[$key] ??= ['count' => 0, 'sum' => 0.0, 'attributes' => $normalized];
        ++$this->data[$key]['count'];
        $this->data[$key]['sum'] += $value;
    }

    #[\Override]
    public function snapshot(): array
    {
        return $this->data;
    }

    /**
     * Extracted shared overflow/eviction logic (dedup from increment/observe).
     *
     * @param array<string,mixed> $attributes
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function resolveSeries(string $name, array $attributes): array
    {
        $normalized = $this->normalizeAttributes($name, $attributes);
        $key = $this->key($name, $normalized);
        if (!isset($this->data[$key]) && count($this->data) >= self::MAX_SERIES) {
            $overflow = ['zef.cardinality.bucket' => 'overflow'];
            $key = $this->key($name, $overflow);
            if (!isset($this->data[$key])) {
                unset($this->data[array_key_first($this->data)]);
            }
            $normalized = $overflow;
        }

        return [$key, $normalized];
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return array<string,mixed>
     */
    private function normalizeAttributes(string $name, array $attributes): array
    {
        $clean = TelemetrySanitizer::attributes($attributes);
        if (str_starts_with($name, 'zef.http.') || str_starts_with($name, 'zef.container.')) {
            $clean = $this->boundedSystemAttributes($name, $clean);
        } elseif ($name === 'zef.lifecycle.events.total') {
            $clean = $this->boundedLifecycleAttributes($clean);
        } else {
            // Other metric families keep their full (sanitized) attribute set.
        }
        ksort($clean);

        return $clean;
    }

    /**
     * HTTP/container metrics are high-cardinality hot paths: only a fixed
     * dimension allowlist survives normalization.
     *
     * @param array<string,mixed> $clean
     *
     * @return array<string,mixed>
     */
    private function boundedSystemAttributes(string $name, array $clean): array
    {
        $allowed = [];
        foreach (['http.request.method', 'http.response.status_code'] as $dimension) {
            if (array_key_exists($dimension, $clean)) {
                $allowed[$dimension] = $clean[$dimension];
            }
        }
        if ($name === 'zef.http.errors.total' && array_key_exists('exception.type', $clean)) {
            $exceptionType = $clean['exception.type'];
            if (is_string($exceptionType)) {
                $allowed['exception.type'] = TelemetrySanitizer::string($exceptionType, 128);
            }
        }
        if (
            $name === 'zef.container.resolve.duration_seconds'
            && array_key_exists('zef.service.id', $clean)
        ) {
            $service = $clean['zef.service.id'];
            if (is_string($service)) {
                $allowed['zef.service.id'] = $this->boundedServiceDimension($service);
            }
        }

        return $allowed;
    }

    /**
     * Lifecycle metrics only ever expose the bounded event.name dimension.
     *
     * @param array<string,mixed> $clean
     *
     * @return array<string,mixed>
     */
    private function boundedLifecycleAttributes(array $clean): array
    {
        $allowed = [];
        if (array_key_exists('event.name', $clean)) {
            $eventValue = $clean['event.name'];
            $event = is_string($eventValue) ? $eventValue : '';
            $allowed['event.name'] = in_array($event, [
                'worker.started', 'worker.ready', 'request.started', 'request.completed',
                'request.failed', 'worker.recovery.detected', 'worker.terminated',
                'telemetry.flush', 'telemetry.shutdown',
            ], true) ? $event : 'other';
        }

        return $allowed;
    }

    private function boundedServiceDimension(string $value): string
    {
        return preg_match('/^[\w.:-]{1,96}$/u', $value) === 1 ? $value : '[other]';
    }

    /** @param array<string,mixed> $attributes */
    private function key(string $name, array $attributes): string
    {
        return $name . '|' . json_encode(
            $attributes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
