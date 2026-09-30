<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Console (Infrastructure layer: operational tooling)
 * Added in v2.32.0 (queue:retry — re-queue dead-lettered jobs to the main queue).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;

/**
 * `bin/zef queue:retry` — move dead-lettered jobs back to the main queue
 * (v2.32.0), the operational half of the failed-job story {@see
 * QueueFailedLister} opens.
 *
 * Both queues arrive as factory closures from the composition root (bin/zef)
 * so this class is driver-agnostic: the failed side is whatever
 * JobQueueInterface the app wired as InProcessJobWorker's deadLetterQueue
 * (a dedicated Redis stream, a PDO table, an InMemory queue in tests), and
 * the main side is the app's own queue. Moving a job is a port round-trip:
 * dequeue() from the failed queue, enqueue() into the main queue.
 *
 *   $cli = new QueueRetry(
 *       fn (): JobQueueInterface => $container->get(JobQueueInterface::class),
 *       fn (): JobQueueInterface => $container->get('zef.queue.failed'),
 *       $io,
 *   );
 *
 * Semantics:
 *   - Entries move oldest-claim-first in failed-queue order, up to --max.
 *   - The envelope is re-queued AS-IS (attempt preserved): a job whose
 *     attempt already exhausted the RetryPolicy dead-letters again on its
 *     next failure — retry is a re-delivery decision, not an attempt-reset.
 *   - A job whose id is already live in the main queue is SKIPPED, not
 *     moved: both durable drivers reject duplicate ids loudly (PDO
 *     UNIQUE(job_id), the Redis live-id SET), which here surfaces as the
 *     duplicate backstop doing its job instead of a worker crash.
 *
 * Options (parsed --key=value switches):
 *   --max=<n>   move at most n jobs (default 50)
 *   --all       move every entry (equivalent to a very large --max)
 */
final readonly class QueueRetry
{
    private const int DEFAULT_MAX = 50;

    /**
     * @param (\Closure(): JobQueueInterface) $mainQueue   the application's live queue
     * @param (\Closure(): JobQueueInterface) $failedQueue the dead-letter store
     * @param ConsoleIO                        $io         console output port
     */
    public function __construct(
        private \Closure $mainQueue,
        private \Closure $failedQueue,
        private ConsoleIO $io,
    ) {}

    /**
     * Run the retry loop.
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     *
     * @return int process exit code (0 = clean, 1 = a queue could not be built)
     */
    public function run(array $options): int
    {
        try {
            $main = ($this->mainQueue)();
            $failed = ($this->failedQueue)();
        } catch (\Throwable $e) {
            $this->io->err('queue:retry could not build the queues: ' . $e->getMessage());

            return 1;
        }

        $all = (bool) ($options['all'] ?? false);
        $budget = $all ? \PHP_INT_MAX : $this->intOption($options, 'max', self::DEFAULT_MAX);

        $moved = 0;
        $skipped = 0;
        $seen = [];
        while ($moved + $skipped < $budget) {
            $job = $failed->dequeue();
            if (!$job instanceof JobEnvelope) {
                break;
            }
            if (isset($seen[$job->jobId])) {
                // The previous skip re-queued this very envelope and the
                // failed queue delivered it back to the head — every
                // remaining entry has now been visited once, so stop
                // instead of burning the budget on the same cycle.
                $failed->enqueue($job);

                break;
            }
            $seen[$job->jobId] = true;

            try {
                $main->enqueue($job);
            } catch (\Throwable) {
                // Duplicate id or a full main queue: the failed entry has
                // already been destructively dequeued, so it is re-queued
                // where it came from — the operator sees a skip, not data
                // loss. Any other failure mode of the main queue is
                // reported the same way (bounded, non-fatal).
                $failed->enqueue($job);
                ++$skipped;
                $this->io->out('job ' . $job->jobId . ' skipped — already queued or queue rejected it');

                continue;
            }
            ++$moved;
            $this->io->out(sprintf(
                'job %s retried (attempt %d)',
                $job->jobId,
                $job->attempt,
            ));
        }

        $this->io->out('');
        $this->io->out(sprintf('%d retried, %d skipped', $moved, $skipped));

        return 0;
    }

    /**
     * Same bare-flag semantics as OutboxWorker::intOption().
     *
     * @param array<string, bool|string> $options parsed CLI switches
     */
    private function intOption(array $options, string $key, int $default, int $min = 1): int
    {
        $raw = $options[$key] ?? null;
        if ($raw === null || \is_bool($raw)) {
            return $default;
        }

        return max($min, (int) $raw);
    }
}
