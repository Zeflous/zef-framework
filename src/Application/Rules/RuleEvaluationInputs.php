<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Application layer: async rule engine)
 * Added in the sonar-zero hardening pass: per-evaluation input bundle
 * for AsyncRuleEngine::executeRule(), keeping its signature small.
 */

namespace Zef\Framework\Rules;

use Zef\Framework\Runtime\Async\CancellationTokenInterface;
use Zef\Framework\Runtime\Async\CancellationTokenSource;
use Zef\Framework\Runtime\Async\Semaphore;

/**
 * Immutable per-rule evaluation inputs shared by every rule body task:
 * the collected arguments of {@see AsyncRuleEngine::executeRule()}.
 *
 * The shared cancellation source and semaphore are handles INTO the
 * evaluation-wide state — they are immutable references, the state they
 * point at is not.
 */
final readonly class RuleEvaluationInputs
{
    public function __construct(
        public int $index,
        public RuleInterface $rule,
        public mixed $subject,
        public CancellationTokenInterface $token,
        public RuleEngineOptions $options,
        public Semaphore $semaphore,
        public CancellationTokenSource $cancellationSource,
    ) {}
}
