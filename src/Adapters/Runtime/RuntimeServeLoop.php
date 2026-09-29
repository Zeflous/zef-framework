<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (adapters layer).
 * Accept/dispatch serve loop extracted from RoadRunnerRuntime
 * (php:S1448/S2042): gates each worker cycle (memory ceiling, worker
 * wait, admission), dispatches admitted requests through the
 * application, and maps cycle outcomes onto the process exit code.
 * Behaviour-identical to the loop previously inlined in
 * RoadRunnerRuntime::run().
 */

namespace Zef\Framework\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Application;
use Zef\Framework\Observability\Telemetry;

final class RuntimeServeLoop
{
    /** Keep serving; a dispatch failure's exit contribution rides on the outcome value. */
    private const int CYCLE_CONTINUE = 0;

    /** Keep serving after a failed dispatch: exit code 1 is sticky. */
    private const int CYCLE_CONTINUE_FAILED = 1;

    /** Worker signalled a graceful end: break, exit code untouched. */
    private const int CYCLE_STOPPED = 2;

    /** Worker wait failed: break with exit code 1. */
    private const int CYCLE_FAILED = 3;

    /** Memory ceiling breached: break with exit code 2. */
    private const int CYCLE_MEMORY = 4;

    private bool $stopRequested = false;
    private int $handled = 0;

    public function __construct(
        private readonly Application $application,
        private readonly WorkerInterface $worker,
        private readonly RuntimeGovernor $governor,
        private readonly RuntimeResponder $responder,
        private readonly int $maxJobs = 0,
    ) {}

    /** Runs the accept/dispatch loop until drained; returns the process exit code. */
    public function serve(): int
    {
        $this->stopRequested = false;
        $exitCode = 0;
        while (!$this->stopRequested && $this->worker->isRunning()) {
            $outcome = $this->serveOneCycle();
            if ($outcome === self::CYCLE_CONTINUE_FAILED) {
                $exitCode = 1;

                continue;
            }
            if ($outcome !== self::CYCLE_CONTINUE) {
                $exitCode = match ($outcome) {
                    self::CYCLE_FAILED => 1,
                    self::CYCLE_MEMORY => 2,
                    default => $exitCode,
                };

                break;
            }
        }

        return $exitCode;
    }

    /** Cooperative stop: flags the loop, drains the lifecycle and halts the worker. */
    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->governor->transition('draining');

        try {
            $this->worker->stop();
        } catch (\Throwable) {
            // The worker is already shutting down or broken: a failed stop
            // request must not mask the shutdown path already in progress.
        }
    }

    public function handledRequests(): int
    {
        return $this->handled;
    }

    private function serveOneCycle(): int
    {
        $this->governor->emitResourceHealth();
        $gate = $this->awaitRequest();
        if ($gate instanceof ServerRequestInterface && $this->governor->admit()) {
            ++$this->handled;
            $outcome = $this->dispatch($gate) === 0 ? self::CYCLE_CONTINUE : self::CYCLE_CONTINUE_FAILED;

            return $this->settleAfterCycle($outcome);
        }
        if (is_int($gate)) {
            return $gate;
        }
        $this->responder->respondServiceUnavailable();

        return self::CYCLE_CONTINUE;
    }

    /**
     * Gate the next cycle: memory ceiling first, then the worker wait.
     *
     * @return int|ServerRequestInterface the request to dispatch, or the terminal cycle outcome
     */
    private function awaitRequest(): int|ServerRequestInterface
    {
        if ($this->governor->overMemoryLimit()) {
            $this->requestStop();

            return self::CYCLE_MEMORY;
        }

        try {
            $request = $this->worker->waitRequest();
        } catch (\Throwable $e) {
            $this->responder->reportWorkerFailure($e);
            $this->requestStop();

            return self::CYCLE_FAILED;
        }
        if (!$request instanceof ServerRequestInterface) {
            return self::CYCLE_STOPPED;
        }

        return $request;
    }

    /** Post-dispatch gates: a memory breach upgrades the outcome; job exhaustion just drains. */
    private function settleAfterCycle(int $outcome): int
    {
        if (!$this->governor->overMemoryLimit()) {
            if ($this->maxJobs > 0 && $this->handled >= $this->maxJobs) {
                $this->requestStop();
            }

            return $outcome;
        }
        $this->requestStop();

        return self::CYCLE_MEMORY;
    }

    /** Serves exactly one admitted request; returns its exit-code contribution (0 or 1). */
    private function dispatch(ServerRequestInterface $request): int
    {
        try {
            $response = $this->application->handle($request);
            if ($this->application->isBooted() && $this->handled === 1) {
                $this->governor->transition('ready');
                $this->governor->recordEvent('worker.ready');
            }
            $this->responder->respond($response);
            $this->flushTelemetry();
        } catch (\Throwable $e) {
            $this->responder->reportWorkerFailure($e);
            $this->responder->respondInternalError();

            return 1;
        } finally {
            $this->application->runtimeAfterRequest();
            $this->governor->complete();
        }

        return 0;
    }

    private function flushTelemetry(): void
    {
        try {
            $telemetry = $this->application->getContainer()->get(Telemetry::class);
            if ($telemetry instanceof Telemetry) {
                $telemetry->flush();
            }
        } catch (\Throwable) {
            // Telemetry flush is best-effort after a served response: a
            // broken exporter must not turn the request into a failure.
        }
    }
}
