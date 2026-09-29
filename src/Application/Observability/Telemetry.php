<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Psr\Log\LoggerInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

final class Telemetry
{
    /**
     * @var list<LogRecord>
     */
    private array $logs = [];

    /**
     * @var list<array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}>>
     */
    private array $metricDeliveryQueue = [];

    /**
     * @var list<list<LogRecord>>
     */
    private array $logDeliveryQueue = [];
    private bool $shutdown = false;

    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly MeterInterface $meter,
        private readonly BatchSpanProcessor $processor,
        private readonly ?MetricExporterInterface $metricExporter = null,
        private readonly ?LogExporterInterface $logExporter = null,
        private readonly bool $enabled = true,
        private readonly ?EnvInterface $env = null,
    ) {}

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
    ): self {
        return TelemetryFactory::fromEnvironment($logger, $registerShutdownHook, $exporterFactory, $env);
    }

    /** @param array<string,mixed> $attributes */
    public function startSpan(string $name, array $attributes = [], ?SpanContext $parent = null): SpanInterface
    {
        return $this->tracer->startSpan($name, $attributes, $parent);
    }

    public function tracer(): TracerInterface
    {
        return $this->tracer;
    }

    public function meter(): MeterInterface
    {
        return $this->meter;
    }

    public function extract(string $traceParent, string $traceState = ''): ?SpanContext
    {
        return TraceContextPropagator::extract($traceParent, $traceState !== '' ? $traceState : null);
    }

    /** @param array<string,mixed> $attributes */
    public function recordLog(string $severity, string $body, array $attributes = []): void
    {
        if (!$this->enabled || $this->shutdown || count($this->logs) >= 256) {
            return;
        }
        $this->logs[] = new LogRecord(
            strtoupper($severity),
            TelemetrySanitizer::string($body),
            TelemetryClock::nowUnixNano(),
            TelemetrySanitizer::attributes($attributes),
        );
    }

    public function flush(): void
    {
        if (!$this->enabled || $this->shutdown) {
            return;
        }
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.flush']);
        $this->processor->flush();
        $this->enqueueDelivery();
        $this->drainDelivery();
    }

    public function shutdown(): void
    {
        if (!$this->enabled || $this->shutdown) {
            return;
        }
        // Set the flag FIRST so no hook can re-enter shutdown(); the final
        // lifecycle counters below are captured by enqueueDelivery() and
        // reach the exporter instead of being dead writes.
        $this->shutdown = true;
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.flush']);
        $this->meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'telemetry.shutdown']);

        try {
            $this->processor->shutdown();
        } catch (\Throwable) {
            // Processor shutdown is best-effort: a failing span exporter
            // must not prevent the queued metric/log drain below.
        }
        $this->enqueueDelivery();
        $env = $this->env ?? new Env();
        $deadline = microtime(true) + $env->readInt('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000) / 1000;
        while (
            ($this->metricDeliveryQueue !== [] || $this->logDeliveryQueue !== [])
            && microtime(true) < $deadline
        ) {
            $this->drainOne();
        }

        try {
            $this->metricExporter?->shutdown();
        } catch (\Throwable) {
            // Metric exporter shutdown is best-effort: the drain loop above
            // already shipped everything it could within the deadline.
        }

        try {
            if ($this->logExporter instanceof LogExporterInterface && $this->logExporter !== $this->metricExporter) {
                $this->logExporter->shutdown();
            }
        } catch (\Throwable) {
            // Log exporter shutdown is best-effort, same reasoning as the
            // metric exporter above — shutdown() must never throw.
        }
        $this->logs = [];
        $this->metricDeliveryQueue = [];
        $this->logDeliveryQueue = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isInMemoryExporter(): bool
    {
        return $this->processor->isInMemoryExporter();
    }

    private function enqueueDelivery(): void
    {
        if ($this->metricExporter instanceof MetricExporterInterface && count($this->metricDeliveryQueue) < 1024) {
            $this->metricDeliveryQueue[] = $this->meter->snapshot();
        }
        if ($this->logs !== []) {
            $pending = $this->logs;
            $this->logs = [];
            if (count($this->logDeliveryQueue) < 1024) {
                $this->logDeliveryQueue[] = $pending;
            }
        }
    }

    private function drainDelivery(): void
    {
        while ($this->metricDeliveryQueue !== []) {
            $batch = array_shift($this->metricDeliveryQueue);
            if (!$this->metricExporter instanceof MetricExporterInterface) {
                continue;
            }

            try {
                $this->metricExporter->exportMetrics($batch);
            } catch (\Throwable) {
                // Exporter failures must not abort the drain loop: the
                // remaining batches still get their chance to ship.
            }
        }
        while ($this->logDeliveryQueue !== []) {
            $batch = array_shift($this->logDeliveryQueue);
            if (!$this->logExporter instanceof LogExporterInterface) {
                continue;
            }

            try {
                $this->logExporter->exportLogs($batch);
            } catch (\Throwable) {
                // Same best-effort contract for log batches.
            }
        }
    }

    private function drainOne(): void
    {
        if ($this->metricDeliveryQueue !== [] && $this->metricExporter instanceof MetricExporterInterface) {
            $batch = array_shift($this->metricDeliveryQueue);

            try {
                $this->metricExporter->exportMetrics($batch);
            } catch (\Throwable) {
                // Failed metric export: stop this round; shutdown()'s loop
                // retries on the next tick before the deadline.
                return;
            }
        }
        if ($this->logDeliveryQueue !== [] && $this->logExporter instanceof LogExporterInterface) {
            $batch = array_shift($this->logDeliveryQueue);

            try {
                $this->logExporter->exportLogs($batch);
            } catch (\Throwable) {
                // Best-effort log export: a failure here is swallowed so the
                // shutdown loop keeps draining the metric side.
            }
        }
    }
}
