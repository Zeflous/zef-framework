<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Base class for event-sourced aggregates.
 *
 * Mechanics:
 * - {@see recordEvent()} appends a {@see PendingEvent}, advances the local
 *   version and immediately applies the event to state (the aggregate is
 *   always its own first projection);
 * - {@see AggregateRepository::persist()} sends
 *   the pending list to the store with `expectedVersion = pendingVersion()`
 *   and then clears it via {@see markCommitted()};
 * - replay calls {@see applyStored()} per committed event, so state rebuilds
 *   exactly the same way it was first written — one `apply()` switch.
 *
 * Subclasses implement:
 * - `apply(string $eventType, array $payload)` — the single state-mutating
 *   switch (MUST be total: every recorded event type has a branch);
 * - `aggregateType()` / `aggregateId()` — stream identity;
 * - `snapshotState()` + `restoreFromSnapshot()` + `createEmpty()` — the
 *   snapshot codec.
 */
abstract class AggregateRoot
{
    /** @var list<PendingEvent> */
    private array $pending = [];
    private int $version = 0;

    final public function version(): int
    {
        return $this->version;
    }

    /** @return list<PendingEvent> */
    final public function pendingEvents(): array
    {
        return $this->pending;
    }

    final public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /**
     * Last version confirmed by an event store (0 = nothing committed yet).
     * Local version minus the still-uncommitted tail.
     */
    final public function pendingVersion(): int
    {
        return $this->version - \count($this->pending);
    }

    final public function hasPendingEvents(): bool
    {
        return $this->pending !== [];
    }

    /**
     * @internal called by AggregateRepository after the shared transaction commits,
     *           or after a successful append when no shared transaction is configured
     */
    final public function markCommitted(): void
    {
        $this->pending = [];
    }

    /**
     * @internal replay one committed event; versions must arrive in strict
     *           steps of one — a gap or a regression means the stream on
     *           disk is corrupt (truncated, hand-edited or written by a
     *           rogue writer) and replaying it would silently fabricate a
     *           wrong aggregate state
     */
    final public function applyStored(StoredEvent $event): void
    {
        if ($event->version !== $this->version + 1) {
            throw new EventSourcingException(
                'Corrupt stream for event ' . $event->eventId . ': expected stored version '
                . ($this->version + 1) . ', got ' . $event->version . ' (aggregate version ' . $this->version . ').',
            );
        }
        $this->version = $event->version;
        $this->apply($event->eventType, $event->payload);
    }

    /**
     * @return array<mixed> JSON-encodable state payload for snapshotting
     */
    abstract public function snapshotState(): array;

    /**
     * Stream family label (grammar: EventGrammar::AGGREGATE_TYPE_PATTERN).
     */
    abstract public static function aggregateType(): string;

    abstract public function aggregateId(): string;

    /**
     * @param array<mixed> $state  payload produced by snapshotState()
     * @param int          $version the snapshot's stream version
     */
    abstract public static function restoreFromSnapshot(array $state, int $version): static;

    /**
     * A pristine instance at version 0 with no pending events, bound to
     * the given stream identity (the repository supplies the id it looked
     * up — an aggregate reconstructed from a stream must know who it is).
     */
    abstract public static function createEmpty(string $aggregateId): static;

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    protected function recordEvent(string $eventType, array $payload = [], array $metadata = []): void
    {
        // Validate first: a grammar failure must not advance the version.
        $pending = new PendingEvent($eventType, $payload, $metadata);
        ++$this->version;
        $this->pending[] = $pending;
        $this->apply($eventType, $payload);
    }

    /**
     * Seed the version counter when restoring from a snapshot. Only the
     * aggregate's own `restoreFromSnapshot()` factory should call it.
     */
    final protected function seedVersion(int $version): void
    {
        if ($version < 0) {
            throw new EventSourcingException("Snapshot version must be >= 0 (got {$version}).");
        }
        $this->version = $version;
    }

    /**
     * The one and only state-mutating switch; never called with an event
     * type the aggregate has not recorded first.
     */
    /**
     * @param array<mixed> $payload
     */
    abstract protected function apply(string $eventType, array $payload): void;
}
