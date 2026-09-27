<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-03 (issue #157):
 * committed suspension values must never be discarded by a racing
 * cancellation, and a cancel-requested task must never park again.
 *
 * The pre-fix defect: release() transferred a permit to a parked acquirer
 * (deliver + queued resume); a cancellation landing before that resume made
 * awaitSuspension() throw away the ALREADY-DELIVERED payload — the permit
 * was counted as consumed but nobody held it, so every later acquire()
 * deadlocked. The same race silently dropped delivered channel messages.
 *
 * Every race below is forced deterministically: the delivery and the
 * cancellation run inside one fiber step, before the pump processes the
 * queued resume.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Runtime\Async\FiberChannel;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\FiberTask;
use Zef\Framework\Runtime\Async\Semaphore;
use Zef\Framework\Runtime\Async\TaskCancelledException;
use Zef\Framework\Runtime\Async\TaskState;

/**
 * @internal
 */
final class AsyncDeferredCancelRaceTest extends TestCase
{
    private FakeAsyncClock $clock;

    private FakeAsyncSleeper $sleeper;

    private FiberScheduler $scheduler;

    protected function setUp(): void
    {
        $this->clock = new FakeAsyncClock();
        $this->sleeper = new FakeAsyncSleeper($this->clock);
        $this->scheduler = new FiberScheduler($this->clock, $this->sleeper);
    }

    // ------------------------------------------------------------------
    // ZEF-DEEP-03 part 1: the permit leak
    // ------------------------------------------------------------------

    /**
     * The audit PoC, made deterministic: release() hands the permit over,
     * cancel() lands before the queued resume runs. The acquirer must
     * receive the committed permit (deferred-cancel) and hand it back —
     * pre-fix the permit vanished and every later acquire() deadlocked.
     */
    public function testSemaphorePermitSurvivesReleaseVersusCancelRace(): void
    {
        $scheduler = $this->scheduler;
        $acquired = false;
        $racer = null;

        $scheduler->run(function () use ($scheduler, &$acquired, &$racer): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $racer = $scheduler->spawn(function () use ($semaphore, &$acquired): void {
                $semaphore->acquire();
                $acquired = true;
                $semaphore->release();
            }, 'racer');

            // Let the racer run to its park point first.
            $scheduler->suspend();

            // The race, in one step: permit handed over (deliver queued)…
            $semaphore->release();
            // …then cancellation lands before the pump resumes the racer.
            self::assertTrue($racer->cancel(), 'cancel must be accepted');
            self::assertFalse($semaphore->tryAcquire(), 'the permit is in transit to the racer');

            try {
                $scheduler->await($racer);
            } catch (TaskCancelledException) {
                self::fail('the racer completed its hand-back; cancellation must stay deferred');
            }

            self::assertTrue($acquired, 'the committed permit must be honoured, not discarded');
            self::assertTrue($semaphore->tryAcquire(), 'the permit must be back in the pool — pre-fix it leaked forever');
            $semaphore->release();

            return 'ok';
        });

        self::assertSame(TaskState::Succeeded, $racer?->state(), 'deferred-cancel lets the coroutine finish its hand-back');
    }

    /**
     * The counterweight: a coroutine whose cancellation lost the delivery
     * race still surfaces the cancellation at its NEXT suspension — it must
     * not park into new waits (timer, channel, semaphore) with the flag set.
     */
    public function testDeferredCancellationFiresAtTheNextSuspension(): void
    {
        $scheduler = $this->scheduler;
        $slept = false;
        $racer = null;

        $scheduler->run(function () use ($scheduler, &$slept, &$racer): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $racer = $scheduler->spawn(function () use ($scheduler, $semaphore, &$slept): void {
                $semaphore->acquire(); // committed via the raced delivery

                try {
                    $scheduler->sleep(5.0); // next suspension: deferred cancel fires here
                    $slept = true;
                } finally {
                    $semaphore->release();
                }
            }, 'next-suspension');

            $scheduler->suspend();
            $semaphore->release();
            $racer->cancel();

            try {
                $scheduler->await($racer);
                self::fail('the deferred cancellation must surface at the next suspension point');
            } catch (TaskCancelledException $exception) {
                self::assertStringContainsString('was cancelled while suspended', $exception->getMessage());
            }

            self::assertFalse($slept, 'the cancelled coroutine must not run past the next suspension');
            self::assertTrue($semaphore->tryAcquire(), 'the finally hand-back must keep the permit pool balanced');
            $semaphore->release();

            return 'ok';
        });

        self::assertSame(TaskState::Cancelled, $racer?->state());
    }

    // ------------------------------------------------------------------
    // ZEF-DEEP-03 part 2: the channel message loss
    // ------------------------------------------------------------------

    /** Direct handoff to a parked receiver, then cancel before resume: the message must not vanish. */
    public function testDeliveredChannelMessageSurvivesCancelRace(): void
    {
        $scheduler = $this->scheduler;
        $received = null;
        $receiver = null;

        $scheduler->run(function () use ($scheduler, &$received, &$receiver): string {
            $channel = new FiberChannel($scheduler, 1); // empty buffer: a receive() still parks, send() does a direct handoff

            $receiver = $scheduler->spawn(function () use ($channel, &$received): void {
                $received = $channel->receive();
            }, 'receiver');

            // Let the receiver park on the empty channel.
            $scheduler->suspend();

            // Direct handoff — the value leaves our hands and is committed…
            $channel->send('payload');
            // …then cancellation lands before the queued resume runs.
            $receiver->cancel();

            try {
                $scheduler->await($receiver);
            } catch (TaskCancelledException) {
                self::fail('the receiver completed; cancellation must stay deferred');
            }

            self::assertSame('payload', $received, 'the delivered message must be received, not silently dropped');

            return 'ok';
        });

        self::assertSame(TaskState::Succeeded, $receiver?->state());
    }

    // ------------------------------------------------------------------
    // The entry guard (beginSuspension)
    // ------------------------------------------------------------------

    /** A self-cancelled task must not park into a semaphore acquire — its waiter entry would swallow a future permit. */
    public function testSelfCancelledTaskCannotParkIntoAcquire(): void
    {
        $scheduler = $this->scheduler;
        $parked = false;
        $task = null;

        $scheduler->run(function () use ($scheduler, &$parked, &$task): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire(); // fill the pool so the sibling's acquire() actually has to park
            $task = $scheduler->spawn(function () use ($semaphore, &$parked, &$task): void {
                self::assertInstanceOf(FiberTask::class, $task);
                $task->cancel(); // self-cancel while running

                try {
                    $semaphore->acquire(); // must throw at suspension entry
                    $parked = true;
                } catch (TaskCancelledException $exception) {
                    self::assertStringContainsString('was cancelled while suspended', $exception->getMessage());
                } finally {
                    // No permit was taken — nothing to release (and releasing
                    // here would be an over-release LogicException).
                }
            }, 'self-cancel-acquire');

            try {
                $scheduler->await($task);
            } catch (TaskCancelledException) {
                // Expected settle state for a self-cancelled task.
            }

            self::assertFalse($parked, 'a cancel-requested task must not park');
            $semaphore->release(); // main returns its own permit; a phantom waiter would swallow THIS hand-off
            self::assertTrue($semaphore->tryAcquire(), 'no waiter entry may remain to swallow a future permit');
            $semaphore->release();

            return 'ok';
        });

        self::assertNotNull($task);
    }

    /** Cancellation arriving while still parked (fail wins) keeps the old clean semantics — no phantom waiter. */
    public function testParkedAcquirerCancelledBeforeDeliveryFailsCleanly(): void
    {
        $scheduler = $this->scheduler;
        $acquired = false;
        $racer = null;

        $scheduler->run(function () use ($scheduler, &$acquired, &$racer): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $racer = $scheduler->spawn(function () use ($semaphore, &$acquired): void {
                $semaphore->acquire();
                $acquired = true;
            }, 'early-cancel');

            $scheduler->suspend();

            // Cancel BEFORE any hand-off: the armed handle is unsettled, so
            // fail() wins and the acquire throws. The settle hook splices the
            // waiter out, so the later release finds an empty queue.
            $racer->cancel();

            try {
                $scheduler->await($racer);
                self::fail('cancellation of a parked acquirer must interrupt it');
            } catch (TaskCancelledException) {
            }
            self::assertFalse($acquired);

            $semaphore->release(); // no waiter: permit returns to the pool
            self::assertTrue($semaphore->tryAcquire());
            $semaphore->release();

            return 'ok';
        });

        self::assertSame(TaskState::Cancelled, $racer?->state());
    }
}
