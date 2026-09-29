<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Extracted from InMemoryOutbox (v2.31.0 lease claiming) so the store class
 * stays under its size budget.
 */

namespace Zef\Framework\EventSourcing;

/**
 * Claim-based lease surface of the in-memory outbox, mirroring the
 * {@see OutboxClaimInterface} semantics of the PDO adapter. Operates on the
 * store's shared {@see OutboxEntryBook} storage.
 */
final class InMemoryOutboxClaims
{
    /**
     * @param (\Closure(): int) $clock now source shared with the store
     */
    public function __construct(
        private readonly OutboxEntryBook $book,
        private readonly \Closure $clock,
    ) {}

    /**
     * @return list<OutboxEntry>
     */
    public function claimBatch(string $owner, int $limit, int $leaseSeconds, ?int $nowUnixNano = null): array
    {
        if ($owner === '' || \strlen($owner) > 64) {
            throw new EventSourcingException('claimBatch() owner must be 1..64 chars.');
        }
        if ($limit < 1) {
            throw new EventSourcingException("claimBatch() limit must be >= 1 (got {$limit}).");
        }
        if ($leaseSeconds < 1) {
            throw new EventSourcingException("claimBatch() leaseSeconds must be >= 1 (got {$leaseSeconds}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();
        $leaseUntil = $now + $leaseSeconds * 1_000_000_000;
        EventGrammar::assertUnixNano($leaseUntil, 'lease deadline');

        $claimable = $this->book->matching(
            static fn (OutboxEntry $entry): bool => $entry->isPending()
                && $entry->nextAttemptAtUnixNano <= $now
                && !$entry->hasActiveLease($now),
        );

        $claimed = [];
        foreach (\array_slice($claimable, 0, $limit) as $entry) {
            $leased = OutboxEntryTransitions::leased($entry, $owner, $leaseUntil);
            $this->book->replace($leased);
            $claimed[] = $leased;
        }

        return $claimed;
    }

    public function releaseLease(string $owner): int
    {
        if ($owner === '') {
            throw new EventSourcingException('releaseLease() owner must be non-empty.');
        }
        $leased = $this->book->matching(
            static fn (OutboxEntry $entry): bool => $entry->leaseOwner === $owner,
        );
        foreach ($leased as $entry) {
            $this->book->replace(OutboxEntryTransitions::leaseReleased($entry));
        }

        return \count($leased);
    }
}
