<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 5 — zona app-observability (ronde 3).
 *
 * Kurikulum: tambangan mutan lolos baseline (Telemetry 32 escape riil) +
 * audit adversarial kelas observability: batas env yang DIPAKAI (bukan
 * read-and-discard), guard tiga cabang, drain pipeline metric→log,
 * sanitizer UTF-8/truncation grammar, meter cardinality overflow,
 * span lifecycle, propagator grammar, health containment.
 *
 * Setiap test mewakili skenario adversarial nyata yang membunuh mutan
 * spesifik, bukan produksi mekanis.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationHeaders;
use Zef\Framework\Observability\CorrelationPropagator;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\HealthIndicatorInterface;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopSpan;
use Zef\Framework\Observability\OtlpExporterFactory;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryClock;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\TraceContextPropagator;
use Zef\Framework\Observability\Tracer;

/**
 * @internal
 */
final class EdgeMatrixObservabilityTest extends TestCase
{
    // ------------------------------------------------------------------
    // Fixtures (spy eksplisit, bukan mock framework)
    // ------------------------------------------------------------------

    private const array ENV_KEYS = [
        'ZEF_OTEL_ENABLED', 'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT',
        'ZEF_OTEL_EXPORT_TIMEOUT_MS', 'ZEF_OTEL_MAX_QUEUE', 'ZEF_OTEL_BATCH_SIZE',
        'ZEF_OTEL_RETRY_ATTEMPTS', 'ZEF_OTEL_RETRY_DELAY_MS',
        'ZEF_OTEL_RETRY_DELAY_CAP_MS', 'ZEF_OTEL_SHUTDOWN_DRAIN_MS',
        'ZEF_OTEL_SERVICE_NAME', 'ZEF_RUNTIME_SATURATION_PERCENT',
    ];

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    // ------------------------------------------------------------------
    // Zona 1 — Telemetry: konfigurasi env yang DIPAKAI (bukan discard)
    // ------------------------------------------------------------------

    public function testFromEnvironmentStrictBoundsAndDefaults(): void
    {
        // strict=true: nilai dalam range diterima persis.
        $this->setEnv([
            'ZEF_OTEL_ENABLED' => '1',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/v1/traces',
            'ZEF_OTEL_EXPORT_TIMEOUT_MS' => '10000',
            'ZEF_OTEL_MAX_QUEUE' => '8192',
            'ZEF_OTEL_BATCH_SIZE' => '128',
        ]);

        try {
            $t = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
            self::assertSame(10000, $this->prop($this->prop($t, 'logExporter'), 'timeoutMs'));
            $processor = $this->prop($t, 'processor');
            self::assertSame(8192, $this->prop($processor, 'maxQueueSize'));
            self::assertSame(128, $this->prop($processor, 'batchSize'));
        } finally {
            $this->clearEnv();
        }

        // Batas bawah (1) diterima.
        $this->setEnv([
            'ZEF_OTEL_ENABLED' => '1',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/v1/traces',
            'ZEF_OTEL_EXPORT_TIMEOUT_MS' => '1',
            'ZEF_OTEL_MAX_QUEUE' => '1',
            'ZEF_OTEL_BATCH_SIZE' => '1',
        ]);

        try {
            $t1 = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
            self::assertSame(1, $this->prop($this->prop($t1, 'logExporter'), 'timeoutMs'));
            $p1 = $this->prop($t1, 'processor');
            self::assertSame(1, $this->prop($p1, 'maxQueueSize'));
            self::assertSame(1, $this->prop($p1, 'batchSize'));
        } finally {
            $this->clearEnv();
        }

        // Default (env tak diset): 500 / 1024 / 128.
        $this->setEnv([
            'ZEF_OTEL_ENABLED' => '1',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/v1/traces',
        ]);

        try {
            $t2 = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
            self::assertSame(500, $this->prop($this->prop($t2, 'logExporter'), 'timeoutMs'));
            $p2 = $this->prop($t2, 'processor');
            self::assertSame(1024, $this->prop($p2, 'maxQueueSize'));
            self::assertSame(128, $this->prop($p2, 'batchSize'));
        } finally {
            $this->clearEnv();
        }

        // strict: satu langkah di luar range → InvalidArgumentException.
        $violations = [
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS' => '0'],
            ['ZEF_OTEL_EXPORT_TIMEOUT_MS' => '10001'],
            ['ZEF_OTEL_MAX_QUEUE' => '0'],
            ['ZEF_OTEL_MAX_QUEUE' => '8193'],
            ['ZEF_OTEL_BATCH_SIZE' => '0'],
            ['ZEF_OTEL_BATCH_SIZE' => '8193', 'ZEF_OTEL_MAX_QUEUE' => '8192'],
        ];
        foreach ($violations as $v) {
            $this->setEnv([
                'ZEF_OTEL_ENABLED' => '1',
                'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/v1/traces',
            ] + $v);

            try {
                Telemetry::fromEnvironment(null, false);
                self::fail(json_encode($v) . ' harusnya ditolak strict');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                $this->clearEnv();
            }
        }
    }

    public function testFromEnvironmentEndpointGrammarIsEnforced(): void
    {
        $base = ['ZEF_OTEL_ENABLED' => '1'];
        foreach (['ftp://collector:4318', 'http://user:pass@collector:4318', '//collector:4318', '/local'] as $bad) {
            $this->setEnv($base + ['ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => $bad]);

            try {
                Telemetry::fromEnvironment(null, false);
                self::fail("Endpoint $bad harusnya ditolak");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                $this->clearEnv();
            }
        }
        // Valid tanpa kredensial → tidak throw, exporter terbentuk.
        $this->setEnv($base + ['ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector:4318/v1/traces']);

        try {
            $t = Telemetry::fromEnvironment(null, false, new OtlpExporterFactory());
            self::assertInstanceOf(OtlpHttpJsonExporter::class, $this->prop($t, 'logExporter'));
        } finally {
            $this->clearEnv();
        }
    }

    public function testFromEnvironmentWithoutEndpointFallsBackToInMemory(): void
    {
        $this->setEnv(['ZEF_OTEL_ENABLED' => '1']);

        try {
            $t = Telemetry::fromEnvironment(null, false);
            self::assertTrue($t->isInMemoryExporter());
            self::assertNull($this->prop($t, 'logExporter'));
            self::assertNull($this->prop($t, 'metricExporter'));
            self::assertTrue($t->isEnabled());
        } finally {
            $this->clearEnv();
        }

        $this->setEnv(['ZEF_OTEL_ENABLED' => '0']);
        $off = Telemetry::fromEnvironment(null, false);
        self::assertFalse($off->isEnabled());
    }

    public function testShutdownHookRegistrationIsOptional(): void
    {
        $this->setEnv(['ZEF_OTEL_ENABLED' => '1']);

        try {
            $t = Telemetry::fromEnvironment(null, true);
            self::assertTrue($t->isEnabled());
        } finally {
            $this->clearEnv();
        }
    }

    public function testEnabledDefaultsTrueInConstructorAndFlushDelivers(): void
    {
        $log = new SpyLogExporter();
        $metric = new SpyMetricExporter();
        $t = $this->telemetry($log, $metric);
        self::assertTrue($t->isEnabled());
        $t->recordLog('info', 'hello');
        $t->meter()->increment('app.events', 3, ['kind' => 'x']);
        $t->flush();
        self::assertCount(1, $log->batches);
        self::assertSame('hello', $log->batches[0][0]->body);
        self::assertSame(1, $metric->calls);
    }

    public function testFlushPushesEndedSpansToExporter(): void
    {
        // flush() wajib memanggil processor->flush() — span yang sudah end
        // harus sampai exporter pada flush yang sama.
        $exporter = new InMemorySpanExporter();
        $log = new SpyLogExporter();
        $t = $this->telemetry($log, null, $exporter);
        $span = $t->tracer()->startSpan('op');
        $span->end();
        self::assertCount(0, $exporter->spans(), 'belum flush → belum diekspor');
        $t->flush();
        self::assertCount(1, $exporter->spans());
        self::assertSame('op', $exporter->spans()[0]->name);
    }

    public function testDrainDeliveryLogLoopContinuesWithoutExporter(): void
    {
        // logExporter NULL + dua batch log → guard continue menguras queue;
        // mutan `break` menyisakan batch kedua.
        $exporter = new InMemorySpanExporter();
        $t = new Telemetry(
            new Tracer(new BatchSpanProcessor($exporter, 16, 8)),
            new CounterMeter(),
            new BatchSpanProcessor($exporter, 16, 8),
            null,
            null,
            true,
        );
        $r1 = new LogRecord('INFO', 'l1', TelemetryClock::nowUnixNano(), []);
        $r2 = new LogRecord('INFO', 'l2', TelemetryClock::nowUnixNano(), []);
        $this->setProp($t, 'logDeliveryQueue', [[$r1], [$r2]]);
        $t->flush();
        self::assertSame([], $this->prop($t, 'logDeliveryQueue'), 'queue log terkuras penuh');
    }

    public function testShutdownStillExportsLogsWhenMetricQueueEmpty(): void
    {
        // drainOne guard `&&`: metric queue KOSONG + exporter valid TIDAK
        // boleh menghentikan drain sebelum log diekspor (mutan `||` membuat
        // array_shift([]) → TypeError → return sebelum log loop → spin).
        $this->setEnv(['ZEF_OTEL_SHUTDOWN_DRAIN_MS' => '50']);

        try {
            $log = new SpyLogExporter();
            $metric = new SpyMetricExporter();
            $t = $this->telemetry($log, $metric); // metric queue kosong
            $t->recordLog('info', 'still-delivered');
            $t->shutdown();
            self::assertCount(1, $log->batches, 'log tetap diekspor walau metric queue kosong');
            self::assertSame('still-delivered', $log->batches[0][0]->body);
        } finally {
            $this->clearEnv();
        }
    }

    public function testRecordLogGuardHasThreeBranches(): void
    {
        // (a) disabled pada konstruksi → tidak ada record.
        $off = new Telemetry(new Tracer(new BatchSpanProcessor(new InMemorySpanExporter())), new CounterMeter(), new BatchSpanProcessor(new InMemorySpanExporter()), null, null, false);
        $off->recordLog('info', 'x');
        self::assertSame([], $this->prop($off, 'logs'));

        // (b) shutdown flag → tidak ada record.
        $t = $this->telemetry();
        $t->shutdown();
        $t->recordLog('info', 'y');
        self::assertSame([], $this->prop($t, 'logs'));

        // (c) batas 256 persis — yang ke-257 tidak masuk.
        $t2 = $this->telemetry();
        for ($i = 0; $i < 257; ++$i) {
            $t2->recordLog('info', 'm' . $i);
        }
        self::assertCount(256, $this->prop($t2, 'logs'));
    }

    public function testShutdownCallsProcessorAndExporterExactlyOnce(): void
    {
        $exporter = new CountingSpanExporter();
        $processor = new BatchSpanProcessor($exporter, 16, 4);
        $t = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, null, null, true);
        $t->shutdown();
        self::assertSame(1, $exporter->shutdownCalls);
        $t->shutdown(); // idempoten — guard flag
        self::assertSame(1, $exporter->shutdownCalls);
    }

    public function testShutdownEmitsFlushAndShutdownMeterEvents(): void
    {
        $meter = new RecordingMeter();
        $exporter = new CountingSpanExporter();
        $processor = new BatchSpanProcessor($exporter, 16, 4);
        $t = new Telemetry(new Tracer($processor), $meter, $processor, null, null, true);
        $t->flush();
        $flushOnly = $meter->events;
        self::assertSame(1, count($flushOnly));
        self::assertSame('zef.lifecycle.events.total', $flushOnly[0]['event']);
        self::assertSame(1, $flushOnly[0]['value']);
        $t->shutdown();
        // shutdown menambah SATU flush + SATU shutdown, masing-masing value 1.
        $all = $meter->events;
        self::assertSame(3, count($all));
        self::assertSame('zef.lifecycle.events.total', $all[1]['event']);
        self::assertSame(1, $all[1]['value']);
        self::assertSame('zef.lifecycle.events.total', $all[2]['event']);
        self::assertSame(1, $all[2]['value']);
        $names = array_column([$all[1], $all[2]], 'event');
        self::assertSame(['zef.lifecycle.events.total', 'zef.lifecycle.events.total'], $names);
    }

    public function testShutdownCallsMetricAndLogExportersIndependently(): void
    {
        // (a) metric & log exporter = objek sama → shutdown sekali.
        $dual = new DualExporter();
        $processor = new BatchSpanProcessor(new InMemorySpanExporter(), 8, 4);
        $t = new Telemetry(new Tracer($processor), new CounterMeter(), $processor, $dual, $dual, true);
        $t->shutdown();
        self::assertSame(1, $dual->shutdownCalls);

        // (b) exporter berbeda → keduanya shutdown.
        $m2 = new SpyMetricExporter();
        $l2 = new SpyLogExporter();
        $processor2 = new BatchSpanProcessor(new InMemorySpanExporter(), 8, 4);
        $t2 = new Telemetry(new Tracer($processor2), new CounterMeter(), $processor2, $m2, $l2, true);
        $t2->shutdown();
        self::assertTrue($m2->shutdownCalled);
        self::assertTrue($l2->shutdownCalled);
    }

    // Triage ekuivalen L238 (drainOne guard &&): logExporter null + queue ada
    // → mutan `||` me-shift lalu crash → tertelan catch kosong; dan shutdown()
    // me-reset seluruh queue di akhir → tidak ada perbedaan yang dapat
    // diamati dari luar. Tidak dapat dibunuh secara jujur.

    public function testDrainDeliveryContinuesPastMissingMetricExporter(): void
    {
        $log = new SpyLogExporter();
        $t = $this->telemetry($log);
        // Metric queue di-inject langsung: exporter metric NULL — jalur yang
        // mustahil lewat enqueueDelivery (guard instanceof), diuji via
        // drainDelivery yang harus continue (queue terkuras), bukan break.
        $this->setProp($t, 'metricDeliveryQueue', [
            ['m1' => ['count' => 1, 'sum' => 1.0, 'attributes' => []]],
            ['m2' => ['count' => 1, 'sum' => 2.0, 'attributes' => []]],
        ]);
        $r1 = new LogRecord('INFO', 'must-survive-1', TelemetryClock::nowUnixNano(), []);
        $r2 = new LogRecord('INFO', 'must-survive-2', TelemetryClock::nowUnixNano(), []);
        $this->setProp($t, 'logDeliveryQueue', [[$r1], [$r2]]);
        $t->flush();
        self::assertCount(2, $log->batches, 'log tetap diekspor meski metric exporter hilang');
        self::assertSame('must-survive-1', $log->batches[0][0]->body);
        self::assertSame('must-survive-2', $log->batches[1][0]->body);
        // continue (bukan break): queue metric terkuras habis.
        self::assertSame([], $this->prop($t, 'metricDeliveryQueue'));
    }

    public function testDrainOneMetricFailureReturnsBeforeLogExport(): void
    {
        $metric = new SpyMetricExporter();
        $metric->throwOnce = true;
        $log = new SpyLogExporter($metric);
        $t = $this->telemetry($log, $metric);
        // Dua batch metric + satu batch log, lewat refleksi agar urutan
        // drainOne terkontrol penuh.
        $rec = new LogRecord('INFO', 'guarded', TelemetryClock::nowUnixNano(), []);
        $this->setProp($t, 'metricDeliveryQueue', [
            ['m1' => ['count' => 1, 'sum' => 1.0, 'attributes' => []]],
            ['m2' => ['count' => 1, 'sum' => 2.0, 'attributes' => []]],
        ]);
        $this->setProp($t, 'logDeliveryQueue', [[$rec]]);
        $t->shutdown(); // jalur drainOne ber-deadline
        self::assertSame(3, $metric->calls); // batch1 throw + batch2 ok + snapshot kosong dari enqueue
        self::assertCount(1, $log->batches);
        // Asli: drainOne #1 = batch1 metric THROW → `return` — log TIDAK
        // diekspor di iterasi yang gagal; log keluar di #2 setelah batch2
        // sukses (calls==2). Mutan yang menghapus `return` mengekspor log
        // pada iterasi gagal itu sendiri (calls==1) → asersi ini membunuhnya.
        self::assertSame(2, $log->metricCallsAtFirstExport);
    }

    public function testShutdownDrainsDeliveriesUnderDeadline(): void
    {
        $log = new SpyLogExporter();
        $metric = new SpyMetricExporter();
        $t = $this->telemetry($log, $metric);
        $t->recordLog('info', 'drain-me');
        $t->meter()->increment('m', 1);
        $t->shutdown();
        self::assertCount(1, $log->batches);
        self::assertSame(1, $metric->calls);
        self::assertSame([], $this->prop($t, 'logs'));
        self::assertSame([], $this->prop($t, 'metricDeliveryQueue'));
    }

    public function testValidateEndpointRejectsBadSchemeAndCredentials(): void
    {
        $m = new \ReflectionMethod(Telemetry::class, 'validateEndpoint');
        $m->invoke(null, 'https://collector.internal:4318/v1/logs'); // tidak throw
        foreach (['javascript:alert(1)', 'ftp://x', 'collector:4318', 'http://u:p@h', 'http://u@h', 'http://:p@h'] as $bad) {
            try {
                $m->invoke(null, $bad);
                self::fail("Endpoint $bad harusnya ditolak");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Query string dengan ':' bukan kredensial authority — valid.
        $m->invoke(null, 'https://h/path?user=x:pass');
        $this->addToAssertionCount(1);
    }

    public function testFromEnvironmentDiscardConfigStillStrictlyValidated(): void
    {
        // RETRY_* / DRAIN dibaca-dibuang di fromEnvironment, tapi validasinya
        // strict: batas min/max ±1 teramati lewat throw.
        $base = [
            'ZEF_OTEL_ENABLED' => '1',
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:9/v1/traces',
        ];
        foreach ([
            ['ZEF_OTEL_RETRY_ATTEMPTS' => '0'],
            ['ZEF_OTEL_RETRY_ATTEMPTS' => '10'],
            ['ZEF_OTEL_RETRY_DELAY_MS' => '0'],
            ['ZEF_OTEL_RETRY_DELAY_MS' => '10000'],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS' => '0'],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS' => '60000'],
            ['ZEF_OTEL_SHUTDOWN_DRAIN_MS' => '0'],
            ['ZEF_OTEL_SHUTDOWN_DRAIN_MS' => '60000'],
        ] as $ok) {
            $this->setEnv($base + $ok);

            try {
                $t = Telemetry::fromEnvironment(null, false);
                self::assertTrue($t->isEnabled());
            } finally {
                $this->clearEnv();
            }
        }
        foreach ([
            ['ZEF_OTEL_RETRY_ATTEMPTS' => '-1'],
            ['ZEF_OTEL_RETRY_ATTEMPTS' => '11'],
            ['ZEF_OTEL_RETRY_DELAY_MS' => '-1'],
            ['ZEF_OTEL_RETRY_DELAY_MS' => '10001'],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS' => '-1'],
            ['ZEF_OTEL_RETRY_DELAY_CAP_MS' => '60001'],
        ] as $bad) {
            $this->setEnv($base + $bad);

            try {
                Telemetry::fromEnvironment(null, false);
                self::fail(json_encode($bad) . ' harusnya ditolak strict');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                $this->clearEnv();
            }
        }
    }

    // ------------------------------------------------------------------
    // Zona 2 — TelemetrySanitizer: grammar UTF-8, truncation, redaksi
    // ------------------------------------------------------------------

    public function testStringLimitBoundaries(): void
    {
        // limit < 1 → kosong (payload cukup panjang agar substr negatif
        // pada mutan ReturnRemoval menghasilkan potongan dari belakang).
        self::assertSame('', TelemetrySanitizer::string('abcdef', 0));
        self::assertSame('', TelemetrySanitizer::string('abcdef', -5));
        self::assertSame('', TelemetrySanitizer::string('abc', -5));
        // len <= limit → utuh tanpa transformasi.
        self::assertSame('abc', TelemetrySanitizer::string('abc', 3));
        // limit 1..3 → substr TANPA ellipsis (kap persis, tanpa bypass).
        self::assertSame('a', TelemetrySanitizer::string('abcdef', 1));
        self::assertSame('abc', TelemetrySanitizer::string('abcdef', 3));
        // limit 4 → mb_strcut(0,1) + '…' — total byte persis 4.
        $cut = TelemetrySanitizer::string('abcdef', 4);
        self::assertSame('a…', $cut);
        self::assertSame(4, strlen($cut));
        // Multibyte: 'ä' = 2 byte; limit 5 → mb_strcut(0,2)='ä' + '…'.
        $mb = TelemetrySanitizer::string('ääää', 5);
        self::assertSame('ä…', $mb);
        self::assertLessThanOrEqual(5, strlen($mb));
        // Len persis limit → tidak dipotong.
        self::assertSame('abcd', TelemetrySanitizer::string('abcd', 4));
    }

    public function testStringAndRedactDefaultLimitIsExactly2048(): void
    {
        // Default 2048 persis: len 2048 → utuh; len 5000 → potongan 2048 byte
        // (mb_strcut(0,2045) + '…' 3 byte) — membunuh default ±1.
        self::assertSame(str_repeat('a', 2048), TelemetrySanitizer::string(str_repeat('a', 2048)));
        self::assertSame(2048, strlen(TelemetrySanitizer::string(str_repeat('a', 5000))));
        self::assertSame(2048, strlen(TelemetrySanitizer::redact(str_repeat('x', 5000))));
    }

    public function testStringScrubControlCharsKeepsTabAndNewline(): void
    {
        self::assertSame("AB\tC\nD", TelemetrySanitizer::string("\x01A\x02B\tC\nD\x7F"));
        self::assertSame('', TelemetrySanitizer::string("\x00\x0B\x0C\x1F"));
    }

    public function testStringScrubInvalidUtf8InsteadOfThrowing(): void
    {
        $dirty = "ok\xC3\x28" . 'tail';
        $clean = TelemetrySanitizer::string($dirty);
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'), 'hasil wajib UTF-8 valid');
        self::assertStringContainsString('ok', $clean);
        self::assertStringContainsString('tail', $clean);
    }

    public function testIsSensitiveKeyNormalizationAndSubstringGrammar(): void
    {
        foreach ([
            'Authorization', 'AUTHORIZATION', 'x-authorization',
            'cookie', 'Set-Cookie', 'password', 'passwd',
            'token', 'api-key', 'API_KEY', 'apikey',
            'access-token', 'refreshToken', 'client secret',
            'tokens', 'passwordless', 'my_secret_value',
        ] as $sensitive) {
            self::assertTrue(TelemetrySanitizer::isSensitiveKey($sensitive), $sensitive);
        }
        foreach (['safe', 'total', 'user.name', 'userid', 'count-of-events', ''] as $clean) {
            self::assertFalse(TelemetrySanitizer::isSensitiveKey($clean), $clean);
        }
    }

    public function testRedactPatternsAndCaseInsensitivity(): void
    {
        self::assertSame('password=[REDACTED]', TelemetrySanitizer::redact('password=hunter2'));
        self::assertSame('passwd=[REDACTED]', TelemetrySanitizer::redact('passwd : xyz'));
        self::assertSame('api_key=[REDACTED]', TelemetrySanitizer::redact('api_key = abc123'));
        self::assertSame('Bearer [REDACTED]', TelemetrySanitizer::redact('Bearer abc.def._~+ghi=='));
        // Replacement memakai separator '=' literal dan kapitalisasi 'Bearer'.
        self::assertSame('Bearer [REDACTED]', TelemetrySanitizer::redact('bearer abc.def'));
        // Non-nilai utuh setelah pattern tetap ada.
        self::assertSame('user password=[REDACTED] end', TelemetrySanitizer::redact('user password=secret123 end'));
        // Redaksi dulu, baru truncation limit: 'password=[REDACTED]' (20 byte)
        // dipotong ke mb_strcut(0,9).'…' = 'password=…'.
        $out = TelemetrySanitizer::redact('password=whatever-more-text', 12);
        self::assertSame('password=…', $out);
        // Token bertanda baca diakhiri dengan benar.
        self::assertSame('token=[REDACTED],next', TelemetrySanitizer::redact('token=abc123,next'));
    }

    public function testValueHandlesNanInfArraysAndObjects(): void
    {
        self::assertSame('NAN', TelemetrySanitizer::value(NAN));
        self::assertSame('INF', TelemetrySanitizer::value(INF));
        self::assertSame('-INF', TelemetrySanitizer::value(-INF));
        self::assertSame(1.5, TelemetrySanitizer::value(1.5));
        self::assertSame(7, TelemetrySanitizer::value(7));
        self::assertTrue(TelemetrySanitizer::value(true));
        self::assertNull(TelemetrySanitizer::value(null));
        self::assertSame('stdClass', TelemetrySanitizer::value(new \stdClass()));
        // Array: slice 32 mempertahankan keys asosiatif.
        $in = [];
        for ($i = 0; $i < 35; ++$i) {
            $in['k' . $i] = $i;
        }
        $out = TelemetrySanitizer::value($in);
        self::assertCount(32, $out);
        self::assertSame(0, $out['k0']);
        self::assertSame(31, $out['k31']);
        self::assertArrayNotHasKey('k32', $out);
        // Rekursif + redaksi key sensitif (continue, bukan break: kunci
        // sensitif KEDUA tetap diproses dan kunci aman tetap ada).
        $nested = TelemetrySanitizer::value(['a' => ['token' => 'x'], 'safe' => "v\x01"]);
        self::assertSame(['a' => ['token' => '[REDACTED]'], 'safe' => 'v'], $nested);
        $multi = TelemetrySanitizer::value(['password' => 'p', 'token' => 't', 'safe' => 1]);
        self::assertSame(['password' => '[REDACTED]', 'token' => '[REDACTED]', 'safe' => 1], $multi);
    }

    public function testAttributesDropsSensitiveKeysEntirely(): void
    {
        $out = TelemetrySanitizer::attributes(['password' => 'x', 'safe' => "a\x01b", 'nested' => ['secret' => 's']]);
        self::assertArrayNotHasKey('password', $out);
        self::assertSame('ab', $out['safe']);
        self::assertSame(['secret' => '[REDACTED]'], $out['nested']);
    }

    // ------------------------------------------------------------------
    // Zona 3 — CounterMeter: monotonic, overflow, cardinality, normalisasi
    // ------------------------------------------------------------------

    public function testIncrementRejectsNegativeAndNonFiniteDeltas(): void
    {
        $m = new CounterMeter();
        foreach ([-1, -0.5, NAN, INF, -INF] as $bad) {
            try {
                $m->increment('x', $bad);
                self::fail('delta ' . var_export($bad, true) . ' harusnya ditolak');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $m->increment('x', 0);
        $snapshot = $m->snapshot();
        self::assertSame(0, $snapshot['x|' . $this->jsonKey([])]['count']);
    }

    public function testIncrementClampsIntOverflowToPhpIntMax(): void
    {
        $m = new CounterMeter();
        $m->increment('ov', \PHP_INT_MAX);
        $m->increment('ov', 1);
        $snapshot = $m->snapshot();
        $s = $snapshot['ov|' . $this->jsonKey([])];
        self::assertSame(\PHP_INT_MAX, $s['count']);
        self::assertIsInt($s['count']);
        self::assertSame((float) \PHP_INT_MAX + 1.0, $s['sum']);
    }

    public function testObserveIsHistogramAndAllowsNegative(): void
    {
        $m = new CounterMeter();
        $m->observe('lat', 0.5);
        $m->observe('lat', -1.0);
        $snapshot = $m->snapshot();
        $s = $snapshot['lat|' . $this->jsonKey([])];
        self::assertSame(2, $s['count']);
        self::assertSame(-0.5, $s['sum']);
    }

    public function testSeriesKeyIsOrderInsensitiveViaKsort(): void
    {
        $m = new CounterMeter();
        $m->increment('n', 1, ['b' => 2, 'a' => 1]);
        $m->increment('n', 1, ['a' => 1, 'b' => 2]);
        $snapshot = $m->snapshot();
        self::assertCount(1, $snapshot);
        self::assertSame(2, reset($snapshot)['count']);
        // sum wajib float (cast di increment) meski delta int.
        self::assertSame(2.0, reset($snapshot)['sum']);
    }

    public function testIncrementDefaultsAndFloatDeltaAreAccepted(): void
    {
        $m = new CounterMeter();
        $m->increment('d'); // default delta = 1
        $m->increment('f', 0.5); // float finite → tidak throw
        $snapshot = $m->snapshot();
        self::assertSame(1, $snapshot['d|' . $this->jsonKey([])]['count']);
        self::assertSame(0.5, $snapshot['f|' . $this->jsonKey([])]['count']);
        self::assertSame(0.5, $snapshot['f|' . $this->jsonKey([])]['sum']);
    }

    public function testMeterKeyEncodingIsUnescapedJson(): void
    {
        $m = new CounterMeter();
        $m->increment('n', 1, ['u' => 'é/']);
        $expectedKey = 'n|' . json_encode(['u' => 'é/'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey($expectedKey, $m->snapshot(), 'key memakai JSON unescaped slash+unicode');
    }

    public function testCardinalityOverflowBucketAndEviction(): void
    {
        $m = new CounterMeter();
        for ($i = 0; $i < 1024; ++$i) {
            $m->increment('s', 1, ['i' => $i]);
        }
        self::assertCount(1024, $m->snapshot());
        $firstKey = array_key_first($m->snapshot());
        // Bucket overflow berlaku PER NAME: 'other' dan 'third' masing-masing
        // mendapat bucket sendiri, masing-masing evict satu series lama.
        $m->increment('other', 1, ['z' => 1]);
        self::assertCount(1024, $m->snapshot());
        $overflowOther = 'other|' . $this->jsonKey(['zef.cardinality.bucket' => 'overflow']);
        self::assertArrayHasKey($overflowOther, $m->snapshot());
        self::assertArrayNotHasKey($firstKey, $m->snapshot());
        $m->increment('third', 1, ['q' => 2]);
        self::assertCount(1024, $m->snapshot());
        $overflowThird = 'third|' . $this->jsonKey(['zef.cardinality.bucket' => 'overflow']);
        self::assertArrayHasKey($overflowThird, $m->snapshot());
        // Increment ke bucket overflow yang SUDAH ada → tidak evict lagi.
        $before = $m->snapshot();
        $m->increment('third', 1, ['r' => 3]);
        self::assertSame(1, $m->snapshot()[$overflowThird]['count'] - $before[$overflowThird]['count']);
        self::assertCount(1024, $m->snapshot());
    }

    /** @dataProvider provideHttpNormalization */
    public function testHttpAndLifecycleAttributeNormalization(string $name, array $attrs, array $expected): void
    {
        $m = new CounterMeter();
        $m->increment($name, 1, $attrs);
        $snapshot = $m->snapshot();
        $series = reset($snapshot);
        self::assertSame($expected, $series['attributes']);
    }

    public static function provideHttpNormalization(): iterable
    {
        yield 'http whitelist drops unknown dims' => [
            'zef.http.requests.total',
            ['http.request.method' => 'GET', 'http.response.status_code' => 200, 'rogue' => 'x'],
            ['http.request.method' => 'GET', 'http.response.status_code' => 200],
        ];

        yield 'errors.total keeps bounded exception.type' => [
            'zef.http.errors.total',
            ['http.request.method' => 'POST', 'exception.type' => str_repeat('E', 200)],
            ['exception.type' => str_repeat('E', 125) . '…', 'http.request.method' => 'POST'],
        ];

        yield 'lifecycle allowlist maps unknown to other' => [
            'zef.lifecycle.events.total',
            ['event.name' => 'bogus-event'],
            ['event.name' => 'other'],
        ];

        yield 'lifecycle allowlist keeps known' => [
            'zef.lifecycle.events.total',
            ['event.name' => 'worker.ready'],
            ['event.name' => 'worker.ready'],
        ];

        yield 'container duration bounded service id valid' => [
            'zef.container.resolve.duration_seconds',
            ['zef.service.id' => 'Cache.Local-1.0'],
            ['zef.service.id' => 'Cache.Local-1.0'],
        ];

        yield 'container duration replaces invalid service id' => [
            'zef.container.resolve.duration_seconds',
            ['zef.service.id' => str_repeat('a', 97)],
            ['zef.service.id' => '[other]'],
        ];

        yield 'errors.total guard is conjunctive — name lain tanpa exception.type ekstra' => [
            'zef.http.requests.total',
            ['http.request.method' => 'GET', 'exception.type' => 'X'],
            ['http.request.method' => 'GET'],
        ];

        yield 'lifecycle guard is conjunctive — service.id tidak bocor' => [
            'zef.lifecycle.events.total',
            ['event.name' => 'worker.ready', 'zef.service.id' => 'svc'],
            ['event.name' => 'worker.ready'],
        ];
    }

    public function testHttpPrefixNameWithoutWhitelistedDimsYieldsEmptyAttributes(): void
    {
        $m = new CounterMeter();
        $m->increment('zef.http.other', 1, ['whatever' => 'x']);
        $snapshot = $m->snapshot();
        self::assertSame([], reset($snapshot)['attributes']);
    }

    public function testSpanLifecycleGuardsAndStatusGrammar(): void
    {
        $received = [];
        $span = new Span('op', $this->spanContext(), null, 100, 1_000, function (SpanData $d) use (&$received): void {
            $received[] = $d;
        });
        // Sensitive & empty keys diabaikan; value disanitasi.
        $span->setAttribute('token', 'keep-me-out');
        $span->setAttribute('', 'nope');
        $span->setAttribute('note', "a\x01b");
        self::assertSame(['note' => 'ab'], $this->prop($span, 'attributes'));
        // Event: nama kosong diabaikan; sensitive dibersihkan.
        $span->addEvent('', ['x' => 1]);
        $span->addEvent('evt', ['password' => 'p', 'ok' => 2]);
        self::assertCount(1, $this->prop($span, 'events'));
        self::assertSame(['ok' => 2], $this->prop($span, 'events')[0]['attributes']);
        // Status grammar.
        self::assertSame($span, $span->setStatus('error', 'boom'));
        self::assertSame('ERROR', $this->prop($span, 'status'));

        try {
            $span->setStatus('BOGUS');
            self::fail('status invalid harusnya throw');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        // end idempoten + endNs = max(start, given).
        $span->end(50); // < start 100 → clamp ke start
        $span->end();   // no-op
        self::assertTrue($span->isEnded());
        self::assertCount(1, $received);
        $d = $received[0];
        self::assertSame(100, $d->startNs);
        self::assertSame(100, $d->endNs);
        self::assertSame(0, $d->endNs - $d->startNs);
        self::assertSame('ERROR', $d->status);
        // Post-end mutations inert.
        $span->setAttribute('late', 1);
        $span->addEvent('late', []);
        $span->setStatus('OK');
        self::assertSame(['note' => 'ab'], $this->prop($span, 'attributes'));
        self::assertCount(1, $this->prop($span, 'events'));
        self::assertSame('ERROR', $this->prop($span, 'status'));
    }

    public function testSpanStatusDescriptionSanitizedAndCapped(): void
    {
        $span = new Span('op', $this->spanContext(), null, 1, 1, static function (SpanData $d): void {});
        $span->setStatus('ERROR', str_repeat('x', 2000) . "y\x01");
        self::assertSame(1024, strlen($this->prop($span, 'statusDescription')));
        $span->setStatus('OK');
        self::assertNull($this->prop($span, 'statusDescription'));
    }

    public function testSpanOnEndReceivesDurationAndInheritance(): void
    {
        $ctx = $this->spanContext();
        $received = [];
        $span = new Span('root', $ctx, null, 1_000, 5_000, function (SpanData $d) use (&$received): void {
            $received[] = $d;
        });
        $span->end(4_500);
        self::assertSame(3_500, $received[0]->endNs - $received[0]->startNs);
        self::assertSame(5_000 + 3_500, $received[0]->endUnixNano);
        self::assertSame('UNSET', $received[0]->status);
        self::assertSame($ctx, $received[0]->context);
        self::assertNull($received[0]->parent);
    }

    // ------------------------------------------------------------------
    // Zona 5 — BatchSpanProcessor: batching, retry, drain shutdown
    // ------------------------------------------------------------------

    public function testProcessorConstructorGuards(): void
    {
        $e = new InMemorySpanExporter();
        foreach ([[0, 8], [8, 0], [-1, 4], [4, -1]] as [$q, $b]) {
            try {
                new BatchSpanProcessor($e, $q, $b);
                self::fail("queue=$q batch=$b harusnya ditolak");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProcessorConstructorDefaults(): void
    {
        $p = new BatchSpanProcessor(new InMemorySpanExporter());
        self::assertSame(2048, $this->prop($p, 'maxQueueSize'));
        self::assertSame(256, $this->prop($p, 'batchSize'));
    }

    public function testFlushAfterShutdownDoesNotExport(): void
    {
        $exporter = new InvalidArgSpanExporter();
        $p = new BatchSpanProcessor($exporter, 8, 4);
        $p->shutdown();
        // Queue di-inject ulang post-shutdown: guard `||` wajib menahan flush.
        $this->setProp($p, 'queue', [$this->spanData('zombie')]);
        $p->flush();
        self::assertSame(0, $exporter->calls, 'post-shutdown flush tidak boleh mengekspor');
    }

    public function testFlushEmptyQueueDoesNotTouchRetryPolicy(): void
    {
        // flush() dengan queue kosong return SEBELUM policy dimuat — env
        // invalid tidak boleh meledak (mutan ReturnRemoval memuat policy).
        $this->setEnv(['ZEF_OTEL_RETRY_ATTEMPTS' => '99']);

        try {
            $p = new BatchSpanProcessor(new InMemorySpanExporter());
            $p->flush(); // tidak throw
            $this->addToAssertionCount(1);
        } finally {
            $this->clearEnv();
        }
    }

    public function testShutdownDrainsInQueueOrder(): void
    {
        $exporter = new InMemorySpanExporter();
        $p = new BatchSpanProcessor($exporter, 8, 8);
        $p->onEnd($this->spanData('s1'));
        $p->onEnd($this->spanData('s2'));
        $p->onEnd($this->spanData('s3'));
        $p->shutdown();
        self::assertSame(['s1', 's2', 's3'], array_map(static fn (SpanData $d): string => $d->name, $exporter->spans()));
    }

    public function testOnEndRejectsAfterShutdownAndWhenQueueFull(): void
    {
        $e = new InMemorySpanExporter();
        $p = new BatchSpanProcessor($e, 2, 8);
        $p->onEnd($this->spanData('s1'));
        $p->onEnd($this->spanData('s2'));
        $p->onEnd($this->spanData('s3')); // penuh (queue >= 2) → ditolak
        $p->flush();
        self::assertCount(2, $e->spans(), 'span ke-3 ditolak karena queue penuh');

        $e2 = new InMemorySpanExporter();
        $p2 = new BatchSpanProcessor($e2, 4, 2);
        $p2->shutdown();
        $p2->onEnd($this->spanData('late')); // post-shutdown → ditolak
        $p2->flush();
        self::assertCount(0, $e2->spans());
    }

    public function testFlushBatchesByBatchSize(): void
    {
        $calls = new CountingSpanExporter();
        $p = new BatchSpanProcessor($calls, 64, 2);
        for ($i = 0; $i < 5; ++$i) {
            $p->onEnd($this->spanData('s' . $i));
        }
        $p->flush();
        self::assertSame(3, $calls->exportCalls); // 2 + 2 + 1
        // Queue kosong setelah flush penuh.
        self::assertSame([], $this->prop($p, 'queue'));
    }

    public function testFlushRetriesTransientFailureAndGivesUpOnInvalidArgument(): void
    {
        $this->setEnv(['ZEF_OTEL_RETRY_DELAY_MS' => '0', 'ZEF_OTEL_RETRY_DELAY_CAP_MS' => '0']);
        // (1) Throwable → retry sesuai policy hingga sukses.
        $flaky = new FlakySpanExporter(2); // gagal 2x pertama
        $p = new BatchSpanProcessor($flaky, 16, 4);
        $p->onEnd($this->spanData('retry-me'));
        $p->flush();
        self::assertSame(3, $flaky->calls);
        self::assertCount(1, $flaky->delivered);

        // (2) InvalidArgumentException → break TANPA retry.
        $invalid = new InvalidArgSpanExporter();
        $p2 = new BatchSpanProcessor($invalid, 16, 4);
        $p2->onEnd($this->spanData('bad-batch'));
        $p2->flush();
        self::assertSame(1, $invalid->calls);
    }

    public function testShutdownDrainsWithThreeAttemptsThenExporterShutdown(): void
    {
        // Deadline 500ms > 2×usleep(50ms) → ketiga attempt jalan penuh.
        $this->setEnv(['ZEF_OTEL_SHUTDOWN_DRAIN_MS' => '500']);
        $always = new FlakySpanExporter(PHP_INT_MAX);
        $p = new BatchSpanProcessor($always, 16, 4);
        $p->onEnd($this->spanData('doomed'));
        $p->shutdown();
        self::assertSame(3, $always->calls); // attempt 0,1,2 lalu berhenti
        self::assertSame(1, $always->shutdownCalls);
        $p->shutdown(); // idempoten
        self::assertSame(1, $always->shutdownCalls);
        self::assertCount(0, $always->delivered);
    }

    public function testIsInMemoryExporterProbe(): void
    {
        self::assertTrue(new BatchSpanProcessor(new InMemorySpanExporter())->isInMemoryExporter());
        self::assertFalse(new BatchSpanProcessor(new FlakySpanExporter(0))->isInMemoryExporter());
    }

    // ------------------------------------------------------------------
    // Zona 6 — Tracer & NoopSpan
    // ------------------------------------------------------------------

    public function testDisabledTracerReturnsSingletonNoopSpan(): void
    {
        $t = new Tracer(new BatchSpanProcessor(new InMemorySpanExporter()), false);
        $s1 = $t->startSpan('op');
        $s2 = $t->startSpan('op2');
        self::assertInstanceOf(NoopSpan::class, $s1);
        self::assertSame(NoopSpan::instance(), $s1);
        self::assertSame($s1, $s2);
        self::assertTrue($s1->isEnded());
        $s1->end(); // inert, tidak throw
        $invalid = SpanContext::invalid();
        self::assertSame($invalid->traceId, $s1->getContext()->traceId);
        self::assertSame($invalid->spanId, $s1->getContext()->spanId);
        self::assertFalse($s1->getContext()->sampled);
    }

    public function testEnabledTracerCreatesRealSpanWithInheritedTraceId(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter, 16, 8);
        $t = new Tracer($processor, true);
        $parent = $this->spanContext();
        $span = $t->startSpan('child', ['k' => 'v'], $parent);
        self::assertInstanceOf(Span::class, $span);
        self::assertSame($parent->traceId, $span->getContext()->traceId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->getContext()->spanId);
        self::assertNotSame($parent->spanId, $span->getContext()->spanId);
        $span->end();
        $processor->flush(); // span masuk queue processor → flush ke exporter
        self::assertCount(1, $exporter->spans());
        self::assertSame('child', $exporter->spans()[0]->name);
        // Tanpa parent → traceId baru 32 hex.
        $root = $t->startSpan('root');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $root->getContext()->traceId);
    }

    // ------------------------------------------------------------------
    // Zona 7 — TraceContextPropagator grammar
    // ------------------------------------------------------------------

    public function testTraceContextExtractGrammar(): void
    {
        // sampled flag bit 0.
        $c = TraceContextPropagator::extract($this->validTraceParent('01'));
        self::assertInstanceOf(SpanContext::class, $c);
        self::assertTrue($c->sampled);
        self::assertSame(str_repeat('a', 32), $c->traceId);
        self::assertSame(str_repeat('b', 16), $c->spanId);
        $c0 = TraceContextPropagator::extract($this->validTraceParent('00'));
        self::assertInstanceOf(SpanContext::class, $c0);
        self::assertFalse($c0->sampled);
        // Uppercase hex → diterima, hasil lowercase.
        $up = TraceContextPropagator::extract('00-' . str_repeat('A', 32) . '-' . str_repeat('B', 16) . '-01');
        self::assertInstanceOf(SpanContext::class, $up);
        self::assertSame(str_repeat('a', 32), $up->traceId);
        // Whitespace trim.
        $c1 = TraceContextPropagator::extract('  ' . $this->validTraceParent() . "\n");
        self::assertInstanceOf(SpanContext::class, $c1);
        // traceState dipertahankan.
        $c2 = TraceContextPropagator::extract($this->validTraceParent(), 'vendor=1');
        self::assertSame('vendor=1', $c2->traceState);
        // Ditolak: zero-id, format salah. (Bit flag reserved TIDAK lagi
        // ditolak — lihat testTraceContextReservedFlagBitsAreMaskedNotDropped.)
        foreach ([
            '00-' . str_repeat('0', 32) . '-' . str_repeat('b', 16) . '-01',
            '00-' . str_repeat('a', 32) . '-' . str_repeat('0', 16) . '-01',
            '00-' . str_repeat('g', 32) . '-' . str_repeat('b', 16) . '-01',
            '01-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
            'too-short',
            '',
            // Anchor: substring yang cocok di tengah tetap ditolak.
            'x00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
            '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01x',
        ] as $bad) {
            self::assertNull(TraceContextPropagator::extract($bad), $bad);
        }
    }

    public function testTraceContextReservedFlagBitsAreMaskedNotDropped(): void
    {
        // Regresi P-23 (issue #172): bit flag reserved (0x02..0x80) TIDAK
        // lagi membatalkan seluruh trace context — W3C Trace Context
        // mengabaikan bit tak dikenal; hanya bit 0 (sampled) yang
        // dipertahankan dan inject() memancarkan flags tersanitasi.
        $sampled = TraceContextPropagator::extract($this->validTraceParent('ff'));
        self::assertInstanceOf(SpanContext::class, $sampled);
        self::assertTrue($sampled->sampled);
        self::assertSame($this->validTraceParent('01'), TraceContextPropagator::inject($sampled));

        $unsampled = TraceContextPropagator::extract($this->validTraceParent('02'));
        self::assertInstanceOf(SpanContext::class, $unsampled);
        self::assertFalse($unsampled->sampled);
        self::assertSame($this->validTraceParent('00'), TraceContextPropagator::inject($unsampled));
    }

    public function testTraceContextInjectRoundtrip(): void
    {
        $ctx = new SpanContext(str_repeat('c', 32), str_repeat('d', 16), true);
        $tp = TraceContextPropagator::inject($ctx);
        self::assertSame('00-' . str_repeat('c', 32) . '-' . str_repeat('d', 16) . '-01', $tp);
        $back = TraceContextPropagator::extract($tp);
        self::assertInstanceOf(SpanContext::class, $back);
        self::assertTrue($back->sampled);
    }

    // ------------------------------------------------------------------
    // Zona 8 — CorrelationPropagator: batas byte & kontrak null
    // ------------------------------------------------------------------

    public function testCorrelationExtractByteLimitsAndGrammar(): void
    {
        $ok = CorrelationPropagator::extract($this->validTraceParent('01'), 'state=1', 'op-1', 'idem', ['k' => 'v']);
        self::assertInstanceOf(CorrelationContext::class, $ok);
        self::assertSame('op-1', $ok->operationId);
        self::assertSame('state=1', $ok->traceState);
        // Trim: guard panjang dicek pada raw input — whitespace pad (> 55)
        // ditolak sebelum trim, sehingga trim tak teramati (triage ekuivalen).
        // traceState tepat 512 byte dengan grammar W3C valid → diterima
        // (batas `>`, bukan `>=`).
        $exact = CorrelationPropagator::extract($this->validTraceParent('01'), 'v=' . str_repeat('a', 510), 'op');
        self::assertInstanceOf(CorrelationContext::class, $exact);
        // Uppercase hex → diterima dan dinormalisasi lowercase.
        $upper = CorrelationPropagator::extract('00-' . str_repeat('A', 32) . '-' . str_repeat('B', 16) . '-01', null, 'op');
        self::assertInstanceOf(CorrelationContext::class, $upper);
        self::assertSame(str_repeat('a', 32), $upper->traceId);
        self::assertSame(str_repeat('b', 16), $upper->spanId);
        self::assertNull(CorrelationPropagator::extract(null, null, 'op'));
        self::assertNull(CorrelationPropagator::extract(str_repeat('x', 56), null, 'op'), 'di atas MAX 55');
        self::assertNull(CorrelationPropagator::extract('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-0', null, 'op'), '54 byte');
        self::assertNull(CorrelationPropagator::extract($this->validTraceParent(), str_repeat('s', 513), 'op'), 'traceState di atas 512');
        self::assertNull(CorrelationPropagator::extract($this->validTraceParent('05'), null, 'op'), 'flag tak dikenal → ctor tolak');
        self::assertNull(CorrelationPropagator::extract('00-' . str_repeat('0', 32) . '-' . str_repeat('b', 16) . '-01', null, 'op'), 'zero id ditolak ctor');
    }

    public function testCorrelationInjectAndDisabled(): void
    {
        $ctx = CorrelationPropagator::extract($this->validTraceParent('01'), null, 'op');
        self::assertInstanceOf(CorrelationContext::class, $ctx);
        $h = CorrelationPropagator::inject($ctx);
        self::assertInstanceOf(CorrelationHeaders::class, $h);
        self::assertSame($this->validTraceParent('01'), $h->traceParent);
        self::assertNull(CorrelationPropagator::inject(null));
        self::assertNull(CorrelationPropagator::disabled());
    }

    // ------------------------------------------------------------------
    // Zona 9 — HealthAggregator: containment & sanitasi
    // ------------------------------------------------------------------

    public function testHealthAggregatesOkDegradedAndContainedFailures(): void
    {
        $ok = new StubHealthIndicator('db', static fn (): HealthCheckResult => HealthCheckResult::up('ready'));
        $down = new StubHealthIndicator('queue!!', static fn (): HealthCheckResult => HealthCheckResult::down(str_repeat('m', 300)));
        $boom = new StubHealthIndicator('cache', static function (): HealthCheckResult {
            throw new \RuntimeException('socket down');
        });
        $agg = new HealthAggregator([$ok]);
        $r = $agg->aggregate();
        self::assertSame('ok', $r['status']);
        self::assertSame(['name' => 'db', 'status' => 'up', 'message' => 'ready'], $r['checks'][0]);

        $agg2 = new HealthAggregator([$ok, $down, $boom]);
        $r2 = $agg2->aggregate();
        self::assertSame('degraded', $r2['status']);
        self::assertSame('queue__', $r2['checks'][1]['name']); // sanitasi non-[A-Za-z0-9._-]
        self::assertSame('down', $r2['checks'][1]['status']);
        self::assertSame(256, strlen($r2['checks'][1]['message']));
        self::assertSame('probe failure: RuntimeException', $r2['checks'][2]['message']);
        self::assertSame('down', $r2['checks'][2]['status']);
    }

    public function testHealthNamesSanitizedTruncatedAndUnnamed(): void
    {
        $long = new StubHealthIndicator(str_repeat('n', 100), static fn (): HealthCheckResult => HealthCheckResult::up());
        $empty = new StubHealthIndicator('', static fn (): HealthCheckResult => HealthCheckResult::up());
        $agg = new HealthAggregator([$long, $empty]);
        $r = $agg->aggregate();
        self::assertSame(64, strlen($r['checks'][0]['name']));
        self::assertSame('unnamed', $r['checks'][1]['name']);
    }

    public function testHealthToJsonRoundtrip(): void
    {
        $agg = new HealthAggregator([new StubHealthIndicator('db', static fn (): HealthCheckResult => HealthCheckResult::up('ok!'))]);
        $decoded = json_decode($agg->toJson(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $decoded['status']);
        self::assertSame('db', $decoded['checks'][0]['name']);
    }

    public function testHealthProbeFailureClassIsTruncatedAt128(): void
    {
        // Nama class 130+ char (namespace 15 + nama) → substr(0,128) aktif:
        // panjang message persis 15+128 membunuh substr ±1 maupun unwrap.
        $boom = new StubHealthIndicator('cache', static function (): HealthCheckResult {
            throw new RuntimeExceptionWithVeryVeryVeryLongNameForTruncationCoverageAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABBBBBBBBBBBBBBBBBBBBBB('boom');
        });
        $agg = new HealthAggregator([$boom]);
        $r = $agg->aggregate();
        self::assertSame(15 + 128, strlen($r['checks'][0]['message']));
        self::assertSame('degraded', $r['status']);
    }

    public function testHealthToJsonIsUnescaped(): void
    {
        // UNESCAPED_SLASHES + UNESCAPED_UNICODE: message 'x/y café' harus
        // muncul literal di JSON mentah (name selalu disanitasi ke [A-Za-z0-9._-]).
        $agg = new HealthAggregator([new StubHealthIndicator('ab', static fn (): HealthCheckResult => HealthCheckResult::up('x/y café'))]);
        $json = $agg->toJson();
        self::assertStringContainsString('"message":"x/y café"', $json);
    }

    // ------------------------------------------------------------------
    // Zona 10 — TelemetryLogger: severity mapping & sanitasi context
    // ------------------------------------------------------------------

    public function testTelemetryLoggerMapsSeverityAndRedactsContext(): void
    {
        $logger = new RecordingLogger();
        $telemetry = $this->telemetry();
        $tl = new TelemetryLogger($logger, $telemetry);
        $tl->info('pesan info', ['safe' => 1]);
        $tl->warning('pesan warn', ['password' => 'x', 'obj' => new \stdClass()]);
        $tl->error('pesan error', ['obj' => new class implements \Stringable {
            public function __toString(): string
            {
                return 'STR';
            }
        }]);
        self::assertSame('info', $logger->records[0]['method']);
        self::assertSame('warning', $logger->records[1]['method']);
        self::assertSame('error', $logger->records[2]['method']);
        self::assertSame(['safe' => 1], $logger->records[0]['context']);
        self::assertSame('[REDACTED]', $logger->records[1]['context']['password']);
        self::assertSame('stdClass', $logger->records[1]['context']['obj']);
        // Stringable diarahkan ke TelemetrySanitizer::value → get_debug_type
        // (class@anonymous + path) — tetap string nama class, bukan __toString.
        self::assertIsString($logger->records[2]['context']['obj']);
        self::assertStringContainsString('Stringable@anonymous', $logger->records[2]['context']['obj']);
        // Record log terkirim ke Telemetry dengan severity uppercase.
        $logs = $this->prop($telemetry, 'logs');
        self::assertCount(3, $logs);
        self::assertSame('INFO', $logs[0]->severity);
        self::assertSame('WARN', $logs[1]->severity);
        self::assertSame('ERROR', $logs[2]->severity);
    }

    public function testTelemetryLoggerWorksWithoutTelemetry(): void
    {
        $logger = new RecordingLogger();
        $tl = new TelemetryLogger($logger);
        $tl->info('dbg');
        self::assertSame('info', $logger->records[0]['method']);
        self::assertCount(1, $logger->records);
    }

    // ------------------------------------------------------------------
    // Zona 11 — InMemorySpanExporter & TelemetryClock
    // ------------------------------------------------------------------

    public function testInMemoryExporterAccumulatesAndResets(): void
    {
        $e = new InMemorySpanExporter();
        $e->export([$this->spanData('a')]);
        $e->export([$this->spanData('b'), $this->spanData('c')]);
        self::assertCount(3, $e->spans());
        self::assertSame('a', $e->spans()[0]->name);
        $e->reset();
        self::assertSame([], $e->spans());
        $e->shutdown(); // inert
        $this->addToAssertionCount(1);
    }

    public function testTelemetryClockIsMonotonicAndUnixNano(): void
    {
        $a = TelemetryClock::nowNs();
        $b = TelemetryClock::nowNs();
        self::assertIsInt($a);
        self::assertGreaterThanOrEqual($a, $b);
        $t = time();
        $u = TelemetryClock::nowUnixNano();
        self::assertIsInt($u);
        self::assertGreaterThan(0, $u);
        // Presisi: selisih terhadap time()×1e9 wajib < 1.2 detik — membunuh
        // pengali 999999999/1000000001 (offset ≈ epoch 1.78e9 ns) namun
        // toleran terhadap jeda scheduler sandbox.
        self::assertLessThan(1_200_000_000, abs($u - $t * 1_000_000_000));
    }

    /** @param array<string,string> $env */
    private function setEnv(array $env): void
    {
        foreach ($env as $k => $v) {
            putenv($k . '=' . $v);
        }
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $k) {
            putenv($k);
        }
    }

    /** @return array<string,mixed> */
    private function prop(object $o, string $p): mixed
    {
        $r = new \ReflectionProperty($o, $p);

        return $r->getValue($o);
    }

    private function setProp(object $o, string $p, mixed $v): void
    {
        $r = new \ReflectionProperty($o, $p);
        $r->setValue($o, $v);
    }

    private function validTraceParent(string $flags = '01'): string
    {
        return '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-' . $flags;
    }

    private function telemetry(
        ?SpyLogExporter $log = null,
        ?SpyMetricExporter $metric = null,
        ?InMemorySpanExporter $spanExport = null,
        ?CounterMeter $meter = null,
    ): Telemetry {
        $spanExport ??= new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($spanExport, 64, 8);
        $log ??= new SpyLogExporter($metric);

        return new Telemetry(
            new Tracer($processor),
            $meter ?? new CounterMeter(),
            $processor,
            $metric,
            $log,
            true,
        );
    }

    private function jsonKey(array $attrs): string
    {
        return json_encode($attrs, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------
    // Zona 4 — Span: lifecycle, guard status, sanitize
    // ------------------------------------------------------------------

    private function spanContext(): SpanContext
    {
        $ctx = TraceContextPropagator::extract($this->validTraceParent());
        self::assertInstanceOf(SpanContext::class, $ctx);

        return $ctx;
    }

    private function spanData(string $name): SpanData
    {
        return new SpanData($name, $this->spanContext(), null, 1, 2, 3, 4, 'UNSET', null, [], []);
    }
}

final class FlakySpanExporter implements SpanExporterInterface
{
    public int $calls = 0;
    public int $shutdownCalls = 0;

    /** @var list<array> */
    public array $delivered = [];

    public function __construct(private readonly int $failFirst) {}

    public function export(array $spans): void
    {
        ++$this->calls;
        if ($this->calls <= $this->failFirst) {
            throw new \RuntimeException('flaky sink #' . $this->calls);
        }
        foreach ($spans as $s) {
            $this->delivered[] = $s;
        }
    }

    public function shutdown(): void
    {
        ++$this->shutdownCalls;
    }
}

final class InvalidArgSpanExporter implements SpanExporterInterface
{
    public int $calls = 0;

    public function export(array $spans): void
    {
        ++$this->calls;

        throw new \InvalidArgumentException('invalid batch shape');
    }

    public function shutdown(): void {}
}

// ----------------------------------------------------------------------
// Spy fixtures file-level (reuse antar test method)
// ----------------------------------------------------------------------

final class SpyLogExporter implements LogExporterInterface
{
    /** @var list<list<LogRecord>> */
    public array $batches = [];
    public int $metricCallsAtFirstExport = -1;
    public bool $shutdownCalled = false;

    public function __construct(private readonly ?SpyMetricExporter $metricSpy = null) {}

    public function exportLogs(array $records): void
    {
        $this->batches[] = $records;
        if ($this->metricCallsAtFirstExport === -1 && $this->metricSpy instanceof SpyMetricExporter) {
            $this->metricCallsAtFirstExport = $this->metricSpy->calls;
        }
    }

    public function shutdown(): void
    {
        $this->shutdownCalled = true;
    }
}

final class SpyMetricExporter implements MetricExporterInterface
{
    public int $calls = 0;
    public bool $throwOnce = false;
    public bool $throwAlways = false;
    public bool $shutdownCalled = false;

    public function exportMetrics(array $metrics): void
    {
        ++$this->calls;
        if ($this->throwAlways || ($this->throwOnce && $this->calls === 1)) {
            throw new \RuntimeException('metric sink down');
        }
    }

    public function shutdown(): void
    {
        $this->shutdownCalled = true;
    }
}

final class RecordingMeter implements MeterInterface
{
    /** @var list<array{event:string,value:float|int}> */
    public array $events = [];

    public function increment(string $name, float|int $value = 1, array $attributes = []): void
    {
        $this->events[] = ['event' => $name, 'value' => $value];
    }

    public function observe(string $name, float $value, array $attributes = []): void {}

    public function snapshot(): array
    {
        return [];
    }
}

final class CountingSpanExporter implements SpanExporterInterface
{
    public int $shutdownCalls = 0;
    public int $exportCalls = 0;

    public function export(array $spans): void
    {
        ++$this->exportCalls;
    }

    public function shutdown(): void
    {
        ++$this->shutdownCalls;
    }
}

final class DualExporter implements LogExporterInterface, MetricExporterInterface
{
    public int $shutdownCalls = 0;

    public function exportLogs(array $records): void {}

    public function exportMetrics(array $metrics): void {}

    public function shutdown(): void
    {
        ++$this->shutdownCalls;
    }
}

/**
 * Nama class 130+ karakter (145 dengan namespace) — dipakai untuk membunuh
 * mutan substr($e::class, 0, 128) di HealthAggregator (±1 dan unwrap).
 */
final class RuntimeExceptionWithVeryVeryVeryLongNameForTruncationCoverageAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABBBBBBBBBBBBBBBBBBBBBB extends \RuntimeException {}

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{method:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->write('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->write('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->write('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->write((string) $level, $message, $context);
    }

    private function write(string $method, string|\Stringable $message, array $context): void
    {
        $this->records[] = ['method' => $method, 'message' => (string) $message, 'context' => $context];
    }
}

final class StubHealthIndicator implements HealthIndicatorInterface
{
    public function __construct(
        private readonly string $name,
        private readonly \Closure $check,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthCheckResult
    {
        return ($this->check)();
    }
}
