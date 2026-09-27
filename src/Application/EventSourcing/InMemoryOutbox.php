<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * In-memory {@see OutboxStoreInterface} — FIFO by (createdAt, id).
 *
 * Also implements {@see OutboxClaimInterface} (v2.31.0) with the same lease
 * semantics as the PDO adapter, so tests and in-process setups exercise the
 * claim-based relay against a store that behaves identically.
 */
final class InMemoryOutbox implements OutboxStoreInterface, OutboxClaimInterface
{
    /** @var array<string, OutboxEntry> keyed by entry id, insertion-ordered */
    private array $entries = [];

    /** @var (\Closure(): int) */
    private readonly \Closure $clock;

    /**
     * @param null|(\Closure(): int) $clock now source — injected or the realtime default,
     *                                      resolved once per instance (not per call)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    #[\Override]
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
    {
        $now = ($this->clock)();
        $entry = new OutboxEntry(
            id: bin2hex(random_bytes(16)),
            messageType: $messageType,
            payload: $payload,
            metadata: $metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $now,
            lastError: null,
            createdAtUnixNano: $now,
        );
        $this->entries[$entry->id] = $entry;

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();
        $eligible = [];
        foreach ($this->entries as $entry) {
            if ($entry->isPending() && $entry->nextAttemptAtUnixNano <= $now) {
                $eligible[] = $entry;
            }
        }
        usort($eligible, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        return \array_slice($eligible, 0, $limit);
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts,
            status: OutboxEntry::STATUS_PROCESSED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $entry->lastError,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $retryAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->entries[$id] = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_FAILED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        ));
    }

    #[\Override]
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }
        $dead = [];
        foreach ($this->entries as $entry) {
            if ($entry->isFailed()) {
                $dead[] = $entry;
            }
        }
        usort($dead, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        return \array_slice($dead, 0, $limit);
    }

    #[\Override]
    public function countPending(): int
    {
        $pending = 0;
        foreach ($this->entries as $entry) {
            if ($entry->isPending()) {
                ++$pending;
            }
        }

        return $pending;
    }

    #[\Override]
    public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
    {
        $now = $nextAttemptAtUnixNano ?? ($this->clock)();
        EventGrammar::assertUnixNano($now, 'nextAttemptAtUnixNano');
        $updated = $this->mutate($id, static fn (OutboxEntry $entry): OutboxEntry => $entry->isFailed()
            ? new OutboxEntry(
                id: $entry->id,
                messageType: $entry->messageType,
                payload: $entry->payload,
                metadata: $entry->metadata,
                attempts: 0,
                status: OutboxEntry::STATUS_PENDING,
                nextAttemptAtUnixNano: $now,
                lastError: $entry->lastError,
                createdAtUnixNano: $entry->createdAtUnixNano,
            )
            : throw new EventSourcingException(
                "Only failed entries can be requeued (entry '{$entry->id}' is '{$entry->status}').",
            ));
        $this->entries[$id] = $updated;

        return $updated;
    }

    /**
     * Test/ops helper: total number of entries in every state.
     */
    public function count(): int
    {
        return \count($this->entries);
    }

    // -------------------------------------------------- lease claiming (v2.31.0)

    #[\Override]
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

        $claimable = [];
        foreach ($this->entries as $entry) {
            if ($entry->isPending() && $entry->nextAttemptAtUnixNano <= $now && !$entry->hasActiveLease($now)) {
                $claimable[] = $entry;
            }
        }
        usort($claimable, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id]);

        $claimed = [];
        foreach (\array_slice($claimable, 0, $limit) as $entry) {
            $leased = new OutboxEntry(
                id: $entry->id,
                messageType: $entry->messageType,
                payload: $entry->payload,
                metadata: $entry->metadata,
                attempts: $entry->attempts,
                status: $entry->status,
                nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
                lastError: $entry->lastError,
                createdAtUnixNano: $entry->createdAtUnixNano,
                leaseOwner: $owner,
                leaseUntilUnixNano: $leaseUntil,
            );
            $this->entries[$leased->id] = $leased;
            $claimed[] = $leased;
        }

        return $claimed;
    }

    #[\Override]
    public function releaseLease(string $owner): int
    {
        if ($owner === '') {
            throw new EventSourcingException('releaseLease() owner must be non-empty.');
        }
        $released = 0;
        foreach ($this->entries as $id => $entry) {
            if ($entry->leaseOwner === $owner) {
                $this->entries[$id] = new OutboxEntry(
                    id: $entry->id,
                    messageType: $entry->messageType,
                    payload: $entry->payload,
                    metadata: $entry->metadata,
                    attempts: $entry->attempts,
                    status: $entry->status,
                    nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
                    lastError: $entry->lastError,
                    createdAtUnixNano: $entry->createdAtUnixNano,
                );
                ++$released;
            }
        }

        return $released;
    }

    /** @param \Closure(OutboxEntry): OutboxEntry $fn */
    private function mutate(string $id, \Closure $fn): OutboxEntry
    {
        $entry = $this->entries[$id] ?? null;
        if (!$entry instanceof OutboxEntry) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }

        return $fn($entry);
    }
}
