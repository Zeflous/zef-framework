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
    /** @var (\Closure(): int) */
    private readonly \Closure $clock;

    /** Lazily-created lease-claiming collaborator ({@see claims()}). */
    private ?InMemoryOutboxClaims $claims = null;

    /** Lazily-created insertion-ordered entry storage ({@see book()}). */
    private ?OutboxEntryBook $book = null;

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
        $this->book()->add($entry);

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();
        $eligible = $this->book()->matching(
            static fn (OutboxEntry $entry): bool => $entry->isPending() && $entry->nextAttemptAtUnixNano <= $now,
        );

        return \array_slice($eligible, 0, $limit);
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->book()->replace(OutboxEntryTransitions::processed($this->requireEntry($id)));
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->book()->replace(OutboxEntryTransitions::retryScheduled(
            $this->requireEntry($id),
            $error,
            $retryAtUnixNano,
        ));
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->book()->replace(OutboxEntryTransitions::dead($this->requireEntry($id), $error));
    }

    #[\Override]
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }
        $dead = $this->book()->matching(static fn (OutboxEntry $entry): bool => $entry->isFailed());

        return \array_slice($dead, 0, $limit);
    }

    #[\Override]
    public function countPending(): int
    {
        return $this->book()->countMatching(static fn (OutboxEntry $entry): bool => $entry->isPending());
    }

    #[\Override]
    public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
    {
        $now = $nextAttemptAtUnixNano ?? ($this->clock)();
        EventGrammar::assertUnixNano($now, 'nextAttemptAtUnixNano');
        $updated = $this->requeuedOrFail($id, $now);
        $this->book()->replace($updated);

        return $updated;
    }

    /**
     * Test/ops helper: total number of entries in every state.
     */
    public function count(): int
    {
        return $this->book()->count();
    }

    // -------------------------------------------------- lease claiming (v2.31.0)

    #[\Override]
    public function claimBatch(string $owner, int $limit, int $leaseSeconds, ?int $nowUnixNano = null): array
    {
        return $this->claims()->claimBatch($owner, $limit, $leaseSeconds, $nowUnixNano);
    }

    #[\Override]
    public function releaseLease(string $owner): int
    {
        return $this->claims()->releaseLease($owner);
    }

    /**
     * The insertion-ordered entry storage: created on first use so the
     * constructor stays work-free (clock wiring only).
     */
    private function book(): OutboxEntryBook
    {
        $this->book ??= new OutboxEntryBook();

        return $this->book;
    }

    /**
     * The lease-claiming collaborator: created on first use, sharing the
     * entry storage and the store's clock.
     */
    private function claims(): InMemoryOutboxClaims
    {
        $this->claims ??= new InMemoryOutboxClaims($this->book(), $this->clock);

        return $this->claims;
    }

    private function requireEntry(string $id): OutboxEntry
    {
        $entry = $this->book()->find($id);
        if (!$entry instanceof OutboxEntry) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }

        return $entry;
    }

    private function requeuedOrFail(string $id, int $now): OutboxEntry
    {
        $entry = $this->requireEntry($id);
        if (!$entry->isFailed()) {
            throw new EventSourcingException(
                "Only failed entries can be requeued (entry '{$entry->id}' is '{$entry->status}').",
            );
        }

        return OutboxEntryTransitions::requeued($entry, $now);
    }
}
