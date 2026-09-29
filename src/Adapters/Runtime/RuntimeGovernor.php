<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (adapters layer).
 * Lifecycle / admission / resource-health board extracted from
 * RoadRunnerRuntime (php:S1448/S2042): the worker lifecycle state machine,
 * request admission counters, memory saturation telemetry and the
 * operational control-plane checks. Behaviour-identical to the code
 * previously inlined in RoadRunnerRuntime.
 */

namespace Zef\Framework\Runtime;

use Zef\Framework\Application;
use Zef\Framework\Observability\Telemetry;

final class RuntimeGovernor
{
    /**
     * @var null|array{state:string,instance_id:string,worker_id:string,started_at_ns:int}
     */
    private ?array $lifecycle = null;

    /**
     * @var array{admitted:int,completed:int,rejected:int,dropped:int,saturation:int}
     */
    private array $resourceCounters = [
        'admitted' => 0,
        'completed' => 0,
        'rejected' => 0,
        'dropped' => 0,
        'saturation' => 0,
    ];

    private int $inFlight = 0;

    /**
     * @param array<string,bool|float|int|string> $runtimeConfig
     */
    public function __construct(
        private readonly Application $application,
        private readonly array $runtimeConfig,
        private readonly int $memoryLimitBytes,
    ) {
    }

    public function initialize(): void
    {
        $instance = bin2hex(random_bytes(16));
        $worker = bin2hex(random_bytes(8));
        $this->lifecycle = [
            'state' => 'starting',
            'instance_id' => $instance,
            'worker_id' => $worker,
            'started_at_ns' => hrtime(true),
        ];
        $boundary = $this->compatibilityBoundary();
        if ($boundary['enabled']) {
            $this->recordEvent('runtime.compatibility.boundary.ready');
        }
        if (
            (bool) $this->runtimeConfig['control_plane_enabled']
            && !$this->validateControlCommand('lifecycle.status')
        ) {
            throw new \LogicException('Operational control boundary failed closed.');
        }
    }

    public function transition(string $next): void
    {
        if ($this->lifecycle === null) {
            return;
        }
        $current = $this->lifecycle['state'];
        $allowed = [
            'starting' => ['ready', 'draining', 'stopped'],
            'ready' => ['draining', 'stopped'],
            'draining' => ['stopped'],
            'stopped' => [],
        ];
        if ($current === $next) {
            return;
        }
        if (!in_array($next, $allowed[$current] ?? [], true)) {
            throw new \LogicException("Illegal runtime lifecycle transition {$current} -> {$next}.");
        }
        $this->lifecycle['state'] = $next;
    }

    public function recordEvent(string $event): void
    {
        try {
            $telemetry = $this->application->getContainer()->get(Telemetry::class);
            if ($telemetry instanceof Telemetry) {
                $telemetry->recordLog('INFO', $event, ['event.name' => $event]);
                $telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => $event]);
            }
        } catch (\Throwable) {
            // Lifecycle telemetry is best-effort: a missing or broken
            // telemetry service must never take the worker down.
        }
    }

    public function admit(): bool
    {
        $capacity = (int) $this->runtimeConfig['resource_capacity'];
        if ($this->inFlight >= $capacity) {
            ++$this->resourceCounters['rejected'];
            ++$this->resourceCounters['saturation'];
            $this->recordResource('rejected');

            return false;
        }
        ++$this->inFlight;
        ++$this->resourceCounters['admitted'];

        return true;
    }

    /** Post-request bookkeeping: releases the admission slot and counts completion. */
    public function complete(): void
    {
        $this->inFlight = max(0, $this->inFlight - 1);
        ++$this->resourceCounters['completed'];
    }

    public function overMemoryLimit(): bool
    {
        return $this->memoryLimitBytes > 0 && memory_get_usage(true) > $this->memoryLimitBytes;
    }

    public function emitResourceHealth(): void
    {
        if ($this->memoryLimitBytes <= 0) {
            return;
        }
        $usage = memory_get_usage(true);
        // No max(1, ...) guard: the early return above already guarantees
        // memoryLimitBytes >= 1 here, so the clamp was unreachable-in-effect.
        $ratio = ($usage / $this->memoryLimitBytes) * 100;
        if ($ratio >= (int) $this->runtimeConfig['saturation_percent']) {
            ++$this->resourceCounters['saturation'];
            $this->recordResource('saturated', ['memory.percent' => round($ratio, 2)]);
        }
    }

    /** @param array<string,mixed> $attributes */
    private function recordResource(string $event, array $attributes = []): void
    {
        try {
            $telemetry = $this->application->getContainer()->get(Telemetry::class);
            if ($telemetry instanceof Telemetry) {
                $telemetry->recordLog(
                    'INFO',
                    'runtime.resource.' . $event,
                    array_merge(['event.name' => 'runtime.resource.' . $event], $attributes),
                );
                $telemetry->meter()->increment(
                    'zef.runtime.resource.events.total',
                    1,
                    ['event.name' => $event],
                );
            }
        } catch (\Throwable) {
            // Resource telemetry is best-effort: a missing or broken
            // telemetry service must never take the worker down.
        }
    }

    /** @return list<string> */
    private function allowedControlCommands(): array
    {
        return ['diagnostics.snapshot', 'lifecycle.status', 'config.reload'];
    }

    private function validateControlCommand(string $command): bool
    {
        if (!(bool) $this->runtimeConfig['control_plane_enabled']) {
            return false;
        }

        return in_array($command, $this->allowedControlCommands(), true);
    }

    /** @return array{enabled:bool,adapters:list<string>} */
    private function compatibilityBoundary(): array
    {
        return [
            'enabled' => (bool) $this->runtimeConfig['distributed_compatibility'],
            'adapters' => ['external_state', 'messaging', 'cache', 'orchestration'],
        ];
    }
}
