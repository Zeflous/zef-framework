<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $initialDelayMs = 100,
        public int $maxDelayMs = 30_000,
        public float $multiplier = 2.0,
        public int $jitterMs = 0,
    ) {
        $hasValidAttemptBudget = $maxAttempts >= 1 && $initialDelayMs >= 0 && $jitterMs >= 0;
        $hasValidDelayRange = $maxDelayMs >= $initialDelayMs && $multiplier >= 1.0;
        if (!$hasValidAttemptBudget || !$hasValidDelayRange) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
    }

    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    public function delayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Attempt must be positive.');
        }
        $product = $this->initialDelayMs * ($this->multiplier ** max(0, $attempt - 1));
        // P-18 (issue #172): a large attempt (or multiplier) overflows the
        // product to INF, and (int) round(INF) === 0 — collapsing the backoff
        // to 0ms and letting the worker hammer a failing handler, the exact
        // thundering herd this policy exists to prevent. (An int-cast of a
        // huge-but-finite float goes negative for the same reason.) Saturate
        // at the cap whenever the product exceeds it, before any cast.
        $raw = $product > $this->maxDelayMs ? $this->maxDelayMs : (int) round($product);
        $delay = min($this->maxDelayMs, $raw);
        if ($this->jitterMs > 0) {
            $delay += random_int(0, $this->jitterMs);
        }

        return min($this->maxDelayMs, $delay);
    }
}
