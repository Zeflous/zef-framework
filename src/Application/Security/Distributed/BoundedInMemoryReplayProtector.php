<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class BoundedInMemoryReplayProtector implements ReplayProtectorInterface
{
    /**
     * @var array<string,int>
     */
    private array $seen = [];

    public function __construct(
        private readonly int $capacity = 1024,
        private readonly int $windowMs = 300_000,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('capacity must be >= 1.');
        }
        if ($windowMs < 1) {
            throw new \InvalidArgumentException('windowMs must be >= 1.');
        }
    }

    #[\Override]
    public function check(?string $replayId, int $nowMs): ReplayResult
    {
        $decision = $this->shapeDecision($replayId)
            ?? $this->admissionDecision((string) $replayId, $nowMs);

        return new ReplayResult($decision);
    }

    /** Structural fast paths: absent id is not checkable, a malformed id is rejected outright. */
    private function shapeDecision(?string $replayId): ?ReplayDecision
    {
        if ($replayId === null) {
            return ReplayDecision::NOT_REQUIRED;
        }
        if ($replayId === '' || strlen($replayId) > SecurityRequest::MAX_REPLAY_ID_BYTES) {
            return ReplayDecision::REJECTED;
        }

        return null;
    }

    private function admissionDecision(string $replayId, int $nowMs): ReplayDecision
    {
        $cutoff = $nowMs - $this->windowMs;
        // N-6 (issue #176): the stale sweep is lazy — it only runs under
        // capacity pressure, so the steady-state check is O(1) instead of
        // O(capacity) per request. Decision semantics are unchanged: an
        // entry older than the window is evicted on touch (its replay
        // window has lapsed, so the id is re-acceptable), and UNAVAILABLE
        // still fires only when the entries surviving the sweep fill the
        // capacity. The array stays bounded by $capacity either way,
        // because admission requires a free slot.
        $seenAt = $this->seen[$replayId] ?? null;
        if ($seenAt !== null && $seenAt >= $cutoff) {
            return ReplayDecision::DUPLICATE;
        }
        if ($seenAt !== null) {
            unset($this->seen[$replayId]);
        }
        if (!$this->hasFreeSlot($cutoff)) {
            return ReplayDecision::UNAVAILABLE;
        }
        $this->seen[$replayId] = $nowMs;

        return ReplayDecision::ACCEPT;
    }

    private function hasFreeSlot(int $cutoff): bool
    {
        if (count($this->seen) < $this->capacity) {
            return true;
        }
        foreach ($this->seen as $id => $at) {
            if ($at < $cutoff) {
                unset($this->seen[$id]);
            }
        }

        return count($this->seen) < $this->capacity;
    }
}
