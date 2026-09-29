<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Application layer: in-process orchestration)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * Evaluates a set of {@see RateLimitRule} tiers for one identity against an
 * underlying limiter and aggregates the outcomes into a
 * {@see RateLimitVerdict} ("most restrictive wins").
 *
 * Storage isolation: the composite storage key is `<ruleName>> <identity>`,
 * so two tiers over the SAME underlying limiter never share a bucket even
 * for the same identity, and two identities never share a bucket within a
 * tier. The ">" separator is reserved (rule names are validated against it),
 * so the mapping is collision-free.
 *
 * Cost handling: rules with cost > 1 require a
 * {@see CostAwareRateLimiterInterface} underneath; passing a plain
 * {@see RateLimiterInterface} fails fast with a naming error message at
 * evaluation time (the alternative — silently charging cost 1 — would
 * undermine the quota). Cost-1 rules work against ANY limiter, including
 * the pre-existing InMemory/APCu/Redis adapters.
 */
final readonly class TieredRateLimiter
{
    /**
     * @param list<RateLimitRule> $rules
     */
    public function __construct(
        private RateLimiterInterface $limiter,
        private array $rules = [],
    ) {
        foreach ($rules as $rule) {
            if (!$rule instanceof RateLimitRule) {
                throw new \InvalidArgumentException('Tiered rate limiter rules must be RateLimitRule instances.');
            }
        }
    }

    /**
     * Evaluates ONE rule against the identity.
     *
     * @throws \InvalidArgumentException when identity is empty/oversized or a
     *         weighted rule is paired with a cost-unaware limiter
     */
    public function evaluate(RateLimitRule $rule, string $identity): RateLimitRuleOutcome
    {
        if ($identity === '') {
            throw new \InvalidArgumentException('Rate limit identity must not be empty.');
        }
        if (strlen($identity) > 512) {
            throw new \InvalidArgumentException('Rate limit identity must be at most 512 characters.');
        }
        if ($rule->cost > 1 && !$this->limiter instanceof CostAwareRateLimiterInterface) {
            throw new \InvalidArgumentException(sprintf(
                'Rule "%s" has cost %d but the underlying limiter does not support weighted consumption.',
                $rule->name,
                $rule->cost,
            ));
        }

        $decision = $this->limiter instanceof CostAwareRateLimiterInterface
            ? $this->limiter->consume(
                $this->storageKey($rule, $identity),
                $rule->limit,
                $rule->windowSeconds,
                $rule->cost,
            )
            : $this->limiter->check(
                $this->storageKey($rule, $identity),
                $rule->limit,
                $rule->windowSeconds,
            );

        return new RateLimitRuleOutcome(
            $rule->name,
            $decision->allowed,
            $decision->limit,
            $decision->remaining,
            $decision->retryAfter,
            $decision->resetAfter > 0 ? $decision->resetAfter : $decision->retryAfter,
        );
    }

    /**
     * Evaluates EVERY given rule (a request matching several tiers consumes
     * quota in all of them — outer caps and inner caps stack) for the
     * identity and aggregates the outcomes.
     *
     * When no rules are passed, the rule set given at construction time is
     * used; an empty set in BOTH places is a wiring error and throws.
     *
     * @param null|list<RateLimitRule> $rules null = the rules given at
     *        construction time
     *
     * @throws \InvalidArgumentException when no rule is supplied or the
     *         identity is invalid
     */
    public function evaluateAll(?array $rules, string $identity): RateLimitVerdict
    {
        $rules ??= $this->rules;
        if ($rules === []) {
            throw new \InvalidArgumentException('Tiered rate limit evaluation requires at least one rule.');
        }

        $outcomes = [];
        foreach ($rules as $rule) {
            $outcomes[] = $this->evaluate($rule, $identity);
        }

        return RateLimitVerdict::fromOutcomes($outcomes);
    }

    private function storageKey(RateLimitRule $rule, string $identity): string
    {
        return $rule->name . '>' . $identity;
    }
}
