<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Console (Infrastructure layer: operational tooling)
 * Added in v2.32.0 (queue:work — unified job-queue worker over the wired
 * JobQueueInterface driver: InMemory, PDO, or Redis Streams).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobResult;

/**
 * `bin/zef queue:work` — process jobs from the wired queue driver (v2.32.0).
 *
 * The worker is injected via a factory closure from bin/zef (the composition
 * root), mirroring how OutboxWorker receives its relay — this class stays
 * App-layer-independent (Deptrac) and is reusable from custom binaries:
 *
 *   $cli = new QueueWorker(fn (): InProcessJobWorker => $container->get(InProcessJobWorker::class), $io);
 *
 * Options (parsed --key=value switches):
 *   --once        drain the queue and exit when it runs empty (batch mode)
 *   --max=<n>     stop after n processed jobs (default: run until stopped)
 *   --memory=<mb> memory-leak guard: stop the worker when the real memory
 *                 usage (memory_get_usage(true)) crosses <mb> MiB. A
 *                 long-running stateful worker that creeps past the budget
 *                 exits 2 so the supervisor (systemd Restart=on-failure,
 *                 RoadRunner, K8s) replaces the process instead of hosting
 *                 a leak — the same contract Laravel's queue:work --memory
 *                 popularised, checked between jobs, never mid-job: the
 *                 port's dequeue is destructive-by-design, so a signal
 *                 arriving mid-job lets that job finish (its retry/DLQ path
 *                 still applies on failure) instead of vanishing.
 *
 * The idle poll interval, retry policy, idempotency store and DLQ are wiring
 * concerns of the InProcessJobWorker the app's ConfigProvider registers —
 * the CLI only drives the loop.
 *
 * SIGTERM/SIGINT (when the pcntl extension is loaded) flip the stop flag,
 * which the worker honours BETWEEN jobs; the memory guard shares the same
 * between-jobs checkpoint.
 */
final readonly class QueueWorker
{
    /** Exit code when the --memory guard tripped (supervisor should restart). */
    public const int EXIT_MEMORY = 2;

    /**
     * @param (\Closure(): InProcessJobWorker) $workerFactory builds the worker from the composition root
     * @param ConsoleIO                        $io          console output port
     */
    public function __construct(
        private \Closure $workerFactory,
        private ConsoleIO $io,
    ) {}

    /**
     * Run the worker loop.
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     *
     * @return int process exit code (0 = clean, 1 = worker could not be built,
     *             2 = the --memory guard tripped — restart the process)
     */
    public function run(array $options): int
    {
        try {
            $worker = ($this->workerFactory)();
        } catch (\Throwable $e) {
            $this->io->err('queue:work could not build the job worker: ' . $e->getMessage());

            return 1;
        }

        $maxJobs = $this->nullableIntOption($options, 'max');
        $once = (bool) ($options['once'] ?? false);
        $memoryMb = $this->nullableIntOption($options, 'memory');

        $running = true;
        $this->installStopHandler($this->io, $running);

        // The guard rides the stopSignal checkpoint InProcessJobWorker
        // already provides: evaluated between jobs (every loop iteration),
        // so a tripped budget stops the loop after the in-flight job
        // instead of mid-flight. false while the budget holds.
        $guard = $memoryMb === null
            ? null
            : static fn (): bool => memory_get_usage(true) > $memoryMb * 1024 * 1024;

        $processed = $worker->run(
            $maxJobs ?? 0,
            static fn (): bool => !$running || ($guard !== null && $guard()),
            function (JobResult $result): void { $this->report($result); },
            $once,
        );

        if ($guard !== null && $guard()) {
            $this->io->out(sprintf(
                'queue:work — memory guard tripped at %d MiB (budget %d MiB), %d job(s) processed — restart advised',
                intdiv(memory_get_usage(true), 1024 * 1024),
                $memoryMb,
                $processed,
            ));

            return self::EXIT_MEMORY;
        }

        $this->io->out("queue:work — {$processed} job(s) processed");

        return 0;
    }

    /**
     * Per-job result line: the four terminal/transient states the worker's
     * JobResult can carry, rendered distinctly so an operator tailing the
     * log can tell a retry from a dead-letter at a glance.
     */
    private function report(JobResult $result): void
    {
        if ($result->completed) {
            $this->io->out(sprintf(
                'job %s done (attempt %d)',
                $result->jobId,
                $result->attempt,
            ));

            return;
        }
        if ($result->willRetry) {
            $this->io->out(sprintf(
                'job %s failed (attempt %d) — retry scheduled',
                $result->jobId,
                $result->attempt,
            ));

            return;
        }
        $suffix = $result->deadLettered ? 'dead-lettered' : 'failed';
        $this->io->out(sprintf(
            'job %s failed (attempt %d) — %s',
            $result->jobId,
            $result->attempt,
            $suffix,
        ));
    }

    /**
     * Installs the SIGTERM/SIGINT stop handlers (when the pcntl extension
     * is loaded). The flag is checked between jobs by the stopSignal
     * closure handed to InProcessJobWorker::run().
     *
     * @param bool $running by-reference loop flag flipped to false on signal
     */
    private function installStopHandler(ConsoleIO $io, bool &$running): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handleStop = static function (int $signal) use ($io, &$running): void {
            $io->out("queue:work signal {$signal} — stopping after the current job");
            $running = false;
        };
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handleStop);
        }
        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handleStop);
        }
    }

    /**
     * Same bare-flag semantics as OutboxWorker::nullableIntOption() for
     * switches whose documented default is "no bound" (--max).
     *
     * @param array<string, bool|string> $options parsed CLI switches
     */
    private function nullableIntOption(array $options, string $key): ?int
    {
        $raw = $options[$key] ?? null;
        if ($raw === null || \is_bool($raw)) {
            return null;
        }

        return max(1, (int) $raw);
    }
}
