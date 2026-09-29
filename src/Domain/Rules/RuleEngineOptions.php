<?php

declare(strict_types=1);

/*
 * ZEF Framework — Rules (Domain layer: value objects)
 * Added in v2.27.0 (Async Rules: concurrent rule evaluation on the fiber scheduler).
 */

namespace Zef\Framework\Rules;

/**
 * Per-evaluation knobs for the async rule engine. Immutable: wither methods
 * return modified copies, so one options instance can be safely shared and
 * specialised per call site.
 *
 * Defaults: unlimited concurrency, no per-rule deadline, no fail-fast.
 */
final readonly class RuleEngineOptions
{
    /**
     * @param null|int $concurrency maximum number of rules running at the
     *                              same time; null means unlimited
     * @param null|float $perRuleTimeout evaluation deadline per rule in
     *                                   seconds (cooperative: only rules
     *                                   that suspend can be interrupted);
     *                                   null means no deadline
     * @param bool $failFast when true, the first failed verdict cancels all
     *                       still-pending sibling evaluations; their verdicts
     *                       become Skipped
     */
    public function __construct(
        private ?int $concurrency = null,
        private ?float $perRuleTimeout = null,
        private bool $failFast = false,
    ) {
        if ($concurrency !== null && $concurrency < 1) {
            throw new \InvalidArgumentException(sprintf(
                'rule engine concurrency must be >= 1 or null for unlimited, got %d.',
                $concurrency,
            ));
        }

        if ($perRuleTimeout !== null && $perRuleTimeout < 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'rule engine per-rule timeout must be >= 0 seconds or null for none, got %F.',
                $perRuleTimeout,
            ));
        }
    }

    public function withConcurrency(?int $concurrency): self
    {
        return new self($concurrency, $this->perRuleTimeout, $this->failFast);
    }

    public function withPerRuleTimeout(?float $perRuleTimeout): self
    {
        return new self($this->concurrency, $perRuleTimeout, $this->failFast);
    }

    public function withFailFast(bool $failFast = true): self
    {
        return new self($this->concurrency, $this->perRuleTimeout, $failFast);
    }

    public function concurrency(): ?int
    {
        return $this->concurrency;
    }

    public function perRuleTimeout(): ?float
    {
        return $this->perRuleTimeout;
    }

    public function failFast(): bool
    {
        return $this->failFast;
    }
}
