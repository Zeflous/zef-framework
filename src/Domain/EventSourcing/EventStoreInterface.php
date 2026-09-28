<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Outbound port for event stores (append-only, per-aggregate streams).
 *
 * Contract:
 * - {@see appendToStream()} is atomic per call: either every pending event
 *   is committed with versions `expectedVersion+1..n`, or nothing changes
 *   and a {@see ConcurrencyException} is raised on a version mismatch;
 * - {@see loadStream()} returns the aggregate's events ordered by `version`
 *   ascending; an unknown stream is an empty list, not an error. The
 *   optional `$afterVersion` tail cut (default 0 = the whole stream,
 *   v2.31.0 / Regresi I-10, issue #175) makes snapshot-seeded replay load
 *   ONLY post-snapshot events — implementations MUST filter on it
 *   (`WHERE version > X` in SQL adapters, in-memory elsewhere) instead of
 *   returning the full stream for the caller to skip;
 * - {@see streamAll()} exposes the store-wide timeline ordered by
 *   `globalSequence` ascending for catch-up projections.
 *
 * Implementations SHOULD participate in an ambient transaction when one is
 * already open on the underlying connection (transaction level > 0) so the
 * {@see AggregateRepository} can commit events
 * and outbox entries atomically.
 */
interface EventStoreInterface
{
    /**
     * @param string                    $aggregateType   stream family label
     * @param string                    $aggregateId     stream identity
     * @param int                       $expectedVersion last committed version known to the caller
     *                                                   (0 = the stream must not exist yet)
     *
     * @return list<StoredEvent> the committed events, in append order
     *
     * @throws ConcurrencyException when the actual last version differs from $expectedVersion
     * @throws EventSourcingException on invalid grammar or an empty event list
     */
    public function appendToStream(string $aggregateType, string $aggregateId, int $expectedVersion, PendingEvent ...$events): array;

    /**
     * @param string $aggregateType stream family label
     * @param string $aggregateId   stream identity
     * @param int    $afterVersion  lower cut (exclusive): only events with
     *                              `version > afterVersion` are returned
     *                              (0 = the whole stream). Lets a
     *                              snapshot-seeded replay skip the
     *                              pre-snapshot tail inside the store
     *                              instead of hydrating it just to filter
     *                              it out in PHP (Regresi I-10, issue #175)
     *
     * @return list<StoredEvent> ordered by version ascending; empty for unknown streams
     */
    public function loadStream(string $aggregateType, string $aggregateId, int $afterVersion = 0): array;

    /**
     * Store-wide timeline slice for catch-up projections.
     *
     * @param int      $fromGlobalSequence inclusive start (>= 1)
     * @param null|int $limit              maximum number of events (>= 1 when provided)
     *
     * @return list<StoredEvent> ordered by globalSequence ascending
     */
    public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array;
}
