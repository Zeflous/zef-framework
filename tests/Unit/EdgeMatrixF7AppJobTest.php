<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7a — Application/Job (ronde 3, chunk app-job).
 *
 * Kurikulum chunk app-job (67 escape baseline): kapasitas default antrian
 * tepat 10.000 (dua arah ±1), urutan leksikografis triple prioritas
 * (availableAt > priority > FIFO-sequence), tie-breaker & spaceship arah,
 * boundary availableAt == now (strictly-greater), coalesce jam vs argumen
 * eksplisit, freeze guards (register/use/processOne/run), flag running via
 * finally, stop-signal semantika (negasi, idle drain-default, pemanggilan
 * berulang), maxJobs CAP (bukan target), pipeline middleware urutan,
 * JobInterface wrapping, DLQ tiga jalur (unknown-type, retry-gagal-enqueue,
 * retry-terjadwal BUKAN dead-letter), kunci idempotensi = sha256 exact
 * "jobType|jobId", budget scheduler (bounds dua arah + overflow tepat 256),
 * re-register menggantikan jadwal (dua keadaan), catch-up cap tepat 8,
 * tick(0) valid + now-1 semantika, jobId "sched-" + 16 hex.
 *
 * Setiap test membunuh mutan spesifik dari build/infection-f7-job-baseline.log.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\FixedIntervalSchedule;
use Zef\Framework\Job\InMemoryJobIdempotencyStore;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobExecutionException;
use Zef\Framework\Job\JobHandlerInterface;
use Zef\Framework\Job\JobIdempotencyStoreInterface;
use Zef\Framework\Job\JobInterface;
use Zef\Framework\Job\JobMiddlewareInterface;
use Zef\Framework\Job\JobQueueInterface;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Job\ScheduleInterface;
use Zef\Framework\Job\Scheduler;

/**
 * @internal
 */
final class EdgeMatrixF7AppJobTest extends TestCase
{
    // --------------------------------------------- InMemoryJobQueue

    public function testDefaultQueueCapacityIsExactlyTenThousand(): void
    {
        $queue = new InMemoryJobQueue();
        for ($i = 0; $i < 10_000; ++$i) {
            $queue->enqueue($this->env('cap-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT)));
        }
        self::assertSame(10_000, $queue->size());
        self::addToAssertionCount(1); // 10.000 enqueue tanpa exception = kapasitas tidak 9.999

        try {
            $queue->enqueue($this->env('cap-100000'));
            self::fail('Expected OverflowException on enqueue beyond the default capacity.');
        } catch (\OverflowException) {
            self::addToAssertionCount(1); // kapasitas tidak 10.001
        }
    }

    public function testOrderAvailableAtDominatesEvenAgainstInsertOrder(): void
    {
        // X (avail 100) diinsert lebih dulu, Y (avail 50) belakangan.
        // Asli: Y keluar duluan (idx-0 memutuskan). Membunuh: Spaceship
        // terbalik (X duluan), ReturnRemoval (idx-2 sequence: X duluan),
        // For_ cond=false & LessThanNegotiation (compare 0: insert order X).
        $queue = new InMemoryJobQueue();
        $x = $this->env('ord-xx01', avail: 100);
        $y = $this->env('ord-yy02', avail: 50);
        $queue->enqueue($x);
        $queue->enqueue($y);
        self::assertSame($y, $queue->dequeue(200));
        self::assertSame($x, $queue->dequeue(200));
        self::assertNull($queue->dequeue(200));
    }

    public function testOrderTieBreaksOnPriorityThenInsertOrder(): void
    {
        // availableAt sama (7): priority 9 harus di atas priority 3 (idx-1
        // memutuskan) — membunuh Identical-negasi & Continue->break yang
        // menghentikan komparasi di idx-0, serta ArrayItemRemoval idx-1.
        $queue = new InMemoryJobQueue();
        $low = $this->env('tie-low1', avail: 7, pri: 3);
        $high = $this->env('tie-hig2', avail: 7, pri: 9);
        $queue->enqueue($low);
        $queue->enqueue($high);
        self::assertSame($high, $queue->dequeue(20));
        self::assertSame($low, $queue->dequeue(20));

        // availableAt & priority sama: FIFO via sequence (idx-2).
        $first = $this->env('fifo-a01', avail: 7);
        $second = $this->env('fifo-b02', avail: 7);
        $queue->enqueue($first);
        $queue->enqueue($second);
        self::assertSame($first, $queue->dequeue(20));
        self::assertSame($second, $queue->dequeue(20));
    }

    public function testDequeueBoundaryAvailableAtEqualsNowIsDue(): void
    {
        $queue = new InMemoryJobQueue();
        $job = $this->env('bnd-ryy1', avail: 5);
        $queue->enqueue($job);
        // strictly-greater: availableAt == now PASTI due (bukan >=).
        self::assertSame($job, $queue->dequeue(5));

        // Argumen eksplisit harus mengalahkan jam dinding (coalesce order).
        $queue2 = new InMemoryJobQueue();
        $past = $this->env('clk-pst1', avail: 1);
        $queue2->enqueue($past);
        self::assertSame($past, $queue2->dequeue(5));
    }

    public function testDequeueFutureJobIsNotExtracted(): void
    {
        $queue = new InMemoryJobQueue();
        $now = (int) (microtime(true) * 1_000_000_000);
        $future = $this->env('ftr-xxx1', avail: $now + 3_600_000_000_000); // +1 jam
        $queue->enqueue($future);
        self::assertNull($queue->dequeue(5));
        self::assertNull($queue->dequeue()); // jam dinding tetap < now + 1 jam
        self::assertSame(1, $queue->size());
    }

    public function testEmptyQueueDequeueReturnsNull(): void
    {
        $queue = new InMemoryJobQueue();
        self::assertNull($queue->dequeue(5));
        self::assertNull($queue->dequeue());
        self::assertSame(0, $queue->size());
    }

    // --------------------------------------------- InProcessJobWorker: guards

    public function testWorkerPollIntervalGuardBounds(): void
    {
        $ok = fn (int $ms): InProcessJobWorker => new InProcessJobWorker(new InMemoryJobQueue(), new RetryPolicy(), null, null, $ms);
        self::addToAssertionCount(1); // 0 valid (floor 1ms aktif)
        $ok(0);
        $ok(60_000); // batas atas inklusif

        try {
            $ok(-1);
            self::fail('Expected InvalidArgumentException for negative poll interval.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $ok(60_001);
            self::fail('Expected InvalidArgumentException for poll interval above 60000.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testWorkerDlqMustDifferFromMainQueue(): void
    {
        $same = new InMemoryJobQueue();

        try {
            new InProcessJobWorker($same, new RetryPolicy(), null, $same);
            self::fail('Expected InvalidArgumentException when DLQ is the main queue.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRegisterRejectsInvalidTypeAndDuplicate(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue());

        try {
            $worker->register('bad type', $this->okHandler());
            self::fail('Expected InvalidArgumentException for invalid job type.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $worker->register('job.ok', $this->okHandler());

        try {
            $worker->register('job.ok', $this->okHandler());
            self::fail('Expected LogicException for duplicate registration.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testProcessOneFreezesWorker(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue());
        self::assertFalse($worker->isFrozen());
        self::assertNull($worker->processOne()); // queue kosong
        self::assertTrue($worker->isFrozen(), 'processOne must freeze the worker');
        $this->assertFrozenGuards($worker);
    }

    public function testRunFreezesWorkerAndClearsRunningFlag(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('run-xxx1'));
        $queue->enqueue($this->env('run-xxx2'));
        self::assertFalse($worker->isFrozen());
        $processed = $worker->run(0, static fn (): bool => false, null, true);
        self::assertSame(2, $processed);
        self::assertTrue($worker->isFrozen());
        self::assertFalse($worker->isRunning(), 'finally must clear the running flag');
        $this->assertFrozenGuards($worker);
    }

    // --------------------------------------------- InProcessJobWorker: execution

    public function testJobInterfaceHandlerIsWrappedAndRunsHandle(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $job = new class implements JobInterface {
            #[\Override]
            public function handle(JobContext $context): mixed
            {
                return 'handled:' . $context->jobId;
            }
        };
        $worker->register('job.cls', $job);
        $queue->enqueue($this->env('cls-yyy1', 'job.cls'));
        $result = $worker->processOne();
        self::assertInstanceOf(JobResult::class, $result);
        self::assertTrue($result->completed, 'JobInterface must be wrapped, not stored as unknown handler');
        self::assertSame('handled:cls-yyy1', $result->result);
    }

    public function testCallableHandlerInterfaceReceivesEnvelopeAndContext(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $seen = null;
        $worker->register('job.hdl', $this->handler(static function (JobEnvelope $j, JobContext $c) use (&$seen): string {
            $seen = [$j->jobType, $c->jobId, $c->attempt];

            return 'done';
        }));
        $queue->enqueue($this->env('hdl-yyy1', 'job.hdl', attempt: 2));
        $result = $worker->processOne();
        self::assertTrue($result->completed); // @phpstan-ignore-line
        self::assertSame(['job.hdl', 'hdl-yyy1', 2], $seen);
    }

    public function testMiddlewarePipelineRunsOuterFirst(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $log = [];
        $worker->use($this->middleware('A', $log));
        $worker->use($this->middleware('B', $log));
        $worker->register('job.mw', $this->handler(static fn (): string => 'h'));
        $queue->enqueue($this->env('mw-yyy12', 'job.mw'));
        $result = $worker->processOne();
        self::assertSame('h', $result->result); // @phpstan-ignore-line
        self::assertSame(['A:enter', 'B:enter', 'B:exit', 'A:exit'], $log, 'pipeline is outer-first (reverse registration order)');
    }

    public function testRunIsRunningFlagVisibleInsideOnResult(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('run-yyy1'));
        $queue->enqueue($this->env('run-yyy2'));
        $runningInside = [];
        $processed = $worker->run(
            0,
            static fn (): bool => false,
            static function (JobResult $r) use ($worker, &$runningInside): void {
                $runningInside[] = $worker->isRunning();
            },
            true,
        );
        self::assertSame(2, $processed);
        self::assertSame([true, true], $runningInside, 'running flag must be true while the loop is alive');
    }

    public function testRunDefaultMaxJobsIsAnUncappedCap(): void
    {
        // run(maxJobs: 0) — CAP 0 berarti tanpa batas, bukan "nol job".
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('cap-yyy1'));
        $queue->enqueue($this->env('cap-yyy2'));
        self::assertSame(2, $worker->run(0, static fn (): bool => false, null, true));
    }

    public function testRunStopsExactlyAtMaxJobsCap(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('cap-zzz1'));
        $queue->enqueue($this->env('cap-zzz2'));
        $queue->enqueue($this->env('cap-zzz3'));
        self::assertSame(2, $worker->run(2, static fn (): bool => false, null, true));
        self::assertSame(1, $queue->size());
    }

    public function testStopSignalIsHonouredWithoutNegation(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('stp-yyy1'));
        $queue->enqueue($this->env('stp-yyy2'));
        $calls = 0;
        $stop = static function () use (&$calls): bool {
            ++$calls;

            return false; // tidak pernah berhenti — loop tetap harus jalan
        };
        self::assertSame(2, $worker->run(0, $stop, null, true));
        self::assertGreaterThanOrEqual(2, $calls);
    }

    public function testIdleDaemonWithStopSignalKeepsPollingUntilStop(): void
    {
        // drain default = false: queue kosong TIDAK langsung break; loop
        // idle terus memanggil stop-signal (floor sleep 1..10ms) sampai
        // sinyal benar. Membunuh mutasi default $drain -> true (break pada
        // panggilan stop pertama).
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $calls = 0;
        $stop = static function () use (&$calls): bool {
            ++$calls;

            return $calls >= 3;
        };
        self::assertSame(0, $worker->run(0, $stop));
        self::assertSame(3, $calls, 'idle daemon polls: 3 stop-signal invocations, no early drain break');
    }

    public function testNegativeMaxJobsIsRejected(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue());

        try {
            $worker->run(-1);
            self::fail('Expected InvalidArgumentException for negative maxJobs.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // --------------------------------------------- InProcessJobWorker: failures & DLQ

    public function testUnknownJobTypeIsDeadLetteredNotLost(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(), null, $dlq);
        $queue->enqueue($this->env('noh-yyy1', 'job.nohandler', attempt: 4));
        $result = $worker->processOne();
        self::assertInstanceOf(JobResult::class, $result);
        self::assertFalse($result->completed);
        self::assertInstanceOf(JobExecutionException::class, $result->result);
        self::assertSame("No handler registered for 'job.nohandler'.", $result->result->getMessage());
        self::assertSame(4, $result->attempt);
        self::assertTrue($result->deadLettered, 'job must be persisted to the DLQ');
        self::assertFalse($result->willRetry);
        self::assertSame(1, $dlq->size());
        self::assertSame('noh-yyy1', $dlq->dequeue(3)->jobId); // @phpstan-ignore-line
    }

    public function testUnknownJobTypeWithoutDlqStillYieldsFailedResult(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $queue->enqueue($this->env('noh-zzz2', 'job.absent'));
        $result = $worker->processOne();
        self::assertFalse($result->completed); // @phpstan-ignore-line
        self::assertFalse($result->deadLettered); // @phpstan-ignore-line
        self::assertInstanceOf(JobExecutionException::class, $result->result); // @phpstan-ignore-line
    }

    public function testScheduledRetryResultIsNotDeadLettered(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(maxAttempts: 3), null, $dlq);
        $worker->register('job.boom', $this->handler(static function (): never {
            throw new \RuntimeException('boom');
        }));
        $queue->enqueue($this->env('rtr-yyy1', 'job.boom', attempt: 1));
        $result = $worker->processOne();
        self::assertFalse($result->completed); // @phpstan-ignore-line
        self::assertTrue($result->willRetry, 'attempt 1 < maxAttempts 3 must reschedule'); // @phpstan-ignore-line
        self::assertFalse($result->deadLettered, 'a scheduled retry must NOT claim DLQ persistence'); // @phpstan-ignore-line
        self::assertSame(0, $dlq->size());
        self::assertSame(1, $queue->size(), 'retry envelope was re-enqueued');
        $retry = $queue->dequeue(PHP_INT_MAX); // availableAt retry 100ms di depan jam dinding
        self::assertInstanceOf(JobEnvelope::class, $retry);
        self::assertSame(2, $retry->attempt);
        self::assertSame('rtr-yyy1', $retry->jobId);
    }

    public function testTerminalFailureAfterMaxAttemptsIsDeadLettered(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(maxAttempts: 2), null, $dlq);
        $worker->register('job.boom', $this->handler(static function (): never {
            throw new \RuntimeException('boom');
        }));
        $queue->enqueue($this->env('rtr-zzz2', 'job.boom', attempt: 2));
        $result = $worker->processOne();
        self::assertFalse($result->completed); // @phpstan-ignore-line
        self::assertFalse($result->willRetry, 'attempt 2 >= maxAttempts 2 is terminal'); // @phpstan-ignore-line
        self::assertTrue($result->deadLettered); // @phpstan-ignore-line
        self::assertSame(1, $dlq->size());
        self::assertSame(0, $queue->size());
    }

    public function testRetryEnqueueFailureFallsBackToDlq(): void
    {
        // Queue utama membuang job secara destruktif lalu menolak setiap
        // enqueue (simulasi penuh): retry gagal — job TIDAK boleh hilang.
        $main = new class implements JobQueueInterface {
            public ?JobEnvelope $held = null;

            #[\Override]
            public function enqueue(JobEnvelope $job): void
            {
                throw new \OverflowException('Job queue capacity exceeded.');
            }

            #[\Override]
            public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
            {
                $job = $this->held;
                $this->held = null;

                return $job;
            }

            #[\Override]
            public function size(): int
            {
                return $this->held instanceof JobEnvelope ? 1 : 0;
            }
        };
        $dlq = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($main, new RetryPolicy(maxAttempts: 3), null, $dlq);
        $worker->register('job.boom', $this->handler(static function (): never {
            throw new \RuntimeException('boom');
        }));
        $main->held = $this->env('rqf-yyy1', 'job.boom');
        $result = $worker->processOne();
        self::assertFalse($result->completed); // @phpstan-ignore-line
        self::assertTrue($result->deadLettered, 'failed retry enqueue must dead-letter the job'); // @phpstan-ignore-line
        self::assertFalse($result->willRetry); // @phpstan-ignore-line
        self::assertSame(1, $dlq->size());
    }

    public function testIdempotencyKeyIsExactSha256OfJobTypeAndJobId(): void
    {
        $store = new class implements JobIdempotencyStoreInterface {
            /** @var list<string> */
            public array $keys = [];

            #[\Override]
            public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
            {
                $this->keys[] = $key;

                return $producer();
            }
        };
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(), $store);
        $worker->register('job.a', $this->okHandler());
        $queue->enqueue($this->env('aaaa0001', 'job.a'));
        $result = $worker->processOne();
        self::assertTrue($result->completed); // @phpstan-ignore-line
        self::assertSame([hash('sha256', 'job.a|aaaa0001')], $store->keys, 'key must be sha256("jobType|jobId") exactly');
    }

    // --------------------------------------------- Scheduler

    public function testSchedulerBudgetBounds(): void
    {
        $queue = new InMemoryJobQueue();
        new Scheduler($queue, 1, 1); // batas bawah inklusif
        self::addToAssertionCount(1);

        try {
            new Scheduler($queue, 0, 8);
            self::fail('Expected InvalidArgumentException for maxRegistrations=0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new Scheduler($queue, 8, 0);
            self::fail('Expected InvalidArgumentException for maxCatchUpPerTick=0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSchedulerDefaultRegistrationBudgetIsExactly256(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new FixedIntervalSchedule(60);
        for ($i = 0; $i < 256; ++$i) {
            $scheduler->register('job.' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), null, $sched);
        }
        self::addToAssertionCount(1); // ke-256 masih diterima (bukan 255)

        try {
            $scheduler->register('job.256', null, $sched);
            self::fail('Expected OverflowException on the 257th registration.');
        } catch (\OverflowException) {
            self::addToAssertionCount(1); // bukan 257
        }
    }

    public function testReRegisterReplacesScheduleKeepsCursor(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue, 2, 1);
        $a = new FixedIntervalSchedule(60);
        $b = new FixedIntervalSchedule(120);
        $scheduler->register('job.a', null, $a);
        self::assertSame($a, $scheduler->scheduleOf('job.a'));
        // Belum penuh (1/2): re-register existing tetap sah.
        $scheduler->register('job.a', 'payload-2', $b);
        self::assertSame($b, $scheduler->scheduleOf('job.a'));
    }

    public function testReRegisterExistingIsAllowedEvenWhenFull(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue, 2, 1);
        $a = new FixedIntervalSchedule(60);
        $b = new FixedIntervalSchedule(120);
        $scheduler->register('job.a', null, $a);
        $scheduler->register('job.b', null, $a);
        // Penuh (2/2): replace existing TETAP sah; hanya NEW yang ditolak.
        $scheduler->register('job.a', null, $b);
        self::assertSame($b, $scheduler->scheduleOf('job.a'));

        try {
            $scheduler->register('job.c', null, $a);
            self::fail('Expected OverflowException for a new registration beyond budget.');
        } catch (\OverflowException) {
            self::addToAssertionCount(1);
        }
    }

    public function testUnregisterAndJobTypes(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);

        try {
            $scheduler->unregister('bad type');
            self::fail('Expected InvalidArgumentException for invalid job type.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertFalse($scheduler->unregister('job.absent'));
        $sched = new FixedIntervalSchedule(60);
        $scheduler->register('job.a', null, $sched);
        $scheduler->register('job.b', null, $sched);
        self::assertSame(['job.a', 'job.b'], $scheduler->jobTypes(), 'registration order, list of strings');
        self::assertTrue($scheduler->unregister('job.a'));
        self::assertSame(['job.b'], $scheduler->jobTypes());
        self::assertNull($scheduler->nextRunOf('job.b'));
        self::assertNull($scheduler->scheduleOf('job.absent'));
    }

    public function testTickZeroIsValidAndFirstTickSeedIsClampedToZero(): void
    {
        // Regresi N-18 (issue #176): tick(0) forwarded -1 to
        // nextRunAfter(), which real schedules (CronExpression) reject —
        // the seed is clamped to 0, so the epoch tick is a well-defined
        // pass. Positive ticks keep the exact now-1 seed.
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new F7FakeSchedule(returnNow: 0);
        $scheduler->register('job.t', null, $sched);
        self::assertSame(8, $scheduler->tick(0), 'tick(0) valid; stationary schedule saturates catch-up cap');
        self::assertSame(0, $sched->firstArg, 'first lookup seed is clamped to 0, never -1');
        self::assertSame(0, $sched->lastArg);
        self::assertSame(0, $scheduler->nextRunOf('job.t'));
    }

    public function testTickBeforeDueEnqueuesNothing(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new F7FakeSchedule(returnNow: 100);
        $scheduler->register('job.t', null, $sched);
        self::assertSame(0, $scheduler->tick(5));
        self::assertSame(4, $sched->firstArg, 'first lookup uses now - 1');
        self::assertSame(100, $scheduler->nextRunOf('job.t'), 'cursor advances past the horizon');
    }

    public function testCatchUpCapIsExactlyEightPerRegistration(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new F7FakeSchedule(returnNow: 5);
        $scheduler->register('job.t', null, $sched);
        self::assertSame(8, $scheduler->tick(5), 'catch-up cap default = 8 (not 7, not 9)');
        self::assertSame(8, $queue->size());
        self::assertSame(5, $scheduler->nextRunOf('job.t'));
    }

    public function testCatchUpCapRespectedExplicitBudgetOne(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue, 256, 1);
        $sched = new F7FakeSchedule(returnNow: 5);
        $scheduler->register('job.t', null, $sched);
        self::assertSame(1, $scheduler->tick(5), 'explicit maxCatchUpPerTick=1 wins over stationary schedule');
    }

    public function testSchedulerRegisterRejectsInvalidTypeAndCorrelation(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new F7FakeSchedule(returnNow: 5);

        try {
            $scheduler->register('bad type', null, $sched);
            self::fail('Expected InvalidArgumentException for invalid scheduled job type.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $scheduler->register('job.t', null, $sched, 'bad corr!');
            self::fail('Expected InvalidArgumentException for invalid correlation ID.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $scheduler->jobTypes(), 'nothing registered by the rejected calls');
    }

    public function testRetryEnqueueFailureWithBrokenDlqReportsNotPersisted(): void
    {
        // Queue utama menolak retry enqueue DAN DLQ juga rusak: flag
        // deadLettered wajib tetap false — observability tidak boleh berbohong.
        $main = new class implements JobQueueInterface {
            public ?JobEnvelope $held = null;

            #[\Override]
            public function enqueue(JobEnvelope $job): void
            {
                throw new \OverflowException('Job queue capacity exceeded.');
            }

            #[\Override]
            public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
            {
                $job = $this->held;
                $this->held = null;

                return $job;
            }

            #[\Override]
            public function size(): int
            {
                return $this->held instanceof JobEnvelope ? 1 : 0;
            }
        };
        $brokenDlq = new class implements JobQueueInterface {
            #[\Override]
            public function enqueue(JobEnvelope $job): void
            {
                throw new \RuntimeException('DLQ is down');
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
        $worker = new InProcessJobWorker($main, new RetryPolicy(maxAttempts: 3), null, $brokenDlq);
        $worker->register('job.boom', $this->handler(static function (): never {
            throw new \RuntimeException('boom');
        }));
        $main->held = $this->env('rqd-yyy1', 'job.boom');
        $result = $worker->processOne();
        self::assertFalse($result->completed); // @phpstan-ignore-line
        self::assertFalse($result->deadLettered, 'a failed DLQ persist must NOT be reported as persisted'); // @phpstan-ignore-line
        self::assertFalse($result->willRetry); // @phpstan-ignore-line
    }

    public function testRunFinallyClearsRunningFlagEvenWhenObserverThrows(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue);
        $worker->register('job.ok', $this->okHandler());
        $queue->enqueue($this->env('fin-yyy1'));

        try {
            $worker->run(0, static fn (): bool => false, static function (): never {
                throw new \LogicException('observer down');
            }, true);
            self::fail('observer exception must bubble out of run()');
        } catch (\LogicException) {
            self::assertFalse($worker->isRunning(), 'finally must clear the running flag even on exception');
        }
    }

    public function testIdleSleepFloorPreventsBusySpin(): void
    {
        // pollIntervalMs=0: floor max(1, 0) = 1ms wajib ada. Tanpa sleep
        // (MethodCallRemoval) atau floor 0 (DecrementInteger) loop idle
        // menjadi busy-spin: puluhan ribu iterasi dalam 100ms vs maksimum
        // ~100 iterasi dengan sleep >= 1ms.
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(), null, null, 0);
        $iterations = 0;
        $start = hrtime(true);
        $stop = static function () use (&$iterations, $start): bool {
            ++$iterations;

            return (hrtime(true) - $start) >= 100_000_000; // 100ms
        };
        self::assertSame(0, $worker->run(0, $stop));
        self::assertLessThan(2_000, $iterations, 'idle loop must sleep, not busy-spin');
    }

    public function testInMemoryJobIdempotencyStoreCachesPerKeyWithBounds(): void
    {
        $calls = 0;
        $store = new InMemoryJobIdempotencyStore();
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'value-' . $calls;
        };
        self::assertSame('value-1', $store->remember('key-0001', $producer));
        self::assertSame('value-1', $store->remember('key-0001', $producer), 'same key must reuse the cached result');
        self::assertSame(1, $calls, 'producer must run once per key');
        self::assertSame('value-2', $store->remember('key-0002', $producer));

        try {
            $store->remember('', $producer);
            self::fail('Expected InvalidArgumentException for empty idempotency key.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid job idempotency key.', $e->getMessage(), 'store-level guard message, not the trait fallback');
        }

        try {
            $store->remember(str_repeat('k', 192), $producer);
            self::fail('Expected InvalidArgumentException for oversized idempotency key.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid job idempotency key.', $e->getMessage());
        }
        self::addToAssertionCount(1); // 191-char key is accepted
        $store->remember(str_repeat('k', 191), $producer);

        try {
            $store->remember('key-0003', $producer, 0);
            self::fail('Expected InvalidArgumentException for TTL 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testInMemoryJobIdempotencyStoreEvictsOldestAtCapacity(): void
    {
        $calls = 0;
        $store = new InMemoryJobIdempotencyStore(maxEntries: 1);
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'v' . $calls;
        };
        self::assertSame('v1', $store->remember('key-a1', $producer));
        self::assertSame('v2', $store->remember('key-b2', $producer), 'capacity 1 evicts the oldest entry');
        self::assertSame('v3', $store->remember('key-a1', $producer), 'evicted key runs the producer again');
        self::assertSame(3, $calls);
    }

    public function testInMemoryJobIdempotencyStoreRejectsNonPositiveCapacity(): void
    {
        try {
            new InMemoryJobIdempotencyStore(maxEntries: 0);
            self::fail('Expected InvalidArgumentException for maxEntries=0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testInMemoryJobIdempotencyStoreDefaultCapacityEvictsOldestExactly(): void
    {
        $calls = 0;
        $store = new InMemoryJobIdempotencyStore(); // maxEntries = 10.000
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'v' . $calls;
        };
        self::assertSame('v1', $store->remember('key-0001', $producer));
        $bulkProducer = static fn (): string => 'bulk';
        for ($i = 1; $i <= 9_999; ++$i) {
            $store->remember('bulk-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $bulkProducer);
        }
        // 10.000 entri: evictor belum menyentuh key-0001 (bukan 9.999).
        self::assertSame('v1', $store->remember('key-0001', $producer), 'at exactly 10.000 entries the oldest is still cached');
        self::assertSame(1, $calls);
        // Insert ke-10.001 meng-evict key-0001 (bukan pada 10.002).
        $store->remember('bulk-010000', $bulkProducer);
        self::assertSame('v2', $store->remember('key-0001', $producer), 'the 10.001st insert must evict the oldest entry');
        self::assertSame(2, $calls);
    }

    public function testScheduledJobIdHasSchedPrefixAndSixteenHex(): void
    {
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $sched = new F7FakeSchedule(returnNow: 5);
        $scheduler->register('job.t', 'payload', $sched, 'corr-0001');
        self::assertSame(8, $scheduler->tick(5));
        $job = $queue->dequeue(5);
        self::assertInstanceOf(JobEnvelope::class, $job);
        self::assertMatchesRegularExpression('/^sched-[0-9a-f]{16}$/', $job->jobId);
        self::assertSame('job.t', $job->jobType);
        self::assertSame('payload', $job->payload);
        self::assertSame(5, $job->availableAtUnixNano);
        self::assertSame('corr-0001', $job->correlationId);
    }

    // --------------------------------------------- helpers

    private function env(string $id, string $type = 'job.a', int $avail = 1, int $pri = 0, int $attempt = 1): JobEnvelope
    {
        return new JobEnvelope(jobId: $id, jobType: $type, payload: null, availableAtUnixNano: $avail, priority: $pri, attempt: $attempt);
    }

    private function handler(\Closure $fn): JobHandlerInterface
    {
        return new readonly class($fn) implements JobHandlerInterface {
            public function __construct(private \Closure $fn) {}

            #[\Override]
            public function __invoke(JobEnvelope $job, JobContext $context): mixed
            {
                return ($this->fn)($job, $context);
            }
        };
    }

    private function okHandler(): JobHandlerInterface
    {
        return $this->handler(static fn (JobEnvelope $j, JobContext $c): string => 'ok:' . $j->jobId);
    }

    /**
     * @param list<string> $log
     */
    private function middleware(string $name, array &$log): JobMiddlewareInterface
    {
        return new class($name, $log) implements JobMiddlewareInterface { // @phpstan-ignore-line
            /** @var list<string> */
            private array $log; // @phpstan-ignore-line

            public function __construct(private readonly string $name, array &$log) // @phpstan-ignore-line
            {
                $this->log = &$log; // @phpstan-ignore-line
            }

            #[\Override]
            public function process(JobEnvelope $job, JobContext $context, \Closure $next): mixed
            {
                $this->log[] = $this->name . ':enter';
                $result = $next($job, $context);
                $this->log[] = $this->name . ':exit';

                return $result;
            }
        };
    }

    private function assertFrozenGuards(InProcessJobWorker $worker): void
    {
        $log = [];
        foreach (['register' => null, 'use' => null] as $op => $_) {
            try {
                if ($op === 'register') {
                    $worker->register('job.fzn', $this->okHandler());
                } else {
                    $worker->use($this->middleware('fzn', $log));
                }
                self::fail("Expected LogicException: {$op}() on frozen worker.");
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

/**
 * Stub ScheduleInterface: mengembalikan nilai tetap (stationary) sehingga
 * catch-up loop saturasi cap; merekam argumen terakhir untuk menguji
 * semantika nextRunAfter(now - 1).
 */
final class F7FakeSchedule implements ScheduleInterface
{
    public ?int $firstArg = null;

    public ?int $lastArg = null;

    public function __construct(public readonly int $returnNow) {}

    #[\Override]
    public function nextRunAfter(int $nowUnixNano): int
    {
        $this->firstArg ??= $nowUnixNano;
        $this->lastArg = $nowUnixNano;

        return $this->returnNow;
    }

    #[\Override]
    public function describe(): string
    {
        return "fake={$this->returnNow}";
    }
}
