<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 * v2.31.0 — Health Check v2: optional overall deadline (skips remaining
 * probes once exceeded — bounds /health response time even when a sync
 * probe hangs), liveness/readiness grouping via GroupedHealthIndicatorInterface,
 * per-aggregate duration reporting, and a Prometheus exposition format.
 */

namespace Zef\Framework\Observability;

/**
 * Runs registered health indicators and aggregates them into a single
 * status: "ok" when every probe passes, "degraded" when any fails.
 *
 * Indicator exceptions are contained: a crashing probe degrades to "down"
 * with a sanitized message instead of failing the whole request. Indicators
 * are typically collected through the TaggedServiceLocator using the
 * `health.indicator` tag.
 *
 * Timeouts (v2.31.0):
 * - Wrap individual probes in {@see TimeoutHealthIndicator} to enforce a
 *   per-probe time budget (with the async runtime this genuinely interrupts
 *   a hung probe via the fiber scheduler).
 * - Pass `$overallTimeoutMs` to {@see aggregate()}/{@see aggregateFor()} to
 *   bound the WHOLE scrape: once the deadline passes, remaining probes are
 *   marked down with a "skipped: overall health deadline exceeded" message
 *   instead of extending the response. A hanging sync probe can still hold
 *   its own slot, but the response as a whole can no longer block forever —
 *   the property load balancers polling /health depend on.
 */
final readonly class HealthAggregator
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_DEGRADED = 'degraded';
    public const string GROUP_LIVE = 'live';
    public const string GROUP_READY = 'ready';

    /** @param list<HealthIndicatorInterface> $indicators */
    public function __construct(
        private array $indicators = [],
    ) {}

    /** @return list<HealthIndicatorInterface> */
    public function indicators(): array
    {
        return $this->indicators;
    }

    /**
     * @param null|int $overallTimeoutMs wall-clock budget for the whole scrape;
     *                                   null = unlimited (legacy behaviour)
     *
     * @return array{status:string, checks:list<array{name:string,status:string,message:string}>, tookMs:float}
     */
    public function aggregate(?int $overallTimeoutMs = null): array
    {
        return $this->runGrouped('all', $overallTimeoutMs);
    }

    /**
     * Aggregate a single health view (v2.31.0):
     * - `live`  → only indicators explicitly tagged `live`
     *             (GroupedHealthIndicatorInterface);
     * - `ready` → everything EXCEPT `live`-tagged indicators (plain
     *             indicators are dependency probes, i.e. readiness);
     * - `all`   → every indicator (same as {@see aggregate()});
     * - any other value → indicators whose group matches exactly.
     *
     * @param  null|int $overallTimeoutMs wall-clock budget for the scrape
     *
     * @return array{status:string, checks:list<array{name:string,status:string,message:string}>, tookMs:float}
     */
    public function aggregateFor(string $group, ?int $overallTimeoutMs = null): array
    {
        return $this->runGrouped($group, $overallTimeoutMs);
    }

    /**
     * @param null|int $overallTimeoutMs wall-clock budget for the scrape
     */
    public function toJson(?int $overallTimeoutMs = null): string
    {
        return json_encode(
            $this->aggregate($overallTimeoutMs),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Prometheus exposition format (v2.31.0) — one gauge per probe plus the
     * aggregate status (1 = ok, 0 = degraded). Probe names are already
     * sanitized to [A-Za-z0-9._-] by the aggregation pipeline, so they are
     * safe label values.
     */
    public function toPrometheus(): string
    {
        $report = $this->aggregate();

        $lines = [
            '# HELP zef_health_check Outcome of each health probe (1 = up, 0 = down).',
            '# TYPE zef_health_check gauge',
        ];
        foreach ($report['checks'] as $check) {
            $lines[] = sprintf(
                'zef_health_check{check="%s"} %s',
                $check['name'],
                $check['status'] === HealthCheckResult::UP ? '1' : '0',
            );
        }
        $lines[] = '# HELP zef_health_status Aggregate health status (1 = ok, 0 = degraded).';
        $lines[] = '# TYPE zef_health_status gauge';
        $lines[] = 'zef_health_status ' . ($report['status'] === self::STATUS_OK ? '1' : '0');

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{status:string, checks:list<array{name:string,status:string,message:string}>, tookMs:float}
     */
    private function runGrouped(string $group, ?int $overallTimeoutMs): array
    {
        $started = hrtime(true);
        $deadline = $overallTimeoutMs !== null ? $started + $overallTimeoutMs * 1_000_000 : null;
        $checks = [];
        $status = self::STATUS_OK;
        foreach ($this->indicatorsForGroup($group) as $indicator) {
            $name = $indicator->name();
            if (!is_string($name) || $name === '') {
                $name = 'unnamed';
            }
            $name = substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'unnamed', 0, 64);

            if ($deadline !== null && hrtime(true) >= $deadline) {
                $checks[] = [
                    'name' => $name,
                    'status' => HealthCheckResult::DOWN,
                    'message' => 'skipped: overall health deadline exceeded',
                ];
                $status = self::STATUS_DEGRADED;

                continue;
            }

            try {
                $result = $indicator->check();
                $healthy = $result instanceof HealthCheckResult && $result->healthy;
                $message = $result instanceof HealthCheckResult ? $result->message : 'invalid check result';
            } catch (\Throwable $e) {
                $healthy = false;
                $message = 'probe failure: ' . substr($e::class, 0, 128);
            }
            if (!$healthy) {
                $status = self::STATUS_DEGRADED;
            }
            $checks[] = [
                'name' => $name,
                'status' => $healthy ? HealthCheckResult::UP : HealthCheckResult::DOWN,
                'message' => substr($message, 0, 256),
            ];
        }

        return [
            'status' => $status,
            'checks' => $checks,
            'tookMs' => round((hrtime(true) - $started) / 1_000_000, 3),
        ];
    }

    /**
     * @return list<HealthIndicatorInterface>
     */
    private function indicatorsForGroup(string $group): array
    {
        if ($group === 'all') {
            return $this->indicators;
        }

        return array_values(array_filter(
            $this->indicators,
            static function (HealthIndicatorInterface $indicator) use ($group): bool {
                $indicatorGroup = $indicator instanceof GroupedHealthIndicatorInterface
                    ? $indicator->healthGroup()
                    : self::GROUP_READY;
                if ($group === self::GROUP_LIVE) {
                    return $indicatorGroup === self::GROUP_LIVE;
                }
                if ($group === self::GROUP_READY) {
                    return $indicatorGroup !== self::GROUP_LIVE;
                }

                return $indicatorGroup === $group;
            },
        ));
    }
}
