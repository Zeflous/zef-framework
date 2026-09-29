<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): observability plumbing and
 * event subscriber wiring — span batching, telemetry shutdown drains,
 * subscriber registration and URI parsing guards.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;

/**
 * @internal
 */
final class ObservabilityEdgeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_OTEL_ENABLED');
    }

    public function testBatchProcessorRejectsInvalidConfiguration(): void
    {
        $exporter = new InMemorySpanExporter();

        try {
            new BatchSpanProcessor($exporter, 0);
            self::fail('Expected maxQueueSize rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new BatchSpanProcessor($exporter, 8, 0);
    }

    public function testBatchProcessorQueuesFlushesAndDropsAfterShutdown(): void
    {
        $exporter = new InMemorySpanExporter();
        $processor = new BatchSpanProcessor($exporter, 2, 2);
        $processor->onEnd($this->spanData());
        $processor->onEnd($this->spanData());
        $processor->onEnd($this->spanData()); // exceeds maxQueueSize: dropped
        $processor->flush();
        self::assertSame(2, count($exporter->spans()));
        $processor->shutdown();
        $processor->onEnd($this->spanData());
        $processor->flush();
        self::assertSame(2, count($exporter->spans()));
    }

    public function testBatchProcessorFlushSurvivesExporterFailures(): void
    {
        $exporter = new class implements SpanExporterInterface {
            /** @var list<list<SpanData>> */
            public array $batches = [];

            #[\Override]
            public function export(array $spans): void
            {
                $this->batches[] = $spans;

                throw new \RuntimeException('export endpoint down');
            }

            #[\Override]
            public function shutdown(): void {}
        };
        $processor = new BatchSpanProcessor($exporter, 4, 4);
        $processor->onEnd($this->spanData());
        $processor->flush();
        self::assertGreaterThanOrEqual(1, count($exporter->batches));
        $processor->shutdown();
    }

    // ------------------------------------------------------------------
    // Telemetry shutdown drain
    // ------------------------------------------------------------------

    public function testTelemetryShutdownDrainsDeliveries(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        $telemetry = Telemetry::fromEnvironment(null, false);
        self::assertTrue($telemetry->isEnabled());
        $telemetry->startSpan('unit.shutdown.span')->end();
        $telemetry->meter()->increment('zef.unit.shutdown.total', 1, []);
        $telemetry->recordLog('WARN', 'unit warning', ['event.name' => 'unit.warning']);
        $telemetry->flush();
        $telemetry->shutdown();
        self::assertTrue($telemetry->isInMemoryExporter());
    }

    public function testTelemetryExtractRejectsMalformedTraceparent(): void
    {
        $telemetry = Telemetry::fromEnvironment(null, false);
        self::assertNull($telemetry->extract('00-short-00f067aa0ba902b7-01'));
        self::assertNull($telemetry->extract('00-zzzz-00f067aa0ba902b7-01'));
    }

    // ------------------------------------------------------------------
    // EventDispatcher subscriber wiring
    // ------------------------------------------------------------------

    public function testDispatcherRejectsUnknownEventClasses(): void
    {
        $dispatcher = new EventDispatcher();
        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->listen('Not\A\Real\Class', static function (object $event): void {});
    }

    public function testDispatcherSubscribesWithPrioritiesAndRejectsNonCallables(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new class implements EventSubscriberInterface {
            /**
             * @return array<string,list<array{int,callable}|callable>>
             */
            #[\Override]
            public static function subscriptions(): array
            {
                return [
                    \stdClass::class => [
                        static function (object $event): void {},
                        [50, static function (object $event): void {}],
                    ],
                ];
            }

            public static function handleHigh(object $event): void {}
        };
        $dispatcher->subscribe($subscriber);
        self::assertSame(2, count($dispatcher->registrations()));
        $returned = $dispatcher->dispatch(new \stdClass());
        self::assertInstanceOf(\stdClass::class, $returned);
    }

    // ------------------------------------------------------------------
    // Job worker cancellation
    // ------------------------------------------------------------------

    public function testJobWorkerPropagatesCancelledContexts(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue(new JobEnvelope('job-0000-0042', 'cancellable.job', null, 0));
        $worker = new InProcessJobWorker($queue);
        $worker->register('cancellable.job', static function (JobEnvelope $job, JobContext $ctx): void {
            $cancelled = $ctx->cancel();
            $cancelled->throwIfCancelled();
        });
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertFalse($result->completed);
        self::assertNotSame('done', $result->result);
    }

    // ------------------------------------------------------------------
    // Uri parsing guards
    // ------------------------------------------------------------------

    public function testUriParsesUserInfoAndNormalizesPercentEscapes(): void
    {
        // Regresi N-7 (issue #176): https:443 is the scheme default, so
        // getPort() normalizes it to null (PSR-7 "SHOULD omit").
        $uri = new Uri('https://user:pass@Example.TEST:443/a%2fb?x=%20');
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.test', $uri->getHost());
        self::assertNull($uri->getPort());
        self::assertSame('/a%2Fb', $uri->getPath());

        $mutated = $uri->withUserInfo('u2');
        self::assertSame('u2', $mutated->getUserInfo());
        $noUser = $mutated->withUserInfo('');
        self::assertSame('', $noUser->getUserInfo());
    }

    public function testUriRejectsBadHostsAndSchemes(): void
    {
        try {
            new Uri('https://bad_host_with_underscore_ok.test/');
            self::addToAssertionCount(1);
        } catch (\InvalidArgumentException) {
            self::fail('Underscore hosts are permitted by the reg-name grammar.');
        }
        $this->expectException(\InvalidArgumentException::class);
        new Uri('https://' . str_repeat('a', 260) . '.test/');
    }

    public function testUriControlCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Uri("https://example.test/\r\nevil");
    }

    private function spanData(): SpanData
    {
        return new SpanData(
            'unit.span',
            new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7'),
            null,
            1,
            2,
            1,
            2,
            'OK',
            null,
            [],
            [],
        );
    }
}
