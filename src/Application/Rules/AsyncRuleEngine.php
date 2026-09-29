<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Application layer: async rule engine)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

use Zef\Framework\Runtime\Async\CancellationTokenSource;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\Semaphore;
use Zef\Framework\Runtime\Async\TaskCancelledException;
use Zef\Framework\Runtime\Async\TaskInterface;

/**
 * The v2.27.0 async rules kernel: evaluates every rule as its own coroutine
 * on the v2.26.0 FiberScheduler, with a concurrency cap, cooperative
 * per-rule deadlines and optional fail-fast — deterministic, single-threaded,
 * no preemption.
 *
 * Structure per evaluation (all handles stay engine-internal):
 * - one BODY task per rule ("rule-<name>"): acquires a semaphore permit,
 *   spawns the INNER task that actually invokes the rule, awaits it, then
 *   converts every possible outcome into exactly one verdict — the body is
 *   total and never fails.
 * - one INNER task per rule ("eval-<name>"): raw rule invocation under the
 *   evaluation's cancellation token.
 * - one GUARD timer per guarded rule ("eval-<name>-deadline"): cancels the
 *   inner task when the per-rule deadline wins, which surfaces at the body's
 *   await as TaskCancelledException.
 *
 * Cancellation mapping at the body (the only place verdicts are decided):
 * - TaskCancelledException with the evaluation cancelled -> Skipped
 *   (fail-fast won the race);
 * - TaskCancelledException without a cancellation request -> deadline miss
 *   (the guard is the only other canceller of an inner task), reported as a
 *   Failed timeout verdict;
 * - any other throwable -> Failed verdict carrying the original throwable;
 * - a returned verdict is taken as-is.
 *
 * Fail-fast cancels every still-pending sibling: bodies queued before their
 * first step or parked on the semaphore are cancelled directly, while an
 * in-flight rule is interrupted through its inner task — TaskCancelledException
 * hits the rule at its next suspension point, so a rule MAY catch it and
 * return its own verdict (graceful cancellation); one that lets it escape is
 * reported as skipped. Rules that never suspend keep the runtime's
 * cooperative guarantee: they run to completion and their verdict is
 * recorded normally.
 */
final readonly class AsyncRuleEngine implements AsyncRuleEngineInterface
{
    private const string SKIPPED_BY_FAIL_FAST = 'rule "%s" was cancelled by fail-fast';

    public function __construct(private FiberScheduler $scheduler) {}

    public function evaluate(iterable $rules, mixed $subject = null, ?RuleEngineOptions $options = null): RuleReport
    {
        $options ??= new RuleEngineOptions();

        /** @var list<RuleInterface> $list */
        $list = [];

        $position = 0;

        foreach ($rules as $rule) {
            if (!$rule instanceof RuleInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'rule at index %d must implement RuleInterface, got %s.',
                    $position,
                    get_debug_type($rule),
                ));
            }

            $list[] = $rule;
            ++$position;
        }

        if ($list === []) {
            return new RuleReport([]);
        }

        if (!\Fiber::getCurrent() instanceof \Fiber) {
            throw new \LogicException(
                'AsyncRuleEngine::evaluate() must be called from inside a coroutine driven by '
                . 'its FiberScheduler; use AsyncRuleEngine::run() for a blocking top-level evaluation.',
            );
        }

        // "Unlimited" is realised as one permit per rule: the pool can never
        // starve, so no coroutine ever parks — observably identical to having
        // no semaphore at all, with a single code path for both cases.
        $semaphore = new Semaphore($this->scheduler, $options->concurrency() ?? count($list));
        $cancellationSource = new CancellationTokenSource();
        $token = $cancellationSource->token();
        $failFast = $options->failFast();

        /** @var array<int, RuleVerdict> $results */
        $results = [];

        /** @var array<int, TaskInterface> $bodies */
        $bodies = [];

        /** @var array<int, TaskInterface> $inFlight */
        $inFlight = [];

        foreach ($list as $index => $rule) {
            $inputs = new RuleEvaluationInputs(
                $index,
                $rule,
                $subject,
                $token,
                $options,
                $semaphore,
                $cancellationSource,
            );
            $bodies[$index] = $this->scheduler->spawn(
                function () use ($inputs, $failFast, &$results, &$bodies, &$inFlight): void {
                    $results[$inputs->index] = $this
                        ->executeRule($inputs, $inFlight)
                        ->forRule($inputs->rule->name())
                    ;

                    if ($failFast && $results[$inputs->index]->isFailed()) {
                        $inputs->cancellationSource->cancel();
                        $this->interruptPeers($bodies, $inFlight, $inputs->index);
                    }
                },
                sprintf('rule-%s', $rule->name()),
            );
        }

        foreach ($bodies as $index => $body) {
            try {
                $this->scheduler->await($body);
            } catch (TaskCancelledException) {
                // The body was cancelled before its first step (or its inner
                // task escaped a TaskCancelledException); every other path
                // records a verdict itself.
                $results[$index] ??= RuleVerdict::skip(
                    sprintf(self::SKIPPED_BY_FAIL_FAST, $list[$index]->name()),
                )->forRule($list[$index]->name());
            } catch (\Throwable $exception) {
                // Defensive: bodies are total by construction; this keeps the
                // one-verdict-per-rule invariant intact even on engine bugs.
                $results[$index] ??= RuleVerdict::fromThrowable($exception)->forRule($list[$index]->name());
            }
        }

        ksort($results);

        return new RuleReport(array_values($results));
    }

    public function run(iterable $rules, mixed $subject = null, ?RuleEngineOptions $options = null): RuleReport
    {
        $report = null;

        $this->scheduler->run(function () use (&$report, $rules, $subject, $options): void {
            $report = $this->evaluate($rules, $subject, $options);
        });

        if (!$report instanceof RuleReport) {
            // Defensive: scheduler->run() rethrows the main coroutine's
            // failure before returning, so the only way to get here with a
            // null report is an engine bug — fail loudly, never half-report.
            throw new \LogicException('the scheduler run settled without producing a rule report');
        }

        return $report;
    }

    /**
     * Runs one rule under the semaphore and optional deadline and converts
     * every outcome into a verdict — this method never throws.
     *
     * @param array<int, TaskInterface> $inFlight
     */
    private function executeRule(RuleEvaluationInputs $inputs, array &$inFlight): RuleVerdict
    {
        $timeout = $inputs->options->perRuleTimeout();

        try {
            $inputs->semaphore->acquire();

            try {
                $inner = $this->scheduler->spawn(
                    static fn (): RuleVerdict => $inputs->rule->evaluate($inputs->subject, $inputs->token),
                    sprintf('eval-%s', $inputs->rule->name()),
                );

                // Registered for fail-fast interruption regardless of the
                // deadline: an awaiting body is always interrupted through
                // its inner task so a graceful rule keeps its own verdict.
                $inFlight[$inputs->index] = $inner;

                $guard = null;

                if ($timeout !== null) {
                    $guard = $this->scheduler->delay(
                        $timeout,
                        static fn (): bool => $inner->cancel(),
                        sprintf('eval-%s-deadline', $inputs->rule->name()),
                    );
                }

                try {
                    return $this->awaitVerdict($inner);
                } finally {
                    if ($guard instanceof TaskInterface) {
                        $guard->cancel();
                    }

                    // If awaitVerdict threw before the inner settled (deadline
                    // interrupt, fail-fast, or the deferred-cancel entry guard
                    // firing between spawn and await — ZEF-DEEP-03), the
                    // spawned inner must be cancelled NOW: otherwise its
                    // queued first step would run the rule body later, after
                    // the engine already recorded this rule as skipped. For a
                    // settled inner this is a provable no-op (cancel() on a
                    // done task returns false without side effects).
                    $inner->cancel();

                    unset($inFlight[$inputs->index]);
                }
            } finally {
                $inputs->semaphore->release();
            }
        } catch (TaskCancelledException) {
            return $this->cancelledVerdict($inputs);
        } catch (\Throwable $exception) {
            return RuleVerdict::fromThrowable($exception);
        }
    }

    /**
     * Maps a cancellation surfacing at the rule body into its verdict: an
     * evaluation-wide cancellation request means fail-fast won the race
     * (the rule is Skipped); otherwise the per-rule deadline guard was the
     * canceller — a rule that throws TaskCancelledException for its own
     * reasons is reported the same way (see RuleInterface).
     */
    private function cancelledVerdict(RuleEvaluationInputs $inputs): RuleVerdict
    {
        if ($inputs->cancellationSource->isCancellationRequested()) {
            return RuleVerdict::skip(sprintf(self::SKIPPED_BY_FAIL_FAST, $inputs->rule->name()));
        }

        return RuleVerdict::timeout($inputs->rule->name(), $inputs->options->perRuleTimeout() ?? 0.0);
    }

    /**
     * Awaits the inner rule task and hands back its verdict with a real type.
     */
    private function awaitVerdict(TaskInterface $inner): RuleVerdict
    {
        $result = $this->scheduler->await($inner);

        if (!$result instanceof RuleVerdict) {
            // Defensive: the inner task closure is typed to return
            // RuleVerdict, so await() can only deliver a verdict here;
            // anything else is an engine bug that must fail loudly instead
            // of leaking out as mixed.
            throw new \LogicException('inner rule task did not produce a RuleVerdict');
        }

        return $result;
    }

    /**
     * @param array<int, TaskInterface> $bodies
     * @param array<int, TaskInterface> $inFlight
     */
    private function interruptPeers(array $bodies, array $inFlight, int $self): void
    {
        foreach ($bodies as $index => $body) {
            if ($index === $self || isset($inFlight[$index])) {
                // In-flight rules are interrupted through their inner task so
                // the rule gets its cooperative chance to settle gracefully;
                // cancelling the body instead would discard its verdict.
                continue;
            }

            $body->cancel();
        }

        foreach ($inFlight as $inner) {
            $inner->cancel();
        }
    }
}
