<?php

declare(strict_types=1);

/*
 * ZEF Framework — Native PHPUnit coverage for the Observability stack.
 *
 * Covers: CorrelationContext, SpanContext, TraceContextPropagator,
 * CorrelationPropagator, CorrelationHeaders, Span, Tracer, NoopSpan,
 * BatchSpanProcessor, InMemorySpanExporter, Telemetry, TelemetryLogger,
 * TelemetrySanitizer, CounterMeter and OtlpHttpJsonExporter (against a
 * real local HTTP server).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationHeaders;
use Zef\Framework\Observability\CorrelationPropagator;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\NoopSpan;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\Span;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\TraceContextPropagator;
use Zef\Framework\Observability\Tracer;

/**
 * @internal
 */
final class ObservabilityTest extends TestCase
{
    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const string SPAN_ID = '00f067aa0ba902b7';

    protected function tearDown(): void
    {
        putenv('ZEF_OTEL_ENABLED');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS');
    }

    // ------------------------------------------------------------------
    // CorrelationContext
    // ------------------------------------------------------------------

    public function testCorrelationContextBuildsTraceParentAndRedactsSensitiveAttributes(): void
    {
        $ctx = new CorrelationContext(
            self::TRACE_ID,
            self::SPAN_ID,
            '01',
            'vendor=1',
            'op-1',
            'idem-1',
            ['user' => 'alice', 'authorization' => 'Bearer xyz', 'attempts' => 2],
        );

        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', $ctx->traceParent());
        self::assertSame(55 + \strlen('vendor=1') + 1, $ctx->propagationBytes());
        $redacted = $ctx->redactedAttributes();
        self::assertSame('alice', $redacted['user']);
        self::assertSame('sha256:' . hash('sha256', 'Bearer xyz'), $redacted['authorization']);
        self::assertSame(2, $redacted['attempts']);
    }

    public function testCorrelationContextRejectsMalformedIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext('zz', self::SPAN_ID, '01', null, 'op');
    }

    public function testCorrelationContextRejectsAllZeroTraceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(\str_repeat('0', 32), self::SPAN_ID, '01', null, 'op');
    }

    public function testCorrelationContextRejectsBadTraceFlags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(self::TRACE_ID, self::SPAN_ID, 'ff', null, 'op');
    }

    public function testCorrelationContextRejectsBadTraceState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', '!!invalid!!', 'op');
    }

    public function testCorrelationContextRejectsEmptyOperationIdAndOversizedTracestate(): void
    {
        try {
            new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', null, '');
            self::fail('empty operationId must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', \str_repeat('a', 513), 'op');
    }

    public function testCorrelationContextRejectsTooManyOrOversizedAttributes(): void
    {
        try {
            new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', null, 'op', null, \array_fill_keys(\range(1, 17), 1));
            self::fail('attribute count above MAX_ATTRIBUTES must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', null, 'op', null, ['big' => \str_repeat('x', 257)]);
    }

    public function testCorrelationContextRejectsNonScalarAttributeValuesAndBadKeys(): void
    {
        try {
            new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', null, 'op', null, ['arr' => [1]]);
            self::fail('array attribute value must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new CorrelationContext(self::TRACE_ID, self::SPAN_ID, '01', null, 'op', null, ['bad key!' => 1]);
    }

    // ------------------------------------------------------------------
    // SpanContext + propagators
    // ------------------------------------------------------------------

    public function testSpanContextValidityAndTraceParent(): void
    {
        $ctx = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
        self::assertTrue($ctx->isValid());
        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', $ctx->traceParent());

        $invalid = SpanContext::invalid();
        self::assertFalse($invalid->isValid());
        self::assertSame('00-' . \str_repeat('0', 32) . '-' . \str_repeat('0', 16) . '-00', $invalid->traceParent());

        $this->expectException(\InvalidArgumentException::class);
        new SpanContext('short', 'short');
    }

    public function testTraceContextPropagatorExtractAndInject(): void
    {
        $parent = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';
        $ctx = TraceContextPropagator::extract($parent, 'k=v');
        self::assertNotNull($ctx);
        self::assertTrue($ctx->sampled);

        self::assertNull(TraceContextPropagator::extract('garbage'));
        self::assertNull(TraceContextPropagator::extract('00-' . \str_repeat('0', 32) . '-' . self::SPAN_ID . '-01'));
        self::assertNull(TraceContextPropagator::extract('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-ff'));
        self::assertSame($parent, TraceContextPropagator::inject($ctx));
    }

    public function testCorrelationPropagatorExtractInjectAndDisabled(): void
    {
        $parent = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';
        $ctx = CorrelationPropagator::extract($parent, null, 'op-9', null, ['tenant' => 'acme']);
        self::assertInstanceOf(CorrelationContext::class, $ctx);
        self::assertSame('acme', $ctx->attributes['tenant']);

        self::assertNull(CorrelationPropagator::extract(null, null, 'op'));
        self::assertNull(CorrelationPropagator::extract(\str_repeat('x', 56), null, 'op'));
        self::assertNull(CorrelationPropagator::extract($parent, \str_repeat('a', 513), 'op'));
        self::assertNull(CorrelationPropagator::extract('00-INVALID-' . self::SPAN_ID . '-01', null, 'op'));

        $headers = CorrelationPropagator::inject($ctx);
        self::assertInstanceOf(CorrelationHeaders::class, $headers);
        self::assertSame(55, $headers->encodedBytes());
        self::assertNull(CorrelationPropagator::inject(null));
        self::assertNull(CorrelationPropagator::disabled());
    }

    public function testSpanRecordsAttributesEventsAndStatusThenExportsOnEnd(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan('request', ['http.method' => 'GET']);
        self::assertInstanceOf(Span::class, $span);

        $span->setAttribute('http.route', '/toko')
            ->setAttribute('authorization', 'Bearer secret') // sensitive: dropped
            ->setAttributes(['retries' => 1])
            ->addEvent('cache.miss', ['key' => 'p:2'])
            ->addEvent('', ['ignored' => true])
            ->setStatus('error', 'upstream 503')
        ;

        self::assertFalse($span->isEnded());
        $span->end();
        $span->end(); // idempotent
        $processor->flush();

        self::assertTrue($span->isEnded());
        self::assertCount(1, $exporter->spans());
        $data = $exporter->spans()[0];
        self::assertSame('request', $data->name);
        self::assertSame('/toko', $data->attributes['http.route']);
        self::assertArrayNotHasKey('authorization', $data->attributes);
        self::assertSame(1, $data->attributes['retries']);
        self::assertCount(1, $data->events);
        self::assertSame('cache.miss', $data->events[0]['name']);
        self::assertSame('ERROR', $data->status);
        self::assertSame('upstream 503', $data->statusDescription);
        self::assertTrue($data->context->isValid());
    }

    public function testSpanIgnoresMutationsAfterEndAndRejectsInvalidStatus(): void
    {
        $exporter = new InMemorySpanExporter();
        $span = $this->makeSpan($exporter);
        $span->end();

        self::assertSame($span, $span->setAttribute('k', 'v'));
        self::assertSame($span, $span->addEvent('late'));
        self::assertSame($span, $span->setStatus('OK'));

        $fresh = $this->makeSpan($exporter, 'fresh');
        $this->expectException(\InvalidArgumentException::class);
        $fresh->setStatus('BOGUS');
    }

    public function testTracerDisabledReturnsNoopSpan(): void
    {
        $processor = new BatchSpanProcessor(new InMemorySpanExporter());
        $tracer = new Tracer($processor, enabled: false);
        $span = $tracer->startSpan('noop');

        self::assertInstanceOf(NoopSpan::class, $span);
        self::assertTrue($span->isEnded());
        self::assertFalse($span->getContext()->isValid());
        self::assertSame($span, $span->setAttributes(['a' => 1]));
        self::assertSame($span, $span->setStatus('OK'));
        self::assertSame($span, $span->addEvent('evt'));
        $span->end();

        self::assertSame(NoopSpan::instance(), NoopSpan::instance());
    }

    public function testTracerPropagatesParentTraceId(): void
    {
        $exporter = new InMemorySpanExporter();
        $parent = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
        $processor = new BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);

        $child = $tracer->startSpan('child', [], $parent);
        $child->end();
        $processor->flush();

        self::assertCount(1, $exporter->spans());
        self::assertSame(self::TRACE_ID, $exporter->spans()[0]->context->traceId);
        self::assertNotNull($exporter->spans()[0]->parent);
    }

    // ------------------------------------------------------------------
    // BatchSpanProcessor
    // ------------------------------------------------------------------

    public function testBatchProcessorQueueCapAndPermanentRejection(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter, 2, 2);

        $context = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
        $data = new SpanData('s', $context, null, 1, 2, 3, 4, 'UNSET', null, [], []);
        $processor->onEnd($data);
        $processor->onEnd($data);
        $processor->onEnd($data); // over the cap: dropped

        $processor->flush();
        self::assertCount(2, $exporter->spans());
        self::assertTrue($processor->isInMemoryExporter());

        // Permanent rejection (\InvalidArgumentException) must not retry.
        $failing = new class implements SpanExporterInterface {
            public int $calls = 0;

            public function export(array $spans): void
            {
                ++$this->calls;

                throw new \InvalidArgumentException('permanent');
            }

            public function shutdown(): void {}
        };
        $p2 = new BatchSpanProcessor($failing, 10, 10);
        $p2->onEnd($data);
        $p2->flush();
        self::assertSame(1, $failing->calls);
        self::assertFalse($p2->isInMemoryExporter());
    }

    public function testBatchProcessorConstructorGuardsAndShutdown(): void
    {
        try {
            new BatchSpanProcessor(new InMemorySpanExporter(), 0, 1);
            self::fail('maxQueueSize 0 must throw');
        } catch (\InvalidArgumentException) {
        }

        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter, 4, 2);
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=50');

        $context = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
        $processor->onEnd(new SpanData('s', $context, null, 1, 2, 3, 4, 'UNSET', null, [], []));
        $processor->shutdown();
        self::assertCount(1, $exporter->spans());

        // After shutdown nothing else is queued nor exported.
        $processor->onEnd(new SpanData('t', $context, null, 1, 2, 3, 4, 'UNSET', null, [], []));
        self::assertCount(1, $exporter->spans());

        $this->expectException(\InvalidArgumentException::class);
        new BatchSpanProcessor(new InMemorySpanExporter(), 4, 0);
    }

    // ------------------------------------------------------------------
    // Telemetry + TelemetryLogger
    // ------------------------------------------------------------------

    public function testTelemetryDisabledModeRecordsNothing(): void
    {
        putenv('ZEF_OTEL_ENABLED=0');
        $t = Telemetry::fromEnvironment(null, false);
        self::assertFalse($t->isEnabled());
        self::assertTrue($t->isInMemoryExporter());

        $t->recordLog('INFO', 'hidden');
        $t->flush();
        $t->shutdown();
        $t->shutdown(); // idempotent
        self::assertInstanceOf(NoopSpan::class, $t->startSpan('x'));
    }

    public function testTelemetryInMemoryModeCollectsSpansLogsAndMetrics(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=50');
        $t = Telemetry::fromEnvironment(null, false);
        self::assertTrue($t->isEnabled());

        $span = $t->startSpan('handler', ['route' => '/x']);
        $span->end();
        $t->recordLog('warn', 'cache cold', ['key' => 'p:1']);
        $t->recordLog('INFO', str_repeat('x', 300)); // beyond the 256 cap? no: 2 logs only
        $t->meter()->increment('requests', 1, ['code' => '200']);
        $t->flush();

        $t->shutdown();
        $t->shutdown();
        self::assertTrue($t->isInMemoryExporter());
    }

    public function testTelemetryExtractDelegatesToTraceContextPropagator(): void
    {
        putenv('ZEF_OTEL_ENABLED=0');
        $t = Telemetry::fromEnvironment(null, false);
        $ctx = $t->extract('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');
        self::assertInstanceOf(SpanContext::class, $ctx);
        self::assertNull($t->extract('bogus'));
    }

    public function testTelemetryLoggerForwardsToPsrLoggerAndTelemetry(): void
    {
        $captured = [];
        $psr = new class($captured) extends AbstractLogger {
            /** @param list<array{0:string,1:string,2:array<string,mixed>}> $sink */
            public function __construct(private array &$sink) {}

            public function log(mixed $level, mixed $message, array $context = []): void
            {
                $this->sink[] = [(string) $level, (string) $message, $context];
            }
        };

        putenv('ZEF_OTEL_ENABLED=0');
        $telemetry = Telemetry::fromEnvironment(null, false);
        $logger = new TelemetryLogger($psr, $telemetry);

        $logger->info('hello', ['user' => 'bob', 'password' => 'hunter2']);
        $logger->warning('cold', ['token' => 'abc']);
        $logger->error('boom', ['request' => new \stdClass()]);

        self::assertCount(3, $captured);
        self::assertSame('info', $captured[0][0]);
        self::assertSame('[REDACTED]', $captured[0][2]['password']);
        self::assertSame('[REDACTED]', $captured[1][2]['token']);
        self::assertSame(\stdClass::class, $captured[2][2]['request']);
    }

    // ------------------------------------------------------------------
    // TelemetrySanitizer
    // ------------------------------------------------------------------

    public function testSanitizerSensitiveKeysStringsRedactionAndValues(): void
    {
        self::assertTrue(TelemetrySanitizer::isSensitiveKey('HTTP_AUTHORIZATION'));
        self::assertTrue(TelemetrySanitizer::isSensitiveKey('set cookie'));
        self::assertFalse(TelemetrySanitizer::isSensitiveKey('http.route'));

        self::assertSame('bell', TelemetrySanitizer::string("bel\x07l"));
        self::assertSame('', TelemetrySanitizer::string('x', 0));
        self::assertSame('ab', TelemetrySanitizer::string('abcdef', 2));
        self::assertSame('ab…', TelemetrySanitizer::string('abcdef', 5));
        self::assertSame('abcdef', TelemetrySanitizer::string('abcdef', 6));

        $red = TelemetrySanitizer::redact('password=hunter2 and api_key: xyz; auth_token=abc123');
        self::assertStringNotContainsString('hunter2', $red);
        self::assertStringNotContainsString('xyz', $red);
        self::assertStringNotContainsString('abc123', $red);
        self::assertStringContainsString('[REDACTED]', $red);
        self::assertStringNotContainsString('tok.123', TelemetrySanitizer::redact('Bearer tok.123'));

        self::assertSame('plain', TelemetrySanitizer::value('plain'));
        self::assertSame(7, TelemetrySanitizer::value(7));
        self::assertNull(TelemetrySanitizer::value(null));
        self::assertSame(['nested'], TelemetrySanitizer::value(['nested']));
    }

    /**
     * Regresi P-21 (issue #172): pasangan ber-quotes gaya JSON harus ter-redact.
     * Pola lama mensyaratkan separator tepat setelah keyword telanjang, jadi
     * "password":"x" lolos utuh; vocabulary juga bolong (pwd, credentials,
     * private key). Nilai rahasia TIDAK boleh muncul di output dalam bentuk apa pun.
     */
    public function testRedactCatchesQuotedKeyValuePairsAndWiderVocabulary(): void
    {
        // JSON-style pairs — key dan value ber-quotes.
        $json = TelemetrySanitizer::redact('{"password":"hunter-two","token":"tok-nine"}');
        self::assertStringNotContainsString('hunter-two', $json, 'nilai rahasia JSON tidak boleh bocor');
        self::assertStringNotContainsString('tok-nine', $json, 'token JSON tidak boleh bocor');
        self::assertStringContainsString('[REDACTED]', $json);

        // Single-quoted style: 'pwd'='secret-one'.
        $single = TelemetrySanitizer::redact("'pwd'='secret-one' username=alice");
        self::assertStringNotContainsString('secret-one', $single);
        self::assertStringContainsString('alice', $single, 'nilai non-sensitif tetap utuh');

        // Key telanjang + value ber-quotes: credentials: "cred-three".
        $mixed = TelemetrySanitizer::redact('credentials: "cred-three"');
        self::assertStringNotContainsString('cred-three', $mixed);

        // Vocabulary baru.
        $vocab = TelemetrySanitizer::redact('passphrase=phrase-one private_key=pk-one credential=cr-one');
        self::assertStringNotContainsString('phrase-one', $vocab);
        self::assertStringNotContainsString('pk-one', $vocab);
        self::assertStringNotContainsString('cr-one', $vocab);

        // Perilaku lama tetap: pasangan telanjang ter-redact, key dipertahankan.
        self::assertSame(
            'password=[REDACTED]',
            TelemetrySanitizer::redact('password=hunter2'),
            'format pasangan telanjang tidak berubah',
        );
    }

    // ------------------------------------------------------------------
    // CounterMeter
    // ------------------------------------------------------------------

    public function testCounterMeterIncrementObserveSnapshotAndGuards(): void
    {
        $meter = new CounterMeter();
        $meter->increment('hits', 2, ['code' => '200']);
        $meter->increment('hits', 3, ['code' => '200']);
        $meter->observe('latency', 0.5);

        $snap = $meter->snapshot();
        self::assertSame(5, $snap['hits|{"code":"200"}']['count']);
        self::assertSame(5.0, $snap['hits|{"code":"200"}']['sum']);
        self::assertSame(1, $snap['latency|[]']['count']);
        self::assertSame(0.5, $snap['latency|[]']['sum']);

        try {
            $meter->increment('bad', -1);
            self::fail('negative delta must throw');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $meter->increment('nan', \NAN);
    }

    public function testCounterMeterLifecycleDimensionAllowList(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'request.started']);
        $meter->increment('zef.lifecycle.events.total', 1, ['event.name' => 'totally-unknown']);
        $meter->increment('zef.http.requests.total', 1, ['http.request.method' => 'GET', 'junk' => 'dropped']);

        $snap = $meter->snapshot();
        self::assertSame(['event.name' => 'request.started'], $snap['zef.lifecycle.events.total|{"event.name":"request.started"}']['attributes']);
        self::assertSame('other', $snap['zef.lifecycle.events.total|{"event.name":"other"}']['attributes']['event.name']);
        self::assertSame(['http.request.method' => 'GET'], $snap['zef.http.requests.total|{"http.request.method":"GET"}']['attributes']);
    }

    public function testOtlpExporterSendsSpansMetricsLogsToRealHttpServer(): void
    {
        if (getenv('ZEF_SKIP_FORK_TESTS') === '1') {
            self::markTestSkipped('fork-based transport tests disabled for this runner');
        }
        $this->runOtlpServerSession(3, function (string $endpoint, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($endpoint, ['service.name' => 'zef-test'], 1000);
            $context = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
            $parent = new SpanContext(self::TRACE_ID, '00f067aa0ba902b8', true);

            $exporter->export([new SpanData(
                'http.request',
                $context,
                $parent,
                1,
                2,
                3,
                4,
                'ERROR',
                'boom',
                ['http.route' => '/x', 'flag' => true],
                [['name' => 'evt', 'time_unix_nano' => 9, 'attributes' => ['k' => 'v']]],
            )]);
            $exporter->exportMetrics(['requests' => ['count' => 3, 'sum' => 3.0, 'attributes' => ['code' => '200']]]);
            $exporter->exportLogs([new LogRecord('INFO', 'hi', 5, ['k' => [1, 2]])]);
            $exporter->export([]); // no-op branch, no HTTP round-trip
            $exporter->exportMetrics([]);
            $exporter->exportLogs([]);
            $exporter->shutdown();
            $exporter->shutdown(); // idempotent

            self::assertFileExists($sink);
            $lines = \file($sink, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            self::assertCount(3, $lines, 'empty payloads must not hit the wire');
            $posted = \array_map(static fn (string $l): array => \json_decode($l, true, 512, \JSON_THROW_ON_ERROR), $lines);

            $span = $posted[0]['body']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
            self::assertSame('/v1/traces', $posted[0]['path']);
            self::assertSame(self::TRACE_ID, $span['traceId']);
            self::assertSame('http.request', $span['name']);
            self::assertSame('STATUS_CODE_ERROR', $span['status']['code']);
            self::assertSame('boom', $span['status']['message']);
            self::assertSame('00f067aa0ba902b8', $span['parentSpanId']);
            self::assertSame('/x', $span['attributes'][0]['value']['stringValue']);
            self::assertTrue($span['attributes'][1]['value']['boolValue']);
            self::assertSame('evt', $span['events'][0]['name']);

            $metric = $posted[1]['body']['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0];
            self::assertSame('/v1/metrics', $posted[1]['path']);
            self::assertSame('requests', $metric['name']);
            // JSON has no float/int distinction for whole numbers: 3.0 goes
            // over the wire as "3" and decodes back as an int.
            self::assertSame(3, $metric['sum']['dataPoints'][0]['asDouble']);

            $log = $posted[2]['body']['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
            self::assertSame('/v1/logs', $posted[2]['path']);
            self::assertSame('INFO', $log['severityText']);
            self::assertSame('hi', $log['body']['stringValue']);
            self::assertSame('1', $log['attributes'][0]['value']['arrayValue']['values'][0]['intValue']);
        });
    }

    public function testOtlpExporterTranslatesHttpAndTransportFailures(): void
    {
        if (getenv('ZEF_SKIP_FORK_TESTS') === '1') {
            self::markTestSkipped('fork-based transport tests disabled for this runner');
        }
        $context = new SpanContext(self::TRACE_ID, self::SPAN_ID, true);
        $data = new SpanData('s', $context, null, 1, 2, 3, 4, 'UNSET', null, [], []);

        $this->runOtlpServerSession(2, function (string $endpoint) use ($data): void {
            // 4xx = permanent rejection
            $permanent = new OtlpHttpJsonExporter($endpoint . '/reject', [], 1000);

            try {
                $permanent->export([$data]);
                self::fail('4xx must raise permanent rejection');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            // 5xx = transient rejection
            $transient = new OtlpHttpJsonExporter($endpoint . '/error', [], 1000);

            try {
                $transient->export([$data]);
                self::fail('5xx must raise transient rejection');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        });

        // unreachable endpoint = transport failure (no server needed)
        $dead = new OtlpHttpJsonExporter('http://127.0.0.1:1/v1/traces', [], 100);

        try {
            $dead->export([$data]);
            self::fail('unreachable endpoint must raise transport failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('transport failure', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new OtlpHttpJsonExporter('http://localhost', [], 0);
    }

    // ------------------------------------------------------------------
    // Span + Tracer + NoopSpan
    // ------------------------------------------------------------------

    private function makeSpan(InMemorySpanExporter $exporter, string $name = 'op'): Span
    {
        $processor = new BatchSpanProcessor($exporter);
        $tracer = new Tracer($processor);
        $span = $tracer->startSpan($name, ['http.method' => 'GET']);
        self::assertInstanceOf(Span::class, $span);
        $processor->flush();

        return $span;
    }

    // ------------------------------------------------------------------
    // OtlpHttpJsonExporter against a real (forked, in-process) HTTP server
    // ------------------------------------------------------------------

    /**
     * Fork an in-process OTLP test server, run $test(port, sink) in the
     * parent, then reap the child. The child serves exactly $requests HTTP
     * round-trips: /reject -> 400, /error -> 503, everything else -> 200
     * with the decoded payload appended to the sink file. Daemon-less by
     * design: the sandbox reaps detached background processes, so the
     * server lives only inside this fork.
     *
     * @param callable(string, string): mixed $test receives (base-endpoint, sink-path)
     */
    private function runOtlpServerSession(int $requests, callable $test): void
    {
        if (!\function_exists('pcntl_fork')) {
            // The fork-based OTLP transport tests are POSIX-only (issue #110):
            // Windows has no pcntl, and the server side of the session cannot
            // run in-process without forking.
            self::markTestSkipped('pcntl_fork is unavailable on this platform.');
        }
        $buildDir = \dirname(__DIR__, 2) . '/build';
        if (!is_dir($buildDir)) {
            \mkdir($buildDir, 0o777, true);
        }
        $sink = $buildDir . '/otlp_sink_' . \uniqid('', true) . '.jsonl';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "cannot bind the OTLP test server: {$errstr}");
        $name = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork must be available for the OTLP transport tests');

        if ($pid === 0) {
            try {
                for ($i = 0; $i < $requests; ++$i) {
                    $conn = @\stream_socket_accept($server, 10);
                    if ($conn === false) {
                        break;
                    }
                    [$path, $body] = $this->readHttpRequest($conn);
                    $status = match (true) {
                        \str_starts_with($path, '/reject') => 400,
                        \str_starts_with($path, '/error') => 503,
                        default => 200,
                    };
                    if ($status === 200) {
                        \file_put_contents(
                            $sink,
                            \json_encode(['path' => $path, 'body' => \json_decode($body, true)]) . \PHP_EOL,
                            \FILE_APPEND,
                        );
                    }
                    \fwrite($conn, "HTTP/1.1 {$status} X\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                    \fclose($conn);
                }
            } catch (\Throwable) {
                // the child must never surface errors into the PHPUnit parent
            }
            \exit(0);
        }

        try {
            $test('http://127.0.0.1:' . $port, $sink);
        } finally {
            \pcntl_waitpid($pid, $status);
            \fclose($server);
            // Server side of the same teardown: $sink comes from
            // runOtlpServerSession(), which computes it as
            // $buildDir . '/otlp_sink_' . \uniqid('', true) . '.jsonl'. No request
            // input reaches the argument. Accepted suppression: section 7.3.
            @unlink($sink); // nosemgrep: unlink-use
        }
    }

    /**
     * @param resource $conn
     *
     * @return array{0:string,1:string}
     */
    private function readHttpRequest($conn): array
    {
        $buffer = '';
        while (!\str_contains($buffer, "\r\n\r\n")) {
            $chunk = \fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        $split = \explode("\r\n\r\n", $buffer, 2);
        $head = $split[0];
        $body = $split[1] ?? '';
        $length = 0;
        if (\preg_match('/Content-Length: (\d+)/i', $head, $m) === 1) {
            $length = (int) $m[1];
        }
        while (\strlen($body) < $length) {
            $chunk = \fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $requestLine = \strtok($head, "\r\n") ?: '';
        $path = \explode(' ', $requestLine)[1] ?? '/';

        return [$path, $body];
    }
}
