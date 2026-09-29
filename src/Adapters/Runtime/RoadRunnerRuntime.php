<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Runtime;

use Zef\Framework\Application;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;

final class RoadRunnerRuntime implements RuntimeInterface
{
    private bool $running = false;
    private bool $started = false;

    /** @var array<string,bool|float|int|string> */
    private readonly array $runtimeConfig;

    private ?RuntimeGovernor $governor = null;
    private ?RuntimeSignalManager $signalManager = null;
    private ?RuntimeResponder $responder = null;
    private ?RuntimeServeLoop $serveLoop = null;

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
        $runtimeGovernor = $this->governor();
        $runtimeGovernor->initialize();
        $this->signals()->install();
        $runtimeGovernor->transition('starting');
        $runtimeGovernor->recordEvent('worker.started');
        $exitCode = 0;

        try {
            $exitCode = $this->serveLoop()->serve();
        } finally {
            $runtimeGovernor->transition('stopped');
            $runtimeGovernor->recordEvent($exitCode === 0 ? 'worker.terminated' : 'worker.recovery.detected');
            $this->signals()->restore();
            $this->running = false;
            $this->application->shutdown();
        }

        return $exitCode;
    }

    #[\Override]
    public function stop(): void
    {
        $this->serveLoop()->requestStop();
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    public function handledRequests(): int
    {
        return $this->serveLoop()->handledRequests();
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

    /** Collaborators are built lazily: php:S2830 forbids object creation in the constructor. */
    private function governor(): RuntimeGovernor
    {
        $this->governor ??= new RuntimeGovernor(
            $this->application,
            $this->runtimeConfig,
            $this->memoryLimitBytes,
        );

        return $this->governor;
    }

    private function signals(): RuntimeSignalManager
    {
        $this->signalManager ??= new RuntimeSignalManager(
            $this->installSignalHandlers,
            fn () => $this->stop(),
        );

        return $this->signalManager;
    }

    private function responder(): RuntimeResponder
    {
        $this->responder ??= new RuntimeResponder($this->worker);

        return $this->responder;
    }

    private function serveLoop(): RuntimeServeLoop
    {
        $this->serveLoop ??= new RuntimeServeLoop(
            $this->application,
            $this->worker,
            $this->governor(),
            $this->responder(),
            $this->maxJobs,
        );

        return $this->serveLoop;
    }
}
