<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Runtime\SleeperInterface;
use Zef\Framework\Runtime\SystemSleeper;

final class BatchSpanProcessor
{
    /**
     * @var list<SpanData>
     */
    private array $queue = [];
    private bool $shutdown = false;

    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly int $maxQueueSize = 2048,
        private readonly int $batchSize = 256,
        private readonly SleeperInterface $sleeper = new SystemSleeper(),
        private readonly ?EnvInterface $env = null,
    ) {
        if ($maxQueueSize < 1) {
            throw new \InvalidArgumentException('maxQueueSize must be >= 1.');
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be >= 1.');
        }
    }

    public function onEnd(SpanData $span): void
    {
        if ($this->shutdown || count($this->queue) >= $this->maxQueueSize) {
            return;
        }
        $this->queue[] = $span;
    }

    public function flush(): void
    {
        if ($this->queue === [] || $this->shutdown) {
            return;
        }
        $policy = RetryBackoffPolicy::fromEnvironment();
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
            $this->exportWithBackoff($batch, $policy);
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $env = $this->env ?? new Env();
        $deadline = microtime(true) + $env->readInt('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000) / 1000;
        while ($this->queue !== [] && microtime(true) < $deadline) {
            $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
            $this->exportBeforeDeadline($batch, $deadline);
        }
        $this->shutdown = true;

        try {
            $this->exporter->shutdown();
        } catch (\Throwable) {
            // Shutdown is best-effort by OTel contract: a failing exporter
            // must not prevent the processor from releasing its queue.
        }
        $this->queue = [];
    }

    public function isInMemoryExporter(): bool
    {
        return $this->exporter instanceof InMemorySpanExporter;
    }

    /**
     * Exports one batch, retrying transport failures according to the
     * shared backoff policy; invalid-argument failures abort the batch.
     *
     * @param list<SpanData> $batch
     */
    private function exportWithBackoff(array $batch, RetryBackoffPolicy $policy): void
    {
        $retryIndex = 0;
        while (true) {
            try {
                $this->exporter->export($batch);

                return;
            } catch (\InvalidArgumentException) {
                return;
            } catch (\Throwable) {
                if (!$policy->shouldRetry($retryIndex)) {
                    return;
                }
                $sleep = $policy->delayMs($retryIndex);
                if ($sleep > 0) {
                    $this->sleeper->sleepMilliseconds($sleep);
                }
                ++$retryIndex;
            }
        }
    }

    /**
     * Exports one batch during the shutdown drain window, bounded by three
     * attempts per batch and the drain deadline.
     *
     * @param list<SpanData> $batch
     */
    private function exportBeforeDeadline(array $batch, float $deadline): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->exporter->export($batch);

                return;
            } catch (\Throwable) {
                if ($attempt === 2 || microtime(true) >= $deadline) {
                    return;
                }
                if (function_exists('usleep')) {
                    usleep(50000);
                }
            }
        }
    }
}

// Immutable, bounded causal context for remote/distributed execution.
