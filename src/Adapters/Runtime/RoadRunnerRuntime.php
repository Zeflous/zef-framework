<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Application;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Observability\Telemetry;

final class RoadRunnerRuntime implements RuntimeInterface
{
    private bool $running = false;
    private int $handled = 0;
    private bool $stopRequested = false;
    private bool $signalsInstalled = false;
    private bool $started = false;

    /**
     * @var null|array{state:string,instance_id:string,worker_id:string,started_at_ns:int}
     */
    private ?array $lifecycle = null;

    /**
     * @var array<string,bool|float|int|string>
     */
    private array $runtimeConfig;

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
     * @var list<int>
     */
    private array $ownedSignals = [];

    public function __construct(
        private readonly Application $application,
        private readonly WorkerInterface $worker,
        private readonly int $maxJobs = 0,
        private readonly int $memoryLimitBytes = 0,
        private readonly bool $installSignalHandlers = true,
        private readonly ?EnvInterface $env = null,
    ) {
        if ($maxJobs < 0) {
            throw new \InvalidArgumentException('maxJobs must be >= 0.');
        }
        if ($memoryLimitBytes < 0) {
            throw new \InvalidArgumentException('memoryLimitBytes must be >= 0.');
        }
        $this->runtimeConfig = $this->loadRuntimeConfig();
    }

    #[\Override]
    public function run(): int
    {
        if ($this->running) {
            throw new \LogicException('Runtime is already running.');
        }
        if ($this->started) {
            throw new \LogicException('Runtime instances are single-use and cannot be restarted after shutdown.');
        }
        $this->started = true;
        $this->running = true;
        $this->initializeLifecycle();
        $this->stopRequested = false;
        $this->installSignals();
        $this->transitionLifecycle('starting');
        $this->recordLifecycle('worker.started');
        $exitCode = 0;

        try {
            while (!$this->stopRequested && $this->worker->isRunning()) {
                $this->emitResourceHealth();
                if ($this->memoryLimitBytes > 0 && memory_get_usage(true) > $this->memoryLimitBytes) {
                    $exitCode = 2;
                    $this->stop();

                    break;
                }

                try {
                    $request = $this->worker->waitRequest();
                } catch (\Throwable $e) {
                    $this->reportWorkerFailure($e);
                    $exitCode = 1;
                    $this->stop();

                    break;
                }
                if (!$request instanceof ServerRequestInterface) {
                    break;
                }
                if (!$this->admitRequest()) {
                    $this->safeRespond(new Response(503, ['Content-Type' => 'application/json'], '{"error":"Service Unavailable","status":503}'));

                    continue;
                }
                ++$this->handled;

                try {
                    $response = $this->application->handle($request);
                    if ($this->application->isBooted() && $this->handled === 1) {
                        $this->transitionLifecycle('ready');
                        $this->recordLifecycle('worker.ready');
                    }
                    $this->worker->respond($this->reconcileContentLength($response));

                    try {
                        $telemetry = $this->application->getContainer()->get(Telemetry::class);
                        if ($telemetry instanceof Telemetry) {
                            $telemetry->flush();
                        }
                    } catch (\Throwable) {
                    }
                } catch (\Throwable $e) {
                    $this->reportWorkerFailure($e);
                    $this->safeRespond(new Response(500, ['Content-Type' => 'application/json'], '{"error":"Internal Server Error","status":500}'));
                    $exitCode = 1;
                } finally {
                    $this->application->runtimeAfterRequest();
                    $this->inFlight = max(0, $this->inFlight - 1);
                    ++$this->resourceCounters['completed'];
                }
                if ($this->memoryLimitBytes > 0 && memory_get_usage(true) > $this->memoryLimitBytes) {
                    $exitCode = 2;
                    $this->stop();

                    break;
                }
                if ($this->maxJobs > 0 && $this->handled >= $this->maxJobs) {
                    $this->stop();
                }
            }
        } finally {
            $this->transitionLifecycle('stopped');
            $this->recordLifecycle($exitCode === 0 ? 'worker.terminated' : 'worker.recovery.detected');
            $this->restoreSignals();
            $this->running = false;
            $this->application->shutdown();
        }

        return $exitCode;
    }

    #[\Override]
    public function stop(): void
    {
        $this->stopRequested = true;
        $this->transitionLifecycle('draining');

        try {
            $this->worker->stop();
        } catch (\Throwable) {
        }
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    public function handledRequests(): int
    {
        return $this->handled;
    }

    /** @return array<string,bool|float|int|string> */
    private function loadRuntimeConfig(): array
    {
        $env = $this->env ?? new Env();
        $capacity = $env->readInt('ZEF_RUNTIME_RESOURCE_CAPACITY', 1, 1, 1024);
        $saturation = $env->readInt('ZEF_RUNTIME_SATURATION_PERCENT', 90, 50, 99);
        $controlRaw = strtolower(trim($env->readString('ZEF_RUNTIME_CONTROL_PLANE', 'off')));
        if (!in_array($controlRaw, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('Invalid ZEF_RUNTIME_CONTROL_PLANE.');
        }

        return [
            'resource_capacity' => $capacity,
            'saturation_percent' => $saturation,
            'control_plane_enabled' => $controlRaw === 'on',
            'distributed_compatibility' => true,
        ];
    }

    private function initializeLifecycle(): void
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
            $this->recordLifecycle('runtime.compatibility.boundary.ready');
        }
        if (
            (bool) $this->runtimeConfig['control_plane_enabled']
            && !$this->validateControlCommand('lifecycle.status')
        ) {
            throw new \LogicException('Operational control boundary failed closed.');
        }
    }

    private function transitionLifecycle(string $next): void
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

    private function admitRequest(): bool
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

    private function emitResourceHealth(): void
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

    private function recordLifecycle(string $event): void
    {
        try {
            $telemetry = $this->application->getContainer()->get(Telemetry::class);
            if ($telemetry instanceof Telemetry) {
                $telemetry->recordLog('INFO', $event, ['event.name' => $event]);
                $telemetry->meter()->increment('zef.lifecycle.events.total', 1, ['event.name' => $event]);
            }
        } catch (\Throwable) {
        }
    }

    private function reportWorkerFailure(\Throwable $e): void
    {
        try {
            $this->worker->error($e::class . ': ' . $e->getMessage());
        } catch (\Throwable) {
            @error_log($e::class . ': ' . $e->getMessage());
        }
    }

    private function safeRespond(ResponseInterface $response): void
    {
        try {
            $this->worker->respond($this->reconcileContentLength($response));
        } catch (\Throwable $e) {
            $this->reportWorkerFailure($e);
        }
    }

    /**
     * ZEF-DEEP-05 safety net: unlike the SAPI path (ResponseEmitter
     * reconciles lying framing headers), this runtime forwards headers to
     * the worker VERBATIM. A stale Content-Length — a 304/204/205 built from
     * a content-bearing 200, or any handler declaring more octets than its
     * stream holds — desyncs clients and keep-alive proxies (RFC 9110 §8.6
     * CL.CL smuggling), so the same reconciliation the emitter applies runs
     * here, centrally, for every outbound response.
     */
    private function reconcileContentLength(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        if (in_array($status, [204, 205, 304], true)) {
            // Bodyless statuses never carry a payload: the would-be length
            // is a stale artefact of the response they were derived from.
            return $this->withoutContentLength($response);
        }
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared === '') {
            return $response;
        }
        $size = $response->getBody()->getSize();
        if (!ctype_digit($declared) || $size === null || (int) $declared !== $size) {
            return $this->withoutContentLength($response);
        }

        return $response;
    }

    private function withoutContentLength(ResponseInterface $response): ResponseInterface
    {
        $reconciled = $response->withoutHeader('Content-Length');
        if (!$reconciled instanceof ResponseInterface) {
            throw new \LogicException('withoutHeader must preserve the response type.');
        }

        return $reconciled;
    }

    private function installSignals(): void
    {
        if (
            !$this->installSignalHandlers
            || $this->signalsInstalled
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')
        ) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $signals = [];
            if (defined('SIGTERM')) {
                $signals[] = SIGTERM;
            }
            if (defined('SIGINT')) {
                $signals[] = SIGINT;
            }
            if (in_array($signal, $signals, true)) {
                $this->stop();
            }
        };
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
            $this->ownedSignals[] = SIGTERM;
        }
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handler);
            $this->ownedSignals[] = SIGINT;
        }
        $this->signalsInstalled = true;
    }

    private function restoreSignals(): void
    {
        if (!$this->signalsInstalled || !function_exists('pcntl_signal')) {
            return;
        }
        foreach ($this->ownedSignals as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        $this->ownedSignals = [];
        $this->signalsInstalled = false;
    }
}
