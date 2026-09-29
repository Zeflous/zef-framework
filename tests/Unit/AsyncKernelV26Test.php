<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.26.0 Async Runtime: fiber scheduler kernel.
 *
 * Deterministic tests over a fake monotonic clock + recording sleeper:
 * scheduling order, await/awaitAll semantics, cooperative cancellation,
 * timers, timeouts, deadlock detection and unobserved-failure surfacing,
 * so a mutant in the pump (order flips, guard removal, ceil/round changes)
 * cannot survive.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Runtime\Async\AsyncException;
use Zef\Framework\Runtime\Async\AsyncTimeoutException;
use Zef\Framework\Runtime\Async\DeadlockException;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\FiberTask;
use Zef\Framework\Runtime\Async\TaskCancelledException;
use Zef\Framework\Runtime\Async\TaskInterface;
use Zef\Framework\Runtime\Async\TaskState;
use Zef\Framework\Runtime\Async\UnobservedTaskException;
use Zef\Framework\Runtime\Async\WaitGroup;

/**
 * @internal
 */
final class AsyncKernelV26Test extends TestCase
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
    // Exceptions & state enum
    // ------------------------------------------------------------------

    public function testAsyncExceptionHierarchy(): void
    {
        self::assertInstanceOf(AsyncException::class, new AsyncTimeoutException('x'));
        self::assertInstanceOf(AsyncException::class, new TaskCancelledException('x'));
        self::assertInstanceOf(AsyncException::class, new DeadlockException('x'));
        self::assertInstanceOf(AsyncException::class, new UnobservedTaskException([]));
        self::assertInstanceOf(\RuntimeException::class, new AsyncException('x'));
    }

    public function testUnobservedTaskExceptionCarriesThrowablesAndMessage(): void
    {
        $first = new \RuntimeException('boom');
        $second = new \LogicException('zap');
        $exception = new UnobservedTaskException([$first, $second]);

        self::assertSame([$first, $second], $exception->throwables());
        self::assertSame('2 unobserved async task failure(s); first: boom', $exception->getMessage());
    }

    public function testUnobservedTaskExceptionHandlesEmptyList(): void
    {
        $exception = new UnobservedTaskException([]);

        self::assertSame([], $exception->throwables());
        self::assertSame('0 unobserved async task failure(s); first: unknown', $exception->getMessage());
    }

    // ------------------------------------------------------------------
    // Spawn / scheduling order
    // ------------------------------------------------------------------

    public function testSpawnDoesNotExecuteUntilTheSchedulerPumps(): void
    {
        $task = $this->scheduler->spawn(static fn (): null => null);

        self::assertSame(TaskState::Pending, $task->state());
        self::assertFalse($task->isDone());

        $this->scheduler->run(static fn (): string => 'ok');

        self::assertSame(TaskState::Succeeded, $task->state());
        self::assertTrue($task->isDone());
    }

    public function testSpawnOrderIsRoundRobinAcrossSuspensions(): void
    {
        $ran = [];
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler, &$ran): int {
            $a = $scheduler->spawn(function () use (&$ran, $scheduler): int {
                $ran[] = 'a1';
                $scheduler->suspend();
                $ran[] = 'a2';

                return 7;
            }, 'alpha');
            $b = $scheduler->spawn(function () use (&$ran, $scheduler, $a): int {
                $ran[] = 'b1';
                $scheduler->suspend();
                $ran[] = 'b2';
                $scheduler->await($a);

                return 8;
            }, 'beta');

            return $a->id() + $b->id();
        });

        self::assertSame(['a1', 'b1', 'a2', 'b2'], $ran);
    }

    public function testTaskIdsAreMonotonicAndNamesAutoAssignOrCustomise(): void
    {
        $first = $this->scheduler->spawn(static fn (): null => null);
        $second = $this->scheduler->spawn(static fn (): null => null, 'worker');
        $timer = $this->scheduler->delay(1.0, static fn (): null => null);
        $timerTwo = $this->scheduler->delay(2.0, static fn (): null => null);

        self::assertSame(1, $first->id());
        self::assertSame('task-1', $first->name());
        self::assertSame(2, $second->id());
        self::assertSame('worker', $second->name());
        self::assertSame(3, $timer->id());
        self::assertSame('timer-3', $timer->name());
        self::assertSame(4, $timerTwo->id());
        self::assertSame('timer-4', $timerTwo->name());
    }

    public function testRunningStateIsObservableFromAPeer(): void
    {
        $observed = null;
        $scheduler = $this->scheduler;
        $gate = null;

        $scheduler->run(function () use ($scheduler, &$observed, &$gate): string {
            $first = $scheduler->spawn(function () use ($scheduler): void {
                $scheduler->suspend();
            }, 'first');
            $second = $scheduler->spawn(function () use (&$observed, $first): string {
                $observed = $first->state();

                return 'seen';
            }, 'second');
            $gate = $scheduler->await($second);
            $scheduler->await($first);

            return 'done';
        });

        self::assertSame(TaskState::Running, $observed);
        self::assertSame('seen', $gate);
    }

    // ------------------------------------------------------------------
    // run() semantics
    // ------------------------------------------------------------------

    public function testRunReturnsZeroOnSuccessAndRethrowsMainFailure(): void
    {
        self::assertSame(0, $this->scheduler->run(static fn (): string => 'ok'));

        try {
            $this->scheduler->run(static fn (): never => throw new \RuntimeException('main exploded'));
            self::fail('main failure must be rethrown');
        } catch (\RuntimeException $exception) {
            self::assertSame('main exploded', $exception->getMessage());
        }
    }

    public function testRunRejectsReentrancyThroughTheGuard(): void
    {
        $scheduler = $this->scheduler;

        try {
            $scheduler->run(function () use ($scheduler): never {
                // The fiber body runs inside the scheduler, so a nested run
                // hits the reentrancy guard rather than a fresh boot.
                $scheduler->run(static fn (): null => null);

                throw new \LogicException('unreachable');
            });
            self::fail('re-entrant run must fail');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('already running', $exception->getMessage());
        }
    }

    public function testSchedulerIsReusableAfterARun(): void
    {
        self::assertSame(0, $this->scheduler->run(static fn (): int => 1));
        self::assertSame(0, $this->scheduler->run(static fn (): int => 2));

        $task = $this->scheduler->spawn(static fn (): null => null);
        $this->scheduler->run(static fn (): null => null);

        self::assertSame(TaskState::Succeeded, $task->state());
    }

    // ------------------------------------------------------------------
    // await / awaitAll
    // ------------------------------------------------------------------

    public function testAwaitReturnsResultInsideCoroutine(): void
    {
        $scheduler = $this->scheduler;
        $value = null;

        $scheduler->run(function () use ($scheduler, &$value): string {
            $task = $scheduler->spawn(static fn (): int => 40 + 2, 'answer');
            $value = $scheduler->await($task);

            return 'ok';
        });

        self::assertSame(42, $value);
    }

    public function testAwaitRethrowsTheTaskFailure(): void
    {
        $scheduler = $this->scheduler;
        $caught = null;

        $scheduler->run(function () use ($scheduler, &$caught): void {
            $task = $scheduler->spawn(static fn (): never => throw new \RuntimeException('kaput'), 'failing');

            try {
                $scheduler->await($task);
            } catch (\RuntimeException $exception) {
                $caught = $exception->getMessage();
            }
        });

        self::assertSame('kaput', $caught);
    }

    public function testAwaitOnAlreadyDoneTaskReturnsImmediately(): void
    {
        $scheduler = $this->scheduler;
        $value = null;

        $scheduler->run(function () use ($scheduler, &$value): string {
            $task = $scheduler->spawn(static fn (): string => 'fast');
            $scheduler->suspend();
            $value = $scheduler->await($task);

            return 'ok';
        });

        self::assertSame('fast', $value);
    }

    public function testAwaitOutsideSchedulerThrows(): void
    {
        try {
            $this->scheduler->await($this->scheduler->spawn(static fn (): null => null));
            self::fail('await outside a coroutine must fail');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('await() can only be called from inside a coroutine', $exception->getMessage());
        }
    }

    public function testAwaitRejectsForeignTasks(): void
    {
        $other = new FiberScheduler($this->clock, $this->sleeper);
        $foreign = $other->spawn(static fn (): null => null);

        try {
            $this->scheduler->await($foreign);
            self::fail('foreign task must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('await() only accepts tasks spawned by this scheduler', $exception->getMessage());
        }
    }

    public function testAwaitAllReturnsResultsInOrder(): void
    {
        $scheduler = $this->scheduler;
        $values = null;

        $scheduler->run(function () use ($scheduler, &$values): string {
            $tasks = [
                $scheduler->spawn(static fn (): int => 1, 'one'),
                $scheduler->spawn(static fn (): string => 'two', 'two'),
                $scheduler->spawn(static fn (): int => 3, 'three'),
            ];
            $values = $scheduler->awaitAll($tasks);

            return 'ok';
        });

        self::assertSame([1, 'two', 3], $values);
    }

    public function testAwaitAllRethrowsTheFirstFailureAndDoesNotCancelSiblings(): void
    {
        $scheduler = $this->scheduler;
        $siblingState = null;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$siblingState, &$message): void {
            $failing = $scheduler->spawn(static fn (): never => throw new \RuntimeException('all-gone'), 'failing');
            $sibling = $scheduler->spawn(static fn (): string => 'alive', 'sibling');

            try {
                $scheduler->awaitAll([$failing, $sibling]);
            } catch (\RuntimeException $exception) {
                $message = $exception->getMessage();
            }

            $scheduler->await($sibling);
            $siblingState = $sibling->state();
        });

        self::assertSame('all-gone', $message);
        self::assertSame(TaskState::Succeeded, $siblingState);
    }

    public function testAwaitAllRejectsForeignTasks(): void
    {
        $other = new FiberScheduler($this->clock, $this->sleeper);
        $foreign = $other->spawn(static fn (): null => null);

        try {
            $this->scheduler->awaitAll([$foreign]);
            self::fail('foreign tasks must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('awaitAll() only accepts tasks', $exception->getMessage());
        }
    }

    public function testNestedAwaitSpawnsAndWaitsForChildren(): void
    {
        $scheduler = $this->scheduler;
        $total = null;

        $scheduler->run(function () use ($scheduler, &$total): string {
            $parent = $scheduler->spawn(function () use ($scheduler): int {
                $children = [
                    $scheduler->spawn(static fn (): int => 20, 'c1'),
                    $scheduler->spawn(static fn (): int => 22, 'c2'),
                ];

                return array_sum($scheduler->awaitAll($children));
            }, 'parent');

            $total = $scheduler->await($parent);

            return 'ok';
        });

        self::assertSame(42, $total);
    }

    // ------------------------------------------------------------------
    // Timers: delay / sleep
    // ------------------------------------------------------------------

    public function testDelayFiresAfterTheClockAdvancesAndCarriesTheResult(): void
    {
        $scheduler = $this->scheduler;
        $result = null;

        $scheduler->run(function () use ($scheduler, &$result): string {
            $timer = $scheduler->delay(0.05, static fn (): string => 'fired', 'job');
            $result = $scheduler->await($timer);

            return 'ok';
        });

        self::assertSame('fired', $result);
        self::assertSame([50], $this->sleeper->sleepsMs, 'scheduler must idle-wait exactly the ceil()ed 50ms');
    }

    public function testSameDueTimersFireInInsertionOrder(): void
    {
        $scheduler = $this->scheduler;
        $ran = [];

        $scheduler->run(function () use ($scheduler, &$ran): string {
            $first = $scheduler->delay(0.01, function () use (&$ran): string {
                $ran[] = 'first';

                return 'a';
            });
            $second = $scheduler->delay(0.01, function () use (&$ran, $scheduler, $first): string {
                $ran[] = 'second';
                $scheduler->await($first);

                return 'b';
            });
            $scheduler->await($second);

            return 'ok';
        });

        self::assertSame(['first', 'second'], $ran);
    }

    public function testDelayCancellationBeforeFireRemovesTheTimer(): void
    {
        $scheduler = $this->scheduler;
        $timer = null;

        $scheduler->run(function () use ($scheduler, &$timer): string {
            $timer = $scheduler->delay(10.0, static fn (): string => 'never');
            self::assertTrue($timer->cancel());
            $scheduler->suspend();

            return 'ok';
        });

        self::assertSame(TaskState::Cancelled, $timer?->state(), 'cancelled timer must never execute');
        self::assertSame([], $this->sleeper->sleepsMs, 'no timers left -> the pump must not idle-wait');
        self::assertSame(0, $this->clock->nano, 'no virtual time may pass');
    }

    public function testDelayCancellationWhileCallbackRunsIsCooperative(): void
    {
        $scheduler = $this->scheduler;
        $state = null;

        $scheduler->run(function () use ($scheduler, &$state): string {
            $timer = $scheduler->delay(0.001, function () use ($scheduler): int {
                $scheduler->sleep(5.0);

                return 1;
            });
            $scheduler->spawn(function () use ($scheduler, $timer): string {
                $scheduler->sleep(0.002);
                $timer->cancel();

                return 'cancelled it';
            });

            try {
                $scheduler->await($timer);
            } catch (TaskCancelledException $exception) {
                $state = 'cancelled: ' . $exception->getMessage();
            }

            return 'ok';
        });

        self::assertNotNull($state);
        self::assertStringStartsWith('cancelled: timer-', $state);
        self::assertStringContainsString('was cancelled while suspended', $state);
    }

    public function testNegativeDelayIsRejected(): void
    {
        try {
            $this->scheduler->delay(-1.0, static fn (): null => null);
            self::fail('negative delay must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('delay() requires a non-negative duration', $exception->getMessage());
        }
    }

    public function testSleepSuspendsUntilTheTimerFires(): void
    {
        $scheduler = $this->scheduler;
        $woke = false;

        $scheduler->run(function () use ($scheduler, &$woke): string {
            $scheduler->sleep(0.02);
            $woke = true;

            return 'ok';
        });

        self::assertTrue($woke);
        self::assertSame([20], $this->sleeper->sleepsMs);
    }

    public function testSleepZeroBehavesLikeSuspend(): void
    {
        $scheduler = $this->scheduler;
        $ran = [];

        $scheduler->run(function () use ($scheduler, &$ran): string {
            $scheduler->spawn(function () use (&$ran): string {
                $ran[] = 'peer-first';

                return 'peer';
            });
            $scheduler->sleep(0.0);
            $ran[] = 'after-zero';

            return 'ok';
        });

        self::assertSame(['peer-first', 'after-zero'], $ran);
        self::assertSame([], $this->sleeper->sleepsMs, 'sleep(0) must not idle-wait');
    }

    public function testNegativeSleepIsRejectedAndSleepOutsideSchedulerThrows(): void
    {
        try {
            $this->scheduler->sleep(-0.5);
            self::fail('negative sleep must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('sleep() requires a non-negative duration', $exception->getMessage());
        }

        try {
            $this->scheduler->sleep(0.5);
            self::fail('sleep outside a coroutine must fail');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('sleep() can only be called from inside a coroutine', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Cancellation
    // ------------------------------------------------------------------

    public function testCancellationBeforeStartSettlesCancelledWithoutExecuting(): void
    {
        $executed = false;
        $task = $this->scheduler->spawn(function () use (&$executed): string {
            $executed = true;

            return 'nope';
        });

        self::assertTrue($task->cancel());

        $this->scheduler->run(static fn (): null => null);

        self::assertFalse($executed);
        self::assertSame(TaskState::Cancelled, $task->state());
        $reason = $task->throwable();
        self::assertInstanceOf(TaskCancelledException::class, $reason);
        self::assertStringContainsString('was cancelled before it started', $reason->getMessage());
    }

    public function testCancellationIsIdempotentAndDoneTasksCannotBeCancelled(): void
    {
        $task = $this->scheduler->spawn(static fn (): int => 1);

        $this->scheduler->run(static fn (): null => null);

        self::assertTrue($task->isDone());
        self::assertFalse($task->cancel(), 'cancelling a done task must report false');

        $pending = $this->scheduler->spawn(static fn (): int => 2);
        self::assertTrue($pending->cancel());
        self::assertFalse($pending->cancel(), 'double cancellation must report false');
    }

    public function testCancellationWhileSuspendedOnAwaitThrowsAtTheSuspensionPoint(): void
    {
        $scheduler = $this->scheduler;
        $states = [];
        $targets = [];

        $scheduler->run(function () use ($scheduler, &$states, &$targets): string {
            $target = $scheduler->spawn(function () use ($scheduler): string {
                $scheduler->sleep(0.01);

                return 'slow-target';
            }, 'target');
            $victim = $scheduler->spawn(function () use ($scheduler, $target): string {
                $value = $scheduler->await($target);
                self::assertIsString($value);

                return $value;
            }, 'victim');

            $killer = $scheduler->spawn(static fn (): bool => $victim->cancel(), 'killer');

            $scheduler->await($killer);
            $states['victim'] = $victim->state();
            $targets['target'] = $target;

            return 'ok';
        });

        self::assertSame(TaskState::Cancelled, $states['victim'] ?? null);
        $targetTask = $targets['target'] ?? null;
        self::assertInstanceOf(TaskInterface::class, $targetTask);
        self::assertSame(TaskState::Succeeded, $targetTask->state(), 'cancelling the waiter must not cancel the awaited task');
    }

    public function testAwaitOnACancelledTaskThrowsTaskCancelledException(): void
    {
        $task = $this->scheduler->spawn(static fn (): int => 1);
        $task->cancel();

        try {
            $task->result();
            self::fail('result() on a cancelled task must throw');
        } catch (TaskCancelledException $exception) {
            self::assertStringContainsString('was cancelled before it started', $exception->getMessage());
        }
    }

    public function testSelfCancellationIsHonouredAtTheNextSuspensionPoint(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): string {
            $task = null;
            $task = $scheduler->spawn(function () use ($scheduler, &$task): string {
                self::assertInstanceOf(FiberTask::class, $task);
                self::assertTrue($task->cancel(), 'self-cancel must be accepted');
                $scheduler->suspend();
                $scheduler->suspend();

                return 'never';
            }, 'self-cancel');

            try {
                $scheduler->await($task);
            } catch (TaskCancelledException $exception) {
                $message = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame('self-cancel was cancelled while suspended', $message);
        self::assertSame(0, $this->scheduler->run(static fn (): null => null));
    }

    public function testCancelRequestLostTheRaceWhenTheCoroutineFinishesWithoutSuspending(): void
    {
        $scheduler = $this->scheduler;
        $result = null;

        $scheduler->run(function () use ($scheduler, &$result): string {
            $task = null;
            $task = $scheduler->spawn(function () use (&$task): int {
                self::assertInstanceOf(FiberTask::class, $task);
                self::assertTrue($task->cancel(), 'self-cancel must be accepted');

                return 5;
            }, 'racer');

            $result = $scheduler->await($task);

            return 'ok';
        });

        self::assertSame(5, $result, 'a coroutine that never suspends wins the race');
    }

    public function testResultOnNonTerminalTaskThrowsLogicException(): void
    {
        $task = $this->scheduler->spawn(static fn (): int => 1);

        try {
            $task->result();
            self::fail('result() before completion must throw');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('has not completed yet (state: Pending)', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Timeout
    // ------------------------------------------------------------------

    public function testTimeoutReturnsTheFastResult(): void
    {
        $scheduler = $this->scheduler;
        $value = null;

        $scheduler->run(function () use ($scheduler, &$value): string {
            $value = $scheduler->timeout(1.0, static fn (): int => 99);

            return 'ok';
        });

        self::assertSame(99, $value);
        self::assertSame([], $this->sleeper->sleepsMs, 'the disarmed guard timer must not idle-wait');
    }

    public function testTimeoutThrowsAsyncTimeoutExceptionForSlowTasks(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            try {
                $scheduler->timeout(0.01, function () use ($scheduler): string {
                    $scheduler->sleep(5.0);

                    return 'too late';
                });

                throw new \RuntimeException('timeout must fire');
            } catch (AsyncTimeoutException $exception) {
                self::assertStringContainsString('exceeded its 0.01 second timeout', $exception->getMessage());
                self::assertInstanceOf(TaskCancelledException::class, $exception->getPrevious());
            }

            return 'ok';
        });
    }

    public function testTimeoutPassesThroughNonCancellationFailures(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): void {
            try {
                $scheduler->timeout(1.0, static fn (): never => throw new \RuntimeException('inner-failure'));
            } catch (AsyncTimeoutException) {
                self::fail('non-cancellation failures must not become timeouts');
            } catch (\RuntimeException $exception) {
                $message = $exception->getMessage();
            }
        });

        self::assertSame('inner-failure', $message);
    }

    public function testNegativeTimeoutIsRejected(): void
    {
        try {
            $this->scheduler->timeout(-2.0, static fn (): null => null);
            self::fail('negative timeout must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('timeout() requires a non-negative duration', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Failure surfacing
    // ------------------------------------------------------------------

    public function testUnobservedFailuresSurfaceAfterTheRunSettles(): void
    {
        $scheduler = $this->scheduler;

        try {
            $scheduler->run(function () use ($scheduler): string {
                $scheduler->spawn(static fn (): never => throw new \RuntimeException('child exploded'), 'child');
                $scheduler->spawn(static fn (): string => 'fine', 'fine');

                return 'main-ok';
            });
            self::fail('unobserved failure must surface');
        } catch (UnobservedTaskException $exception) {
            self::assertStringContainsString('1 unobserved async task failure(s); first: child exploded', $exception->getMessage());
        }
    }

    public function testAwaitedFailuresDoNotSurfaceTwice(): void
    {
        $scheduler = $this->scheduler;

        $code = $scheduler->run(function () use ($scheduler): string {
            $failing = $scheduler->spawn(static fn (): never => throw new \RuntimeException('handled'), 'handled');

            try {
                $scheduler->await($failing);
            } catch (\RuntimeException) {
                // observed and handled
            }

            return 'ok';
        });

        self::assertSame(0, $code);
    }

    public function testFailedMainSurfacesBeforeUnobservedChildren(): void
    {
        $scheduler = $this->scheduler;

        try {
            $scheduler->run(function () use ($scheduler): never {
                $scheduler->spawn(static fn (): never => throw new \RuntimeException('child-exploded'), 'child');

                throw new \RuntimeException('main-exploded');
            });
            self::fail('main failure must surface');
        } catch (\RuntimeException $exception) {
            self::assertSame('main-exploded', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Deadlock detection
    // ------------------------------------------------------------------

    public function testDeadlockIsDetectedWhenNothingCanWakeTheSuspendedTasks(): void
    {
        $scheduler = $this->scheduler;

        try {
            $scheduler->run(function () use ($scheduler): string {
                $waitGroup = new WaitGroup($scheduler);
                $waitGroup->add(1);
                $parked = $scheduler->spawn(static fn () => $waitGroup->await(), 'parked-one');

                $scheduler->await($parked);

                return 'never';
            });
            self::fail('deadlock must be detected');
        } catch (DeadlockException $exception) {
            self::assertStringContainsString('deadlock: 2 suspended task(s)', $exception->getMessage());
            self::assertStringContainsString('parked-one', $exception->getMessage());
            self::assertStringContainsString('main', $exception->getMessage());
        }
    }

    public function testDeadlockIsNotReportedWhileTimersRemainPending(): void
    {
        $scheduler = $this->scheduler;
        $tick = null;

        $code = $scheduler->run(function () use ($scheduler, &$tick): string {
            $tick = $scheduler->delay(0.01, static fn (): string => 'tick');
            $scheduler->sleep(0.02);

            return 'ok';
        });

        self::assertSame(0, $code);
        self::assertSame(TaskState::Succeeded, $tick?->state());
        self::assertSame([10, 10], $this->sleeper->sleepsMs);
    }

    // ------------------------------------------------------------------
    // v2.26.0 mutation-driven edge cases
    // ------------------------------------------------------------------

    public function testDelayDueRoundsDownTheFloatProductSoAnExactAdvanceFires(): void
    {
        $scheduler = $this->scheduler;
        $tick = null;

        $scheduler->run(function () use ($scheduler, &$tick): string {
            // 0.002 * 1e9 = 2000000.0000000002: round() must produce 2000000,
            // matching the fake clock's advanceSeconds(0.002) exactly.
            $tick = $scheduler->delay(0.002, static fn (): string => 'tick');
            $this->clock->advanceSeconds(0.002);
            $scheduler->suspend();

            return 'ok';
        });

        self::assertSame(TaskState::Succeeded, $tick?->state());
        self::assertSame([], $this->sleeper->sleepsMs, 'the due instant was reached exactly -> no idle wait');
    }

    public function testDelayDueDoesNotFloorAwayTheLastNanosecond(): void
    {
        $scheduler = $this->scheduler;
        $tick = null;

        $scheduler->run(function () use ($scheduler, &$tick): string {
            // 0.0021 * 1e9 = 2099999.9999999998: round() must produce 2100000,
            // so parking the clock one nanosecond earlier keeps the timer pending.
            $tick = $scheduler->delay(0.0021, static fn (): string => 'tick');
            $this->clock->nano = 2099999;
            $scheduler->suspend();

            return 'ok';
        });

        self::assertSame([1], $this->sleeper->sleepsMs, 'the pump idle-waits the final millisecond: the timer was NOT due at 2099999ns');
    }

    public function testSleepWithWholeSecondsRecordsTheFullIdleWait(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $scheduler->sleep(0.02);
            $scheduler->sleep(1.0);

            return 'ok';
        });

        self::assertSame([20, 1000], $this->sleeper->sleepsMs);
    }

    public function testSleepDueRoundsDownTheFloatProductSoTheIdleWaitIsExact(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            // 0.002 * 1e9 = 2000000.0000000002: the timer must land on exactly
            // 2ms, so the pump's ceil() computes a 2ms idle wait, not 3ms.
            $scheduler->sleep(0.002);

            return 'ok';
        });

        self::assertSame([2], $this->sleeper->sleepsMs);
    }

    public function testPumpIdleWaitsAreCeiledToMilliseconds(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $scheduler->sleep(0.0203);

            return 'ok';
        });

        self::assertSame([21], $this->sleeper->sleepsMs, '20.3ms must ceil to a 21ms idle wait');
    }

    public function testSchedulerReuseAfterUnobservedFailureStartsClean(): void
    {
        $scheduler = $this->scheduler;

        try {
            $scheduler->run(function () use ($scheduler): string {
                $scheduler->spawn(static fn (): never => throw new \RuntimeException('stale'), 'stale-task');

                return 'main-ok';
            });
            self::fail('run one must surface the unobserved failure');
        } catch (UnobservedTaskException $exception) {
            self::assertStringContainsString('stale', $exception->getMessage());
        }

        self::assertSame(0, $scheduler->run(static fn (): string => 'clean'), 'run two must not re-surface old failures');
    }

    public function testTaskThrowingTaskCancelledExceptionIsTreatedAsCancellation(): void
    {
        $scheduler = $this->scheduler;
        $state = null;

        $scheduler->run(function () use ($scheduler, &$state): string {
            $task = $scheduler->spawn(static fn (): never => throw new TaskCancelledException('giving up'), 'resigned');
            $observer = $scheduler->spawn(function () use ($scheduler, $task): string {
                try {
                    $scheduler->await($task);
                } catch (TaskCancelledException) {
                    // observed
                }

                return (string) $task->state()->name;
            }, 'observer');

            $state = $scheduler->await($observer);

            return 'ok';
        });

        self::assertSame('Cancelled', $state);
    }

    public function testCancellingTheTimeoutCallerPropagatesTheCancellation(): void
    {
        $scheduler = $this->scheduler;
        $states = [];

        $scheduler->run(function () use ($scheduler, &$states): string {
            $guarded = $scheduler->spawn(function () use ($scheduler): string {
                $result = $scheduler->timeout(5.0, function () use ($scheduler): string {
                    $scheduler->sleep(4.0);

                    return 'slow';
                });

                return is_string($result) ? $result : '';
            }, 'guarded');
            $killer = $scheduler->spawn(static fn (): bool => $guarded->cancel(), 'killer');

            $scheduler->await($killer);
            $states['guarded'] = $guarded->state();
            $states['throwable'] = $guarded->throwable();

            return 'ok';
        });

        self::assertSame(TaskState::Cancelled, $states['guarded'] ?? null, 'the caller cancellation wins over the deadline');
        self::assertInstanceOf(TaskCancelledException::class, $states['throwable'] ?? null, 'the cancellation reason must survive, not become a TypeError');
    }

    public function testThreeTimersInsertedOutOfOrderFireInDueOrder(): void
    {
        $scheduler = $this->scheduler;
        $ran = [];

        $scheduler->run(function () use ($scheduler, &$ran): string {
            $first = $scheduler->delay(0.03, function () use (&$ran): string {
                $ran[] = '30ms';

                return 'a';
            });
            $second = $scheduler->delay(0.01, function () use (&$ran): string {
                $ran[] = '10ms';

                return 'b';
            });
            $third = $scheduler->delay(0.005, function () use (&$ran): string {
                $ran[] = '5ms';

                return 'c';
            });

            $results = $scheduler->awaitAll([$first, $second, $third]);

            self::assertSame(['a', 'b', 'c'], $results, 'awaitAll returns results in input order');

            return 'ordered';
        });

        self::assertSame(['5ms', '10ms', '30ms'], $ran, 'timers must fire strictly by due instant');
    }

    // ------------------------------------------------------------------
    // Internal task surface (direct construction)
    // ------------------------------------------------------------------

    public function testFiberTaskInternalStateSurface(): void
    {
        $task = new FiberTask($this->scheduler, 99, 'direct', static fn (): int => 5);

        self::assertSame(99, $task->id());
        self::assertSame('direct', $task->name());
        self::assertSame(TaskState::Pending, $task->state());
        self::assertNull($task->fiber());
        self::assertFalse($task->isObserved());
        self::assertFalse($task->isCancelRequested());
        self::assertNull($task->suspension()->handle());
        self::assertTrue($task->isOwnedBy($this->scheduler));
        self::assertFalse($task->isOwnedBy(new FiberScheduler()));

        $task->markObserved();
        self::assertTrue($task->isObserved());

        $task->requestCancellation();
        self::assertTrue($task->isCancelRequested());

        $task->finish(TaskState::Succeeded, 5, null);
        self::assertSame(TaskState::Succeeded, $task->state());

        $fired = [];
        $task->addCompletionCallback(static function () use (&$fired): void {
            $fired[] = 'immediate';
        });
        self::assertSame(['immediate'], $fired, 'callbacks on a settled task fire immediately');
    }

    public function testFiberTaskRequireThrowableGuardsNonFailureStates(): void
    {
        $task = new FiberTask($this->scheduler, 98, 'guarded', static fn (): int => 5);
        $task->finish(TaskState::Succeeded, 5, null);

        try {
            $task->requireThrowable();
            self::fail('requireThrowable on a succeeded task must throw');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('carries no failure reason (state: Succeeded)', $exception->getMessage());
        }
    }

    public function testSchedulerDefaultsAreUsable(): void
    {
        $scheduler = new FiberScheduler();
        self::assertSame(0, $scheduler->run(static fn (): int => 1));
    }
}
