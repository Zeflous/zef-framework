<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * In-memory {@see EventStoreInterface} — the reference adapter for tests
 * and single-process demos.
 *
 * - per-aggregate streams keep strict append order; a flat timeline serves
 *   streamAll() in global-sequence order (both are assigned under the same
 *   counter, so index i always holds globalSequence i+1);
 * - the concurrency check compares the last committed version with the
 *   caller's expectation and raises {@see ConcurrencyException} before any
 *   state is mutated (atomic per call);
 * - NOT thread-safe and intentionally volatile: everything dies with the
 *   process, which is exactly what unit tests want.
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, array<string, list<StoredEvent>>> */
    private array $streams = [];

    /** @var list<StoredEvent> */
    private array $timeline = [];

    private int $nextGlobalSequence = 1;

    /**
     * @param null|(\Closure(): int) $clock recordedAt source (default: realtime nanoseconds)
     */
    public function __construct(private readonly ?\Closure $clock = null) {}

    #[\Override]
    public function appendToStream(
        string $aggregateType,
        string $aggregateId,
        int $expectedVersion,
        PendingEvent ...$events,
    ): array {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        if ($expectedVersion < 0) {
            throw new EventSourcingException("Expected version must be >= 0 (got {$expectedVersion}).");
        }
        if ($events === []) {
            throw new EventSourcingException('appendToStream() requires at least one event.');
        }

        $existing = $this->streams[$aggregateType][$aggregateId] ?? [];
        $actual = $existing === []
            ? 0
            : $existing[\count($existing) - 1]->version;
        if ($actual !== $expectedVersion) {
            throw new ConcurrencyException($expectedVersion, $actual);
        }

        $clock = $this->clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
        $version = $expectedVersion;
        $created = [];
        foreach ($events as $pending) {
            ++$version;
            $globalSequence = $this->nextGlobalSequence;
            ++$this->nextGlobalSequence;
            $created[] = new StoredEvent(
                eventId: bin2hex(random_bytes(16)),
                aggregateType: $aggregateType,
                aggregateId: $aggregateId,
                version: $version,
                globalSequence: $globalSequence,
                eventType: $pending->eventType,
                payload: $pending->payload,
                metadata: $pending->metadata,
                recordedAtUnixNano: $clock(),
            );
        }

        // Validation of every event happened above (StoredEvent ctor);
        // only now is the store mutated.
        $this->streams[$aggregateType][$aggregateId] = [...$existing, ...$created];
        foreach ($created as $event) {
            $this->timeline[] = $event;
        }

        return $created;
    }

    #[\Override]
    public function loadStream(string $aggregateType, string $aggregateId, int $afterVersion = 0): array
    {
        if ($afterVersion < 0) {
            throw new EventSourcingException("afterVersion must be >= 0 (got {$afterVersion}).");
        }
        $events = $this->streams[$aggregateType][$aggregateId] ?? [];
        if ($afterVersion === 0) {
            return $events;
        }

        // Regresi I-10 (issue #175): the tail cut is applied here — the
        // reference adapter has no SQL to push it into, so it filters in
        // memory with the same `version > afterVersion` semantics.
        return array_values(array_filter(
            $events,
            static fn (StoredEvent $event): bool => $event->version > $afterVersion,
        ));
    }

    #[\Override]
    public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array
    {
        if ($fromGlobalSequence < 1) {
            throw new EventSourcingException("fromGlobalSequence must be >= 1 (got {$fromGlobalSequence}).");
        }
        if ($limit !== null && $limit < 1) {
            throw new EventSourcingException("limit must be >= 1 when provided (got {$limit}).");
        }
        $slice = \array_slice($this->timeline, $fromGlobalSequence - 1);
        if ($limit !== null) {
            return \array_slice($slice, 0, $limit);
        }

        return $slice;
    }

    /**
     * Test/ops helper: total number of committed events.
     */
    public function countEvents(): int
    {
        return \count($this->timeline);
    }

    /**
     * Test/ops helper: last assigned global sequence (0 = empty store).
     */
    public function lastGlobalSequence(): int
    {
        return $this->nextGlobalSequence - 1;
    }
}
