<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — queue CLI harness tests (queue:work,
 * queue:failed, queue:retry, queue:flush).
 *
 * The CLIs are driven over InMemoryJobQueue pairs (the port's reference
 * implementation), so everything here is hermetic — no Redis, no PDO.
 * The queue classes under test live in Infrastructure/Console/Inspector;
 * their factories mimic what bin/zef's composition root wires.
 *
 * Zero bytes may reach the real STDERR (issue #94): every ConsoleIO goes
 * through HermeticConsoleIo and assertions read outLog()/errLog().
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\Inspector\QueueFailedLister;
use Zef\Framework\Console\Inspector\QueueFlush;
use Zef\Framework\Console\Inspector\QueueRetry;
use Zef\Framework\Console\Inspector\QueueWorker;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;
use Zef\Framework\Job\RetryPolicy;

/**
 * @internal
 */
final class QueueCliTest extends TestCase
{
    // ------------------------------------------------------------------
    // queue:work
    // ------------------------------------------------------------------

    public function testWorkerFactoryFailureExitsOne(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueWorker(static function (): never {
            throw new \RuntimeException('not wired');
        }, $io);

        self::assertSame(1, $cli->run([]));
        self::assertStringContainsString('queue:work could not build the job worker: not wired', implode("\n", $io->errLog()));
    }

    public function testOnceDrainsAndReportsEachJobState(): void
    {
        $io = HermeticConsoleIo::create();
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-wk-0001')); // t.job.ok → done
        $queue->enqueue($this->job('job-wk-boom', 1, 0, 't.job.boom')); // → default policy retries

        $cli = new QueueWorker($this->workerFactory($queue), $io);
        $exit = $cli->run(['once' => true]);

        self::assertSame(0, $exit);
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('job job-wk-0001 done (attempt 1)', $out);
        self::assertStringContainsString('job job-wk-boom failed (attempt 1) — retry scheduled', $out);
        self::assertStringContainsString('queue:work — 2 job(s) processed', $out);
    }

    public function testDeadLetterPathIsReportedDistinctly(): void
    {
        $io = HermeticConsoleIo::create();
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-wk-doom', 1, 0, 't.job.boom'));

        // maxAttempts=1: the first failure is terminal → dead-lettered.
        $cli = new QueueWorker($this->workerFactory($queue, $dlq, new RetryPolicy(1)), $io);
        $exit = $cli->run(['once' => true]);

        self::assertSame(0, $exit);
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('job job-wk-doom failed (attempt 1) — dead-lettered', $out);
        self::assertSame(1, $dlq->size(), 'the failed queue received the envelope');
    }

    public function testMaxCapsProcessedJobs(): void
    {
        $io = HermeticConsoleIo::create();
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-cap-0001'));
        $queue->enqueue($this->job('job-cap-0002'));
        $queue->enqueue($this->job('job-cap-0003'));

        $cli = new QueueWorker($this->workerFactory($queue), $io);
        $exit = $cli->run(['max' => '1']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('queue:work — 1 job(s) processed', implode("\n", $io->outLog()));
        self::assertSame(2, $queue->size(), 'the cap left the remaining jobs queued');
    }

    public function testMemoryGuardTripsAndExitsTwo(): void
    {
        $io = HermeticConsoleIo::create();
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-mem-0001'));

        // A 1 MiB budget is always already exceeded by the test process —
        // the guard must trip before the first job runs.
        $cli = new QueueWorker($this->workerFactory($queue), $io);
        $exit = $cli->run(['once' => true, 'memory' => '1']);

        self::assertSame(QueueWorker::EXIT_MEMORY, $exit);
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('memory guard tripped', $out);
        self::assertStringContainsString('restart advised', $out);
        self::assertStringContainsString('0 job(s) processed', $out);
        self::assertSame(1, $queue->size(), 'the guarded loop never claimed the job');
    }

    public function testBareMemoryFlagMeansNoGuard(): void
    {
        $io = HermeticConsoleIo::create();
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-mem-ok01'));

        // --memory without a value parses to bool true: the documented
        // bare-flag semantics fall back to "no guard" instead of casting
        // true to 1 MiB (OutboxWorker's Kilo-review rule).
        $cli = new QueueWorker($this->workerFactory($queue), $io);
        $exit = $cli->run(['once' => true, 'memory' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('queue:work — 1 job(s) processed', implode("\n", $io->outLog()));
    }

    // ------------------------------------------------------------------
    // queue:failed
    // ------------------------------------------------------------------

    public function testFailedListerRendersTheTable(): void
    {
        $io = HermeticConsoleIo::create();
        $jobs = [
            $this->job('job-fl-0001', 3, 1_700_000_000_000_000_000),
            $this->job('job-fl-0002', 1, 0),
        ];
        $cli = new QueueFailedLister(static fn (int $max): array => array_slice($jobs, 0, $max), $io);
        $exit = $cli->run([]);

        self::assertSame(0, $exit);
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('job-fl-0001', $out);
        self::assertStringContainsString('2023-11-14T22:13:20Z', $out, 'nano deadline renders as UTC ISO-8601');
        self::assertStringContainsString('2 failed job(s)', $out);
    }

    public function testFailedListerEmptyQueue(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueFailedLister(static fn (int $max): array => [], $io);

        self::assertSame(0, $cli->run([]));
        self::assertStringContainsString('no failed jobs (queue is empty)', implode("\n", $io->outLog()));
    }

    public function testFailedListerProviderFailureExitsOne(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueFailedLister(static function (int $max): never {
            throw new \RuntimeException('peek failed');
        }, $io);

        self::assertSame(1, $cli->run([]));
        self::assertStringContainsString('queue:failed could not read the failed-job queue: peek failed', implode("\n", $io->errLog()));
    }

    public function testFailedListerRejectsNonEnvelopeYield(): void
    {
        $io = HermeticConsoleIo::create();
        $provider = static fn (int $max): array => ['not-a-job'];
        $cli = new QueueFailedLister($provider, $io);

        self::assertSame(1, $cli->run([]));
        self::assertStringContainsString('queue:failed provider yielded a non-envelope entry.', implode("\n", $io->errLog()));
    }

    /**
     * @param array<string, bool|string> $options
     */
    #[DataProvider('failedMaxProvider')]
    public function testFailedListerMaxParsing(array $options, int $expectedMax): void
    {
        $io = HermeticConsoleIo::create();
        $seen = 0;
        $cli = new QueueFailedLister(static function (int $max) use (&$seen): array {
            $seen = $max;

            return [];
        }, $io);
        $cli->run($options);

        self::assertSame($expectedMax, $seen);
    }

    /** @return array<string, array{0: array<string, bool|string>, 1: int}> */
    public static function failedMaxProvider(): array
    {
        return [
            'default' => [[], 50],
            'explicit' => [['max' => '10'], 10],
            'bare-flag' => [['max' => true], 50],
            'garbage-becomes-one' => [['max' => 'abc'], 1],
        ];
    }

    // ------------------------------------------------------------------
    // queue:retry
    // ------------------------------------------------------------------

    public function testRetryMovesFailedJobsToTheMainQueue(): void
    {
        $io = HermeticConsoleIo::create();
        $main = new InMemoryJobQueue();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-rtry-001', 3));

        $cli = new QueueRetry(static fn (): JobQueueInterface => $main, static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run([]);

        self::assertSame(0, $exit);
        self::assertSame(0, $failed->size());
        self::assertSame(1, $main->size());
        $moved = $main->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $moved);
        self::assertSame('job-rtry-001', $moved->jobId);
        self::assertSame(3, $moved->attempt, 'the envelope moves as-is — attempt is a delivery fact, not a reset');
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('job job-rtry-001 retried (attempt 3)', $out);
        self::assertStringContainsString('1 retried, 0 skipped', $out);
    }

    public function testRetrySkipsJobsTheMainQueueRejectsAndTerminates(): void
    {
        $io = HermeticConsoleIo::create();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-rtry-dup'));
        $rejecting = new class implements JobQueueInterface {
            #[\Override]
            public function enqueue(JobEnvelope $job): void
            {
                throw new \RuntimeException('already queued');
            }

            #[\Override]
            public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
            {
                return null;
            }

            #[\Override]
            public function size(): int
            {
                return 0;
            }
        };

        $cli = new QueueRetry(static fn (): JobQueueInterface => $rejecting, static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run([]);

        self::assertSame(0, $exit);
        self::assertSame(1, $failed->size(), 'the rejected entry was re-queued where it came from — no data loss');
        $out = implode("\n", $io->outLog());
        self::assertStringContainsString('job job-rtry-dup skipped — already queued or queue rejected it', $out);
        self::assertStringContainsString('0 retried, 1 skipped', $out);
    }

    public function testRetryBudgetCapsTheMoves(): void
    {
        $io = HermeticConsoleIo::create();
        $main = new InMemoryJobQueue();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-rtry-b01'));
        $failed->enqueue($this->job('job-rtry-b02'));

        $cli = new QueueRetry(static fn (): JobQueueInterface => $main, static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run(['max' => '1']);

        self::assertSame(0, $exit);
        self::assertSame(1, $main->size());
        self::assertSame(1, $failed->size());
        self::assertStringContainsString('1 retried, 0 skipped', implode("\n", $io->outLog()));
    }

    public function testRetryAllMovesEveryEntry(): void
    {
        $io = HermeticConsoleIo::create();
        $main = new InMemoryJobQueue();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-rtry-a01'));
        $failed->enqueue($this->job('job-rtry-a02'));

        $cli = new QueueRetry(static fn (): JobQueueInterface => $main, static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run(['all' => true]);

        self::assertSame(0, $exit);
        self::assertSame(2, $main->size());
        self::assertSame(0, $failed->size());
    }

    public function testRetryFactoryFailureExitsOne(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueRetry(
            static function (): never {
                throw new \RuntimeException('main missing');
            },
            static fn (): JobQueueInterface => new InMemoryJobQueue(),
            $io,
        );

        self::assertSame(1, $cli->run([]));
        self::assertStringContainsString('queue:retry could not build the queues: main missing', implode("\n", $io->errLog()));
    }

    // ------------------------------------------------------------------
    // queue:flush
    // ------------------------------------------------------------------

    public function testFlushDrainsEverythingByDefault(): void
    {
        $io = HermeticConsoleIo::create();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-flsh-001'));
        $failed->enqueue($this->job('job-flsh-002'));
        $failed->enqueue($this->job('job-flsh-003'));

        $cli = new QueueFlush(static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run([]);

        self::assertSame(0, $exit);
        self::assertSame(0, $failed->size());
        self::assertStringContainsString('3 failed job(s) flushed', implode("\n", $io->outLog()));
    }

    public function testFlushEmptyQueueReportsZero(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueFlush(static fn (): JobQueueInterface => new InMemoryJobQueue(), $io);

        self::assertSame(0, $cli->run([]));
        self::assertStringContainsString('0 failed job(s) flushed', implode("\n", $io->outLog()));
    }

    public function testFlushBudgetCapsThePurge(): void
    {
        $io = HermeticConsoleIo::create();
        $failed = new InMemoryJobQueue();
        $failed->enqueue($this->job('job-flsh-101'));
        $failed->enqueue($this->job('job-flsh-102'));
        $failed->enqueue($this->job('job-flsh-103'));

        $cli = new QueueFlush(static fn (): JobQueueInterface => $failed, $io);
        $exit = $cli->run(['max' => '2']);

        self::assertSame(0, $exit);
        self::assertSame(1, $failed->size(), 'the budget left one entry behind');
        self::assertStringContainsString('2 failed job(s) flushed', implode("\n", $io->outLog()));
    }

    public function testFlushFactoryFailureExitsOne(): void
    {
        $io = HermeticConsoleIo::create();
        $cli = new QueueFlush(
            static function (): never {
                throw new \RuntimeException('failed queue missing');
            },
            $io,
        );

        self::assertSame(1, $cli->run([]));
        self::assertStringContainsString('queue:flush could not build the failed-job queue: failed queue missing', implode("\n", $io->errLog()));
    }

    private function job(string $id, int $attempt = 1, int $availableAt = 0, string $type = 't.job.ok'): JobEnvelope
    {
        return new JobEnvelope($id, $type, null, $availableAt, 0, $attempt);
    }

    /** A worker factory over an InMemory queue, mirroring the bin/zef wiring. */
    private function workerFactory(
        InMemoryJobQueue $queue,
        ?InMemoryJobQueue $dlq = null,
        ?RetryPolicy $retryPolicy = null,
    ): \Closure {
        return static function () use ($queue, $dlq, $retryPolicy): InProcessJobWorker {
            $worker = new InProcessJobWorker($queue, $retryPolicy ?? new RetryPolicy(), null, $dlq);
            $worker->register('t.job.ok', static fn (): string => 'done');
            $worker->register('t.job.boom', static function (): never {
                throw new \RuntimeException('boom');
            });

            return $worker;
        };
    }
}
