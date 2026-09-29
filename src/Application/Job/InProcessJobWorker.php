<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\Runtime\SleeperInterface;
use Zef\Framework\Runtime\SystemSleeper;
use Zef\Framework\Validation\Identifier;

final class InProcessJobWorker
{
    /**
     * @var array<string,callable>
     */
    private array $handlers = [];

    /**
     * @var list<JobMiddlewareInterface>
     */
    private array $middleware = [];
    private bool $frozen = false;
    private bool $running = false;

    public function __construct(
        private readonly JobQueueInterface $queue,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly ?JobIdempotencyStoreInterface $idempotency = null,
        private readonly ?JobQueueInterface $deadLetterQueue = null,
        private readonly int $pollIntervalMs = 10,
        private readonly SleeperInterface $sleeper = new SystemSleeper(),
        private readonly ?int $jobTimeoutMs = null,
    ) {
        if ($pollIntervalMs < 0 || $pollIntervalMs > 60_000) {
            throw new \InvalidArgumentException('Invalid job poll interval.');
        }
        if ($this->jobTimeoutMs !== null && $this->jobTimeoutMs < 1) {
            throw new \InvalidArgumentException('Job timeout must be positive.');
        }
        if ($deadLetterQueue instanceof JobQueueInterface && $deadLetterQueue === $queue) {
            // Same instance as main queue: unknown-type / max-attempt jobs
            // would be re-enqueued where they came from, re-dequeued and
            // re-DLQ'd forever — an unobservable infinite loop.
            throw new \InvalidArgumentException('Dead-letter queue must differ from the main queue.');
        }
    }

    public function register(string $jobType, callable|JobHandlerInterface|JobInterface $handler): void
    {
        $this->assertMutable();
        Identifier::assertMessageType($jobType, 'job type');
        if (isset($this->handlers[$jobType])) {
            throw new \LogicException("Job handler already registered for '{$jobType}'.");
        }
        if ($handler instanceof JobInterface) {
            $this->handlers[$jobType] =
                static fn (JobEnvelope $_job, JobContext $context): mixed => $handler->handle($context);
        } elseif ($handler instanceof JobHandlerInterface) {
            $this->handlers[$jobType] = $handler(...);
        } else {
            $this->handlers[$jobType] = $handler;
        }
    }

    public function use(JobMiddlewareInterface $middleware): void
    {
        $this->assertMutable();
        $this->middleware[] = $middleware;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Process jobs from the queue.
     *
     * Semantics: $maxJobs is a CAP, not a target. With $drain = false
     * (daemon mode, the default) an empty queue idles with a 1ms-floor
     * sleep until $stopSignal() fires or $maxJobs is reached — run() then
     * blocks forever by design. With $drain = true (batch mode) the loop
     * returns as soon as the queue is empty, which is the deterministic
     * way to drain an InMemoryJobQueue in tests/one-shot scripts.
     */
    public function run(
        int $maxJobs = 0,
        ?callable $stopSignal = null,
        ?callable $onResult = null,
        bool $drain = false,
    ): int {
        if ($maxJobs < 0) {
            throw new \InvalidArgumentException('maxJobs cannot be negative.');
        }
        if (!$this->frozen) {
            $this->freeze();
        }
        $processed = 0;
        $this->running = true;

        try {
            while (
                ($maxJobs === 0 || $processed < $maxJobs)
                && ($stopSignal === null || !$stopSignal())
            ) {
                $job = $this->queue->dequeue();
                if (!$job instanceof JobEnvelope) {
                    if ($drain) {
                        // Batch mode: queue is empty — return instead of
                        // idling forever.
                        break;
                    }
                    // pollIntervalMs=0 previously busy-spun (~470k
                    // iterations/s on an empty queue). Enforce a 1ms floor
                    // for the idle path.
                    $this->sleeper->sleepMilliseconds(max(1, $this->pollIntervalMs));

                    continue;
                }
                $result = $this->execute($job);
                // run() used to discard every JobResult, making failed /
                // unknown-type jobs invisible without a DLQ.
                if ($onResult !== null) {
                    $onResult($result);
                }
                ++$processed;
            }
        } finally {
            $this->running = false;
        }

        return $processed;
    }

    public function processOne(): ?JobResult
    {
        if (!$this->frozen) {
            $this->freeze();
        }
        $job = $this->queue->dequeue();

        return $job instanceof JobEnvelope ? $this->execute($job) : null;
    }

    private function execute(JobEnvelope $job): JobResult
    {
        $handler = $this->handlers[$job->jobType] ?? null;
        if (!is_callable($handler)) {
            // The envelope was already destructively dequeued; letting the
            // exception escape would lose the job AND abort the whole run
            // loop. An unknown job type is a permanent failure: route it
            // to the DLQ and report a failed result instead.
            return new JobResult(
                $job->jobId,
                false,
                new JobExecutionException("No handler registered for '{$job->jobType}'."),
                $job->attempt,
                $this->deadLetter($job),
            );
        }
        $run = $this->buildRunner($handler, $job, $this->contextFor($job));

        try {
            $result = $this->idempotency instanceof JobIdempotencyStoreInterface
                ? $this->idempotency->remember(
                    hash('sha256', $job->jobType . '|' . $job->jobId),
                    $run,
                )
                : $run();

            return new JobResult($job->jobId, true, $result, $job->attempt);
        } catch (\Throwable $e) {
            return $this->resultForFailure($job, $e);
        }
    }

    /**
     * Context handed to handlers, carrying the optional deadline
     * (N-17, issue #176): null keeps the legacy no-timeout behaviour.
     */
    private function contextFor(JobEnvelope $job): JobContext
    {
        $context = new JobContext($job->jobId, $job->attempt, $job->correlationId, $job->traceParent, $job->headers);
        if ($this->jobTimeoutMs !== null) {
            $context = $context->withDeadlineMs($this->jobTimeoutMs);
        }

        return $context;
    }

    private function buildRunner(callable $handler, JobEnvelope $job, JobContext $context): \Closure
    {
        return function () use ($handler, $job, $context): mixed {
            $next = \Closure::fromCallable($handler);
            for ($i = count($this->middleware) - 1; $i >= 0; --$i) {
                $layer = $this->middleware[$i];
                $next = static fn (JobEnvelope $msg, JobContext $ctx): mixed => $layer->process($msg, $ctx, $next);
            }
            $context->throwIfCancelled();

            return $next($job, $context);
        };
    }

    private function resultForFailure(JobEnvelope $job, \Throwable $e): JobResult
    {
        if (!$this->retryPolicy->shouldRetry($job->attempt)) {
            return new JobResult($job->jobId, false, $e, $job->attempt, $this->deadLetter($job));
        }

        try {
            $this->queue->enqueue($job->nextAttempt($this->retryPolicy->delayMs($job->attempt)));
        } catch (\Throwable) {
            // Retry enqueue failed (queue full): the destructively
            // dequeued job would VANISH and the exception would abort
            // the whole run(). Dead-letter if possible and report a
            // terminal failure so execute() always yields a JobResult.
            return new JobResult($job->jobId, false, $e, $job->attempt, $this->deadLetter($job));
        }

        return new JobResult($job->jobId, false, $e, $job->attempt, false, true);
    }

    /**
     * Best-effort dead-lettering of a destructively dequeued envelope.
     * DLQ enqueue must never abort the worker loop: a full/broken DLQ
     * only flips the reported flag to FALSE, otherwise observability
     * would lie about a job that was actually stored.
     */
    private function deadLetter(JobEnvelope $job): bool
    {
        if (!$this->deadLetterQueue instanceof JobQueueInterface) {
            return false;
        }
        try {
            $this->deadLetterQueue->enqueue($job);
        } catch (\Throwable) {
            // Best-effort by contract: a full or broken DLQ is reported as
            // "not stored" instead of escaping the worker loop.
            return false;
        }

        return true;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('Job worker is frozen.');
        }
    }
}
