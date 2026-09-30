<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Console (Infrastructure layer: operational tooling)
 * Added in v2.32.0 (queue:flush — purge the failed-job store).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;

/**
 * `bin/zef queue:flush` — purge dead-lettered jobs from the failed queue
 * (v2.32.0): the "I have read the failure, drop it" counterpart of
 * {@see QueueRetry}.
 *
 * The failed queue arrives as a factory closure from the composition root
 * (bin/zef), so the flush is driver-agnostic: it drains the port with
 * repeated dequeue() calls instead of a driver-specific truncate (DEL /
 * TRUNCATE TABLE). That keeps one code path for Redis streams, PDO tables
 * and InMemory queues alike; the dequeue ordering (priority DESC,
 * availability ASC) is irrelevant here — everything goes.
 *
 *   $cli = new QueueFlush(fn (): JobQueueInterface => $container->get('zef.queue.failed'), $io);
 *
 * Options (parsed --key=value switches):
 *   --max=<n>   purge at most n entries (default: purge until empty)
 *
 * A bounded --max exists for the same reason QueueRetry has one: an
 * operator watching a failed queue that is still growing (a live incident
 * feeding the DLQ) wants a bounded batch, not an unbounded race against
 * the producer.
 */
final readonly class QueueFlush
{
    /**
     * @param (\Closure(): JobQueueInterface) $failedQueue the dead-letter store
     * @param ConsoleIO                        $io         console output port
     */
    public function __construct(
        private \Closure $failedQueue,
        private ConsoleIO $io,
    ) {}

    /**
     * Run the flush loop.
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     *
     * @return int process exit code (0 = clean, 1 = the queue could not be built)
     */
    public function run(array $options): int
    {
        try {
            $failed = ($this->failedQueue)();
        } catch (\Throwable $e) {
            $this->io->err('queue:flush could not build the failed-job queue: ' . $e->getMessage());

            return 1;
        }

        $raw = $options['max'] ?? null;
        $budget = ($raw === null || \is_bool($raw)) ? 0 : max(1, (int) $raw);

        $purged = 0;
        while ($budget === 0 || $purged < $budget) {
            if (!$failed->dequeue() instanceof JobEnvelope) {
                break;
            }
            ++$purged;
        }

        $this->io->out(sprintf('%d failed job(s) flushed', $purged));

        return 0;
    }
}
