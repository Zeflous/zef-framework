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

final class InMemoryWorker implements WorkerInterface
{
    /**
     * @var list<ResponseInterface>
     */
    private array $responses = [];
    private bool $running = true;

    /** @param list<ServerRequestInterface> $requests */
    public function __construct(private array $requests) {}

    #[\Override]
    public function waitRequest(): ?ServerRequestInterface
    {
        return array_shift($this->requests) ?? null;
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    #[\Override]
    public function error(string $message): void
    {
        // Intentionally empty: this in-memory worker has no error channel to
        // observe — the WorkerInterface contract requires the hook, while test
        // suites only assert on waitRequest()/respond()/responses().
    }

    #[\Override]
    public function stop(): void
    {
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @return list<ResponseInterface> */
    public function responses(): array
    {
        return $this->responses;
    }
}
