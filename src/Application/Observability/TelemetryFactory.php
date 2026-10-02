<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

/**
 * Composition of a Telemetry instance from environment knobs.
 *
 * Every construction decision that used to live inline in
 * Telemetry::fromEnvironment() lives here so the Telemetry facade itself
 * stays small (php:S2042/S1200); behaviour and the public factory entry
 * point are unchanged — Telemetry::fromEnvironment() delegates here.
 */
final class TelemetryFactory
{
    /**
     * Bug fix #10: added $registerShutdownHook parameter.
     *
     * Issue #36 exit ramp: the default OTLP exporter is composed through the
     * OtlpExporterFactoryInterface port; the kernel composition root wires the
     * default factory via container config. Endpoint validation and every env
     * knob stay here — only the concrete exporter construction is delegated.
     *
     * Issue #55 step 3 (observability module): every env knob is read through
     * the injected EnvInterface port — the static facade is gone from this
     * file. The parameter is optional and defaults to the concrete Env, so
     * existing callers keep working unchanged; the kernel composition root
     * passes the container-bound port.
     */
    public static function fromEnvironment(
        ?LoggerInterface $logger = null,
        bool $registerShutdownHook = true,
        ?OtlpExporterFactoryInterface $exporterFactory = null,
        ?EnvInterface $env = null,
    ): Telemetry {
        $env ??= new Env();
        // Issue #355 (C-4): strict fail-closed parsing (audit #304 grammar) —
        // an unrecognized ZEF_OTEL_ENABLED spelling now refuses to boot
        // instead of silently disabling telemetry.
        $enabled = $env->readBoolStrict('ZEF_OTEL_ENABLED', false);
        $logger ??= new NullLogger();
        if (!$enabled) {
            return new Telemetry(
                new NoopTracer(),
                new CounterMeter(),
                new BatchSpanProcessor(new InMemorySpanExporter()),
                null,
                null,
                false,
                $env,
            );
        }
        $endpoint = trim($env->readString('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT'));
        if ($endpoint !== '') {
            self::validateEndpoint($endpoint);
        }
        $timeout = $env->readInt('ZEF_OTEL_EXPORT_TIMEOUT_MS', 500, 1, 10000, true);
        $queue = $env->readInt('ZEF_OTEL_MAX_QUEUE', 1024, 1, 8192, true);
        $batch = $env->readInt('ZEF_OTEL_BATCH_SIZE', 128, 1, $queue, true);
        $env->readInt('ZEF_OTEL_RETRY_ATTEMPTS', 2, 0, 10, true);
        $env->readInt('ZEF_OTEL_RETRY_DELAY_MS', 100, 0, 10000, true);
        $env->readInt('ZEF_OTEL_RETRY_DELAY_CAP_MS', 1000, 0, 60000, true);
        $env->readInt('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000, true);
        $resource = [
            'service.name' => $env->readString('ZEF_OTEL_SERVICE_NAME', 'zef-application'),
            'telemetry.sdk.name' => 'zef-observability',
            'telemetry.sdk.language' => 'php',
        ];
        $exporter = $endpoint !== '' && $exporterFactory instanceof OtlpExporterFactoryInterface
            ? $exporterFactory->create($endpoint, $resource, $timeout)
            : null;
        $spanExporter = $exporter ?? new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($spanExporter, $queue, $batch);
        $t = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, $exporter, $exporter, true, $env);
        if ($registerShutdownHook) {
            register_shutdown_function($t->shutdown(...));
        }

        return $t;
    }

    private static function validateEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT must be an absolute HTTP/HTTPS URI.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(
                'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT must not contain embedded credentials.'
            );
        }
    }
}
