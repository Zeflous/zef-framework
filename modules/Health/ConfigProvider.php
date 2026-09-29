<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo modules
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Module\Health;

use Psr\Container\ContainerInterface;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Observability\Telemetry;

final class ConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'health';
    }

    #[\Override]
    public function getConfig(): array
    {
        return [
            'services' => [
                'health.handler.live' => ['factory' => static fn (): LiveHandler => new LiveHandler(), 'deps' => []],
                'health.handler.ready' => ['factory' => static fn (): ReadyHandler => new ReadyHandler(), 'deps' => []],
                // v2.8.0 — aggregate health + Prometheus metrics.
                'health.canary' => ['factory' => static fn (): CanaryService => new CanaryService(), 'deps' => []],
                'health.indicator.container' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                    ): ContainerHealthIndicator => new ContainerHealthIndicator($c),
                    'deps' => [],
                    'tags' => ['health.indicator'],
                ],
                'health.aggregator' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                        TaggedServiceLocator $locator,
                    ): HealthAggregator => new HealthAggregator($locator->resolveAll('health.indicator')),
                    'deps' => [TaggedServiceLocator::class],
                ],
                'health.handler.aggregate' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                        HealthAggregator $aggregator,
                    ): AggregateHealthHandler => new AggregateHealthHandler($aggregator),
                    'deps' => ['health.aggregator'],
                ],
                'health.metrics.renderer' => [
                    'factory' => static fn (): PrometheusRenderer => new PrometheusRenderer(),
                    'deps' => [],
                ],
                'health.handler.metrics' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                        Telemetry $t,
                        PrometheusRenderer $r,
                    ): MetricsHandler => new MetricsHandler($t, $r),
                    'deps' => [Telemetry::class, 'health.metrics.renderer'],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
            ],
            'routes' => [
                ['method' => 'GET', 'path' => '/health/live', 'handler' => 'health.handler.live', 'priority' => 2000],
                ['method' => 'GET', 'path' => '/health/ready', 'handler' => 'health.handler.ready', 'priority' => 2000],
                // v2.8.0 — aggregate health (503 when any indicator is down).
                [
                    'method' => 'GET',
                    'path' => '/health',
                    'handler' => 'health.handler.aggregate',
                    'priority' => 2000,
                    'name' => 'health.aggregate',
                ],
                // v2.8.0 — Prometheus text exposition of the meter snapshot.
                [
                    'method' => 'GET',
                    'path' => '/metrics',
                    'handler' => 'health.handler.metrics',
                    'priority' => 2000,
                    'name' => 'health.metrics',
                ],
            ],
        ];
    }
}
