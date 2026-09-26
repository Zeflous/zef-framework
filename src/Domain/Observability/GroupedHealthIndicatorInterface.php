<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in v2.31.0 (Health Check v2: liveness/readiness grouping).
 */

namespace Zef\Framework\Observability;

/**
 * Optional extension for {@see HealthIndicatorInterface}: declares which
 * health view the indicator belongs to.
 *
 * Groups:
 * - `live`  — process-local truth (event loop alive, worker heartbeat).
 *             Never touches dependencies; if this fails the instance should
 *             be restarted, not drained.
 * - `ready` — dependency-backed truth (database, cache, queue reachable).
 *             Gates traffic routing behind the load balancer.
 *
 * Indicators that do NOT implement this interface are treated as `ready`,
 * so existing setups keep their behaviour unchanged.
 */
interface GroupedHealthIndicatorInterface
{
    /**
     * @return string 'live' | 'ready' (custom group names are matched exactly
     *                 by {@see \Zef\Framework\Observability\HealthAggregator::aggregateFor()})
     */
    public function healthGroup(): string;
}
