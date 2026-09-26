<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in v2.31.0 (Health Check v2: per-probe time budget).
 */

namespace Zef\Framework\Observability;

/**
 * Wraps a health indicator with a per-probe time budget.
 *
 * The timeout is enforced by the injected `$timeoutRunner` — pass the async
 * scheduler's timeout primitive for a REAL interruption of a hung probe:
 *
 *   new TimeoutHealthIndicator($dbProbe, 1.0, fn (callable $fn, float $s): mixed => $scheduler->timeout($s, $fn));
 *
 * Without a runner the probe runs directly (no interruption possible for
 * synchronous code); pair the aggregate with the overall deadline parameter
 * of {@see HealthAggregator::aggregate()} to bound the scrape anyway.
 *
 * Outcome containment: a runner timeout or a crashing probe yields
 * `HealthCheckResult::down()` with a sanitized message — never a throw — so
 * the aggregator's status math stays deterministic.
 */
final readonly class TimeoutHealthIndicator implements HealthIndicatorInterface
{
    public function __construct(
        private HealthIndicatorInterface $inner,
        private float $timeoutSeconds = 1.0,
        private ?\Closure $timeoutRunner = null,
    ) {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be > 0.');
        }
    }

    #[\Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        if ($this->timeoutRunner === null) {
            return $this->inner->check();
        }

        $inner = $this->inner;
        $seconds = $this->timeoutSeconds;
        try {
            $result = ($this->timeoutRunner)(
                static fn (): HealthCheckResult => $inner->check(),
                $seconds,
            );
        } catch (\Throwable $e) {
            return HealthCheckResult::down(
                'probe did not finish within '
                . number_format($this->timeoutSeconds, 3)
                . 's (' . substr($e::class, 0, 96) . ')',
            );
        }

        return $result instanceof HealthCheckResult
            ? $result
            : HealthCheckResult::down('invalid check result');
    }
}
