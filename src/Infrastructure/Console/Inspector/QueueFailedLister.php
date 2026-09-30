<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Console (Infrastructure layer: operational tooling)
 * Added in v2.32.0 (queue:failed — inspect the persistent failed-job stream).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Job\JobEnvelope;

/**
 * `bin/zef queue:failed` — read-only inspection of a failed-job queue
 * (v2.32.0). The failed queue is a persistent stream of dead-lettered
 * envelopes: wire a second RedisStreamJobQueue under a dedicated name as
 * InProcessJobWorker's deadLetterQueue, then list what landed there.
 *
 * The entries arrive via a provider closure built by the composition root
 * (bin/zef) so this class stays driver-agnostic and testable without a
 * live Redis — the closure owns both the queue resolution and the peek
 * budget, mirroring how QueueWorker receives its worker factory.
 *
 *   $cli = new QueueFailedLister(fn (int $max): iterable => $queue->peek($max), $io);
 *
 * Options (parsed --key=value switches):
 *   --max=<n>   entry budget (default 50)
 */
final readonly class QueueFailedLister
{
    private const int DEFAULT_MAX = 50;

    /**
     * @param (\Closure(int): iterable<mixed>) $jobs provider of the failed entries (oldest-first);
     *                                              each yield is guarded at runtime to be a JobEnvelope
     * @param ConsoleIO                 $io   console output port
     */
    public function __construct(
        private \Closure $jobs,
        private ConsoleIO $io,
    ) {}

    /**
     * Print the failed-job table.
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     *
     * @return int process exit code (0 = clean, 1 = provider failure)
     */
    public function run(array $options): int
    {
        $raw = $options['max'] ?? null;
        $max = ($raw === null || \is_bool($raw)) ? self::DEFAULT_MAX : max(1, (int) $raw);

        try {
            $jobs = ($this->jobs)($max);
        } catch (\Throwable $e) {
            $this->io->err('queue:failed could not read the failed-job queue: ' . $e->getMessage());

            return 1;
        }

        $rows = is_iterable($jobs) ? $jobs : [];

        $this->io->out(sprintf(
            '%-40s %-28s %-8s %-28s %s',
            'JOB-ID',
            'TYPE',
            'ATTEMPT',
            'AVAILABLE-AT',
            'CORRELATION',
        ));
        $count = 0;
        foreach ($rows as $job) {
            if (!$job instanceof JobEnvelope) {
                $this->io->err('queue:failed provider yielded a non-envelope entry.');

                return 1;
            }
            $this->io->out(sprintf(
                '%-40s %-28s %-8d %-28s %s',
                $job->jobId,
                $job->jobType,
                $job->attempt,
                self::iso($job->availableAtUnixNano),
                $job->correlationId ?? '-',
            ));
            ++$count;
        }
        $this->io->out('');
        $this->io->out($count === 0
            ? 'no failed jobs (queue is empty)'
            : sprintf('%d failed job(s)', $count));

        return 0;
    }

    /**
     * Nano timestamp → ISO-8601 in UTC: the raw 19-digit value is unreadable
     * in a table, and the queue contract keeps timestamps clock-agnostic, so
     * UTC (with the raw seconds) is the least surprising rendering.
     * DateTimeImmutable never fails to format, so there is no fallback
     * branch to leave untested (64-bit PHP renders pre-epoch seconds as
     * negative years correctly).
     */
    private static function iso(int $availableAtUnixNano): string
    {
        $seconds = intdiv($availableAtUnixNano, 1_000_000_000);

        return new \DateTimeImmutable('@' . $seconds)->format('Y-m-d\TH:i:s\Z');
    }
}
