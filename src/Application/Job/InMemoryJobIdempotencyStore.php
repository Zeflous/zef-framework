<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

use Zef\Framework\CQRS\IdempotencyTrait;

final class InMemoryJobIdempotencyStore implements JobIdempotencyStoreInterface
{
    use IdempotencyTrait;

    public function __construct(int $maxEntries = 10_000)
    {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('Job idempotency capacity must be positive.');
        }
        $this->idempotencyMaxEntries = $maxEntries;
        $this->idempotencyMaxKeyLength = 191;
    }

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        if ($key === '' || strlen($key) > $this->idempotencyMaxKeyLength) {
            throw new \InvalidArgumentException('Invalid job idempotency key.');
        }

        return $this->idempotencyRemember($key, $producer, $ttlSeconds);
    }
}
