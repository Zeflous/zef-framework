<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.23.0 (issue #60 P1: maps the config observability
 * port onto the existing Observability meter port — Prometheus/OTLP see the
 * secrets pipeline without the provider knowing any metric vocabulary).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Observability\MeterInterface;

/**
 * Binds {@see ConfigMetricsInterface} to the framework's existing
 * {@see MeterInterface} port. Metric vocabulary (framework-internal names,
 * `zef.` namespace, dots rendered per the Prometheus renderer):
 *
 * - `zef.config.secrets.retries.total{provider, key}` — failed attempt with
 *   retry budget left;
 * - `zef.config.secrets.success.total{provider}` — provider answered without
 *   throwing (unknown-key null included);
 * - `zef.config.secrets.fallback.total{provider}` — stale known-good value
 *   served after exhausted retries.
 *
 * Label hygiene (issue #60: "label the key name, never the resolved value"):
 * only the dotted key NAME and the provider NAME are ever used as label
 * values, each bounded to 96 characters of word characters plus `._:-` —
 * beyond that
 * the dimension collapses to `[other]`, mirroring the container service-id
 * bounding already used by {@see CounterMeter}. Cardinality stays bounded
 * by the schema-declared key set, and {@see CounterMeter}'s MAX_SERIES
 * overflow bucket is the final backstop.
 */
final readonly class MeterConfigMetrics implements ConfigMetricsInterface
{
    private const string METRIC_RETRIES = 'zef.config.secrets.retries.total';

    private const string METRIC_SUCCESS = 'zef.config.secrets.success.total';

    private const string METRIC_FALLBACK = 'zef.config.secrets.fallback.total';

    public function __construct(private MeterInterface $meter) {}

    #[\Override]
    public function secretRetry(string $provider, string $key): void
    {
        $this->meter->increment(self::METRIC_RETRIES, 1, [
            'provider' => $this->bounded($provider),
            'key' => $this->bounded($key),
        ]);
    }

    #[\Override]
    public function secretResolved(string $provider): void
    {
        $this->meter->increment(self::METRIC_SUCCESS, 1, [
            'provider' => $this->bounded($provider),
        ]);
    }

    #[\Override]
    public function secretStaleFallback(string $provider, string $key): void
    {
        $this->meter->increment(self::METRIC_FALLBACK, 1, [
            'provider' => $this->bounded($provider),
        ]);
    }

    /**
     * Bound a label dimension the same way the container meter bounds
     * service ids: well-formed short identifiers pass through, anything
     * else collapses to a single `[other]` series.
     */
    private function bounded(string $value): string
    {
        return preg_match('/^[\w.:-]{1,96}$/u', $value) === 1 ? $value : '[other]';
    }
}
