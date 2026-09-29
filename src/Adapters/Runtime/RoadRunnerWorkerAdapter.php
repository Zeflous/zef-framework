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

/**
 * Bug fix #12: validates waitRequest/respond in constructor.
 */
final readonly class RoadRunnerWorkerAdapter implements WorkerInterface
{
    public function __construct(private object $worker)
    {
        if (!method_exists($this->worker, 'waitRequest')) {
            throw new \InvalidArgumentException('RoadRunner HTTP worker must expose waitRequest().');
        }
        if (!method_exists($this->worker, 'respond')) {
            throw new \InvalidArgumentException('RoadRunner HTTP worker must expose respond().');
        }
    }

    #[\Override]
    public function waitRequest(): ?ServerRequestInterface
    {
        $request = $this->worker->waitRequest();

        return $request instanceof ServerRequestInterface ? $request : null;
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        $this->worker->respond($response);
    }

    #[\Override]
    public function error(string $message): void
    {
        if (method_exists($this->worker, 'error')) {
            $this->worker->error($message);

            return;
        }
        error_log($message);
    }

    #[\Override]
    public function stop(): void
    {
        if (method_exists($this->worker, 'stop')) {
            $this->worker->stop();

            return;
        }
        if (method_exists($this->worker, 'getWorker')) {
            $inner = $this->worker->getWorker();
            if (is_object($inner) && method_exists($inner, 'stop')) {
                $inner->stop();
            }
        }
    }

    #[\Override]
    public function isRunning(): bool
    {
        if (method_exists($this->worker, 'isStopped')) {
            return !$this->worker->isStopped();
        }

        return true;
    }
}
