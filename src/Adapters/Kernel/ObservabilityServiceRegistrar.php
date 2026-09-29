<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: observability service wiring.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; registration order unchanged).
 */

namespace Zef\Framework\Kernel;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigMetricsInterface;
use Zef\Framework\Config\MeterConfigMetrics;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\OtlpExporterFactory;
use Zef\Framework\Observability\OtlpExporterFactoryInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\TracerInterface;

/**
 * @internal
 *
 * Observability service registrations of the kernel composition root
 * (extracted from {@see Application}).
 *
 * Every service is registered behind its factory closure, so the wiring is
 * lazy and overridable by applications re-registering the same service ids.
 */
final class ObservabilityServiceRegistrar
{
    public static function register(Container $container, ?LoggerInterface $logger): void
    {
        // Issue #36 exit ramp (OtlpExporter): the default exporter factory is
        // an ordinary container service — the default wiring is a config-level
        // decision applications can override by re-registering the port.
        $container->register(
            OtlpExporterFactoryInterface::class,
            static fn (): OtlpExporterFactoryInterface => new OtlpExporterFactory(),
            [],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            Telemetry::class,
            static function (ContainerInterface $c) use ($logger): Telemetry {
                /** @var OtlpExporterFactoryInterface $exporterFactory */
                $exporterFactory = $c->get(OtlpExporterFactoryInterface::class);

                /** @var EnvInterface $env */
                $env = $c->get(EnvInterface::class);

                return Telemetry::fromEnvironment($logger, true, $exporterFactory, $env);
            },
            [OtlpExporterFactoryInterface::class, EnvInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            TracerInterface::class,
            static function (ContainerInterface $c): TracerInterface {
                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return $telemetry->tracer();
            },
            [Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            MeterInterface::class,
            static function (ContainerInterface $c): MeterInterface {
                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return $telemetry->meter();
            },
            [Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        // v2.23.0 (issue #60 P1): config observability port over the meter.
        $container->register(
            ConfigMetricsInterface::class,
            static function (ContainerInterface $c): ConfigMetricsInterface {
                /** @var MeterInterface $meter */
                $meter = $c->get(MeterInterface::class);

                return new MeterConfigMetrics($meter);
            },
            [MeterInterface::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
        $container->register(
            TelemetryLogger::class,
            static function (ContainerInterface $c): TelemetryLogger {
                /** @var LoggerInterface $logger */
                $logger = $c->get(LoggerInterface::class);

                /** @var Telemetry $telemetry */
                $telemetry = $c->get(Telemetry::class);

                return new TelemetryLogger($logger, $telemetry);
            },
            [LoggerInterface::class, Telemetry::class],
            'framework',
            ServiceLifetime::SINGLETON,
        );
    }
}
