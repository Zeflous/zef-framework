<?php

declare(strict_types=1);

/*
 * Issue #55 step 3 (observability module) — guard test for the env-port
 * migration of Telemetry + BatchSpanProcessor.
 *
 * Dua invariant dikawal di sini:
 *   1. fromEnvironment membaca SELURUH knob melalui EnvInterface — stub env
 *      dengan nilai kaleng sepenuhnya menentukan konfigurasi telemetri yang
 *      dihasilkan (bukti injeksi port, bukan facade statis).
 *   2. File observability bebas panggilan facade statis Env:: — sensus
 *      statis menurun monoton per modul (acceptance issue #55).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\OtlpExporterFactoryInterface;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;

/**
 * @internal
 */
final class TelemetryEnvPortTest extends TestCase
{
    public function testFromEnvironmentReadsEveryKnobThroughTheEnvPort(): void
    {
        $env = new TelemetryStubEnv([
            'ZEF_OTEL_ENABLED' => 'true',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/collect',
            'ZEF_OTEL_SERVICE_NAME' => 'stub-service',
            'ZEF_OTEL_EXPORT_TIMEOUT_MS' => '700',
            'ZEF_OTEL_MAX_QUEUE' => '2048',
            'ZEF_OTEL_BATCH_SIZE' => '64',
            'ZEF_OTEL_SHUTDOWN_DRAIN_MS' => '50',
        ]);
        $factory = new TelemetrySpyExporterFactory();

        // Tidak ada putenv sama sekali — seluruh konfigurasi datang dari port.
        $t = Telemetry::fromEnvironment(null, false, $factory, $env);

        self::assertSame(1, $factory->createCalls, 'exporter factory wajib dipanggil via port (endpoint terbaca dari stub)');
        self::assertSame('http://127.0.0.1:9/collect', $factory->lastEndpoint);
        self::assertSame(700, $factory->lastTimeoutMs, 'ZEF_OTEL_EXPORT_TIMEOUT_MS wajib mengalir dari stub');
        self::assertSame('stub-service', $factory->lastResource['service.name'], 'ZEF_OTEL_SERVICE_NAME wajib mengalir dari stub');

        // Jalur span berjalan dan flush meniris ke exporter spy.
        $span = $t->startSpan('port.test');
        $span->end();
        $t->flush();
        self::assertGreaterThanOrEqual(1, $factory->exporter->spanBatches, 'flush wajib mengekspor batch span');
    }

    public function testDisabledThroughStubEnvSkipsExporterConstruction(): void
    {
        $env = new TelemetryStubEnv(['ZEF_OTEL_ENABLED' => 'false']);
        $factory = new TelemetrySpyExporterFactory();

        $t = Telemetry::fromEnvironment(null, false, $factory, $env);

        self::assertSame(0, $factory->createCalls, 'exporter tidak boleh dibangun saat ZEF_OTEL_ENABLED=false via port');
        $span = $t->startSpan('noop.test');
        $span->end();
        $t->flush();
    }

    public function testObservabilityLayerCarriesNoStaticFacadeCalls(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/src/Application/Observability/Telemetry.php',
            dirname(__DIR__, 2) . '/src/Application/Observability/BatchSpanProcessor.php',
        ];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'Env::',
                $source,
                basename($file) . ' tidak boleh memanggil facade statis Env:: lagi (issue #55 step 3 — migrasi observability).',
            );
        }
    }
}

// ------------------------------------------------------------- Fixtures

/**
 * Sumber env kaleng — nilai yang dikembalikan sepenuhnya dikendalikan test,
 * membuktikan fromEnvironment membaca melalui port, bukan getenv().
 */
final class TelemetryStubEnv implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null) {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        return isset($this->values[$name]) && filter_var($this->values[$name], FILTER_VALIDATE_BOOL);
    }

    #[\Override]
    public function readBoolStrict(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }

        throw new \InvalidArgumentException($name . "='" . $raw . "' is not a recognized boolean.");
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = $this->values[$name] ?? '';

        return $raw === ''
            ? []
            : array_values(array_filter(
                array_map(trim(...), explode(',', $raw)),
                static fn (string $v): bool => $v !== '',
            ));
    }
}

final class TelemetrySpyExporter implements LogExporterInterface, MetricExporterInterface, SpanExporterInterface
{
    public int $spanBatches = 0;

    public int $logBatches = 0;

    public int $metricBatches = 0;

    /** @param list<mixed> $spans */
    #[\Override]
    public function export(array $spans): void
    {
        if ($spans !== []) {
            ++$this->spanBatches;
        }
    }

    /** @param list<LogRecord> $records */
    #[\Override]
    public function exportLogs(array $records): void
    {
        if ($records !== []) {
            ++$this->logBatches;
        }
    }

    /** @param array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}> $metrics */
    #[\Override]
    public function exportMetrics(array $metrics): void
    {
        if ($metrics !== []) {
            ++$this->metricBatches;
        }
    }

    #[\Override]
    public function shutdown(): void {}
}

final class TelemetrySpyExporterFactory implements OtlpExporterFactoryInterface
{
    public int $createCalls = 0;

    public string $lastEndpoint = '';

    public int $lastTimeoutMs = 0;

    /** @var array<string, mixed> */
    public array $lastResource = [];

    public TelemetrySpyExporter $exporter;

    public function __construct()
    {
        $this->exporter = new TelemetrySpyExporter();
    }

    #[\Override]
    public function create(
        string $endpoint,
        array $resource,
        int $timeoutMs,
    ): LogExporterInterface&MetricExporterInterface&SpanExporterInterface {
        ++$this->createCalls;
        $this->lastEndpoint = $endpoint;
        $this->lastResource = $resource;
        $this->lastTimeoutMs = $timeoutMs;

        return $this->exporter;
    }
}
