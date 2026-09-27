<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\GroupedHealthIndicatorInterface;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\HealthIndicatorInterface;
use Zef\Framework\Observability\TimeoutHealthIndicator;

/**
 * v2.31.0 — Health Check v2: overall deadline bounding (P-25 — a hanging
 * probe can no longer block /health forever), liveness/readiness grouping,
 * duration reporting, Prometheus exposition, and the per-probe timeout
 * wrapper.
 *
 * @internal
 */
final class HealthAggregatorV2Test extends TestCase
{
    // -------------------------------------------------- overall deadline (P-25)

    public function testOverallDeadlineSkipsRemainingProbesAndBoundsResponse(): void
    {
        $slow = new HealthProbeStub('slow', static function (): HealthCheckResult {
            usleep(400_000); // 400ms — exceeds the 100ms budget

            return HealthCheckResult::up();
        });
        $fastA = new HealthProbeStub('fast-a', static fn (): HealthCheckResult => HealthCheckResult::up());
        $fastB = new HealthProbeStub('fast-b', static fn (): HealthCheckResult => HealthCheckResult::up());
        $agg = new HealthAggregator([$slow, $fastA, $fastB]);

        $report = $agg->aggregate(100);

        self::assertSame('degraded', $report['status']);
        self::assertCount(3, $report['checks']);
        self::assertSame('up', $report['checks'][0]['status'], 'the probe that started inside the budget still runs');
        self::assertSame('down', $report['checks'][1]['status']);
        self::assertStringContainsString('deadline', $report['checks'][1]['message']);
        self::assertSame('down', $report['checks'][2]['status']);
        self::assertStringContainsString('deadline', $report['checks'][2]['message']);
        self::assertLessThan(700.0, $report['tookMs'], 'response time is bounded by the deadline + the one hanging probe');
    }

    public function testWithoutDeadlineEveryProbeStillRuns(): void
    {
        $agg = new HealthAggregator([
            new HealthProbeStub('a', static fn (): HealthCheckResult => HealthCheckResult::up()),
            new HealthProbeStub('b', static fn (): HealthCheckResult => HealthCheckResult::up()),
        ]);

        $report = $agg->aggregate();

        self::assertSame('ok', $report['status']);
        self::assertCount(2, $report['checks']);
        self::assertGreaterThanOrEqual(0.0, $report['tookMs']);
    }

    public function testDeadlineThatLeavesTimeForAllProbesStaysOk(): void
    {
        $agg = new HealthAggregator([
            new HealthProbeStub('a', static fn (): HealthCheckResult => HealthCheckResult::up()),
            new HealthProbeStub('b', static fn (): HealthCheckResult => HealthCheckResult::up()),
        ]);

        $report = $agg->aggregate(5000);

        self::assertSame('ok', $report['status']);
        self::assertCount(2, $report['checks']);
    }

    // -------------------------------------------------- liveness / readiness groups

    public function testGroupFilteringLiveReadyAndAll(): void
    {
        $db = new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up());
        $loop = new HealthGroupedProbe('event-loop', 'live', static fn (): HealthCheckResult => HealthCheckResult::up());
        $broker = new HealthGroupedProbe('broker', 'ready', static fn (): HealthCheckResult => HealthCheckResult::up());
        $agg = new HealthAggregator([$db, $loop, $broker]);

        self::assertCount(3, $agg->aggregateFor('all')['checks']);
        self::assertSame(['event-loop'], array_column($agg->aggregateFor('live')['checks'], 'name'));
        self::assertSame(['db', 'broker'], array_column($agg->aggregateFor('ready')['checks'], 'name'));
        self::assertSame(['db', 'event-loop', 'broker'], array_column($agg->aggregateFor('all')['checks'], 'name'));
    }

    public function testLiveViewIsIndependentOfFailingDependencies(): void
    {
        $db = new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::down('connection refused'));
        $loop = new HealthGroupedProbe('event-loop', 'live', static fn (): HealthCheckResult => HealthCheckResult::up());
        $agg = new HealthAggregator([$db, $loop]);

        self::assertSame('degraded', $agg->aggregate()['status']);
        self::assertSame('ok', $agg->aggregateFor('live')['status'], 'liveness ignores dependency probes');
        self::assertSame('degraded', $agg->aggregateFor('ready')['status']);
    }

    // -------------------------------------------------- Prometheus exposition

    public function testPrometheusExpositionFormat(): void
    {
        $agg = new HealthAggregator([
            new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up()),
            new HealthProbeStub('queue__', static fn (): HealthCheckResult => HealthCheckResult::down('broker unreachable')),
        ]);

        $text = $agg->toPrometheus();

        self::assertStringContainsString('# HELP zef_health_check', $text);
        self::assertStringContainsString('# TYPE zef_health_check gauge', $text);
        self::assertStringContainsString('zef_health_check{check="db"} 1', $text);
        self::assertStringContainsString('zef_health_check{check="queue__"} 0', $text);
        self::assertStringContainsString('# TYPE zef_health_status gauge', $text);
        self::assertStringContainsString('zef_health_status 0', $text, 'any down probe degrades the aggregate gauge');
        self::assertStringEndsWith("\n", $text);
    }

    public function testPrometheusHealthyAggregateIsOne(): void
    {
        $agg = new HealthAggregator([
            new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up()),
        ]);

        self::assertStringContainsString('zef_health_status 1', $agg->toPrometheus());
    }

    // -------------------------------------------------- toJson duration

    public function testToJsonCarriesTookMs(): void
    {
        $agg = new HealthAggregator([
            new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up('ok!')),
        ]);

        $decoded = json_decode($agg->toJson(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['status']);
        self::assertIsArray($decoded['checks']);
        self::assertIsArray($decoded['checks'][0]);
        self::assertSame('db', $decoded['checks'][0]['name']);
        self::assertArrayHasKey('tookMs', $decoded);
    }

    // -------------------------------------------------- TimeoutHealthIndicator

    public function testTimeoutHealthIndicatorReportsTimeoutAsDown(): void
    {
        $probe = new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up());
        $wrapped = new TimeoutHealthIndicator(
            $probe,
            0.05,
            static function (callable $fn, float $seconds): mixed {
                throw new \RuntimeException('fiber deadline exceeded');
            },
        );

        $result = $wrapped->check();

        self::assertFalse($result->healthy);
        self::assertStringContainsString('did not finish within 0.050s', $result->message);
        self::assertStringContainsString('RuntimeException', $result->message);
    }

    public function testTimeoutHealthIndicatorPassesThroughSuccessAndInvalidResults(): void
    {
        $probe = new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up('fine'));
        $wrapped = new TimeoutHealthIndicator(
            $probe,
            1.0,
            static fn (callable $fn, float $seconds): mixed => $fn(),
        );
        self::assertTrue($wrapped->check()->healthy);
        self::assertSame('fine', $wrapped->check()->message);

        $garbage = new TimeoutHealthIndicator(
            $probe,
            1.0,
            static fn (callable $fn, float $seconds): mixed => 'not-a-result',
        );
        self::assertFalse($garbage->check()->healthy);
        self::assertSame('invalid check result', $garbage->check()->message);
    }

    public function testTimeoutHealthIndicatorWithoutRunnerRunsDirectly(): void
    {
        $probe = new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up());
        $wrapped = new TimeoutHealthIndicator($probe, 1.0);

        self::assertTrue($wrapped->check()->healthy);
        self::assertSame('db', $wrapped->name());
    }

    public function testTimeoutHealthIndicatorRejectsNonPositiveBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TimeoutHealthIndicator(
            new HealthProbeStub('db', static fn (): HealthCheckResult => HealthCheckResult::up()),
            0.0,
        );
    }
}

/**
 * @internal
 */
final class HealthProbeStub implements HealthIndicatorInterface
{
    /** @param (\Closure(): HealthCheckResult) $check */
    public function __construct(
        private readonly string $name,
        private readonly \Closure $check,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        return ($this->check)();
    }
}

/**
 * @internal
 */
final class HealthGroupedProbe implements HealthIndicatorInterface, GroupedHealthIndicatorInterface
{
    /** @param (\Closure(): HealthCheckResult) $check */
    public function __construct(
        private readonly string $name,
        private readonly string $group,
        private readonly \Closure $check,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function healthGroup(): string
    {
        return $this->group;
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        return ($this->check)();
    }
}
