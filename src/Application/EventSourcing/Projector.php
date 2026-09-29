<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Catch-up projection runner.
 *
 * For each registered projection it reads the store-wide timeline starting
 * just past the projection's checkpoint, hands matching events to the
 * projection and advances the checkpoint after every successfully handled
 * event. A failing {@see ProjectionInterface::handle()} aborts the run with
 * the checkpoint left behind — the same event is re-delivered on the next
 * run (at-least-once).
 *
 * Reads are paged at {@see EventGrammar::MAX_PAGE} so even unbounded
 * catch-ups cannot balloon memory; `$batchLimit` bounds the number of
 * applied (matching) events per run, null means "drain to head".
 */
final class Projector
{
    /** @var array<string, ProjectionInterface> keyed by projection id */
    private array $projections = [];

    /**
     * @param list<ProjectionInterface> $projections at least one, unique ids
     */
    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly CheckpointStoreInterface $checkpoints,
        array $projections,
    ) {
        if ($projections === []) {
            throw new EventSourcingException('Projector requires at least one projection.');
        }
        foreach ($projections as $projection) {
            if (!$projection instanceof ProjectionInterface) {
                throw new EventSourcingException(
                    'Projector accepts ProjectionInterface instances (got ' . get_debug_type($projection) . ').',
                );
            }
            $id = $projection->projectionId();
            EventGrammar::assertAggregateType($id, 'projection id');
            if (isset($this->projections[$id])) {
                throw new EventSourcingException("Duplicate projection id '{$id}'.");
            }
            foreach ($projection->handles() as $eventType) {
                EventGrammar::assertEventType($eventType, 'handled event type');
            }
            $this->projections[$id] = $projection;
        }
    }

    /**
     * Run every projection sequentially (registration order).
     *
     * @param null|int $batchLimit per-projection cap on applied events
     *
     * @return int total events applied across all projections
     */
    public function run(?int $batchLimit = null): int
    {
        $applied = 0;
        foreach (array_keys($this->projections) as $id) {
            $applied += $this->runProjection($id, $batchLimit);
        }

        return $applied;
    }

    /**
     * Catch one projection up to the head of the timeline.
     *
     * @param string   $projectionId registered projection id
     * @param null|int $batchLimit   stop after this many events were APPLIED
     *                               (>= 1); null drains the whole backlog
     *
     * @return int number of events applied (matching, successfully handled)
     */
    public function runProjection(string $projectionId, ?int $batchLimit = null): int
    {
        $projection = $this->projections[$projectionId]
            ?? throw new EventSourcingException("Unknown projection '{$projectionId}'.");
        if ($batchLimit !== null && $batchLimit < 1) {
            throw new EventSourcingException("batchLimit must be >= 1 when provided (got {$batchLimit}).");
        }

        $handled = array_fill_keys($projection->handles(), true);
        $applied = 0;
        $from = ($this->checkpoints->get($projectionId) ?? 0) + 1;
        while (true) {
            $events = $this->store->streamAll($from, EventGrammar::MAX_PAGE);
            if ($events === []) {
                break;
            }
            if ($this->hasReachedBatchLimit($projection, $projectionId, $handled, $events, $batchLimit, $applied)) {
                return $applied;
            }
            $from = $events[count($events) - 1]->globalSequence + 1;
            if (count($events) < EventGrammar::MAX_PAGE) {
                break;
            }
        }

        return $applied;
    }

    /**
     * @return list<string> registered projection ids in registration order
     */
    public function projectionIds(): array
    {
        return array_keys($this->projections);
    }

    /**
     * Delivers one store page to the projection.
     *
     * Every observed event advances the checkpoint (matching or not: a
     * replay of already-observed events would be pure waste), applied
     * events count towards the optional batch limit.
     *
     * @param array<string, bool> $handled    event types the projection subscribes to
     * @param list<StoredEvent>   $events     page read from the store
     * @param null|int            $batchLimit stop after this many applied events
     *
     * @return bool true when the batch limit was reached and the run must stop
     */
    private function hasReachedBatchLimit(
        ProjectionInterface $projection,
        string $projectionId,
        array $handled,
        array $events,
        ?int $batchLimit,
        int &$applied,
    ): bool {
        foreach ($events as $event) {
            if (isset($handled[$event->eventType])) {
                $projection->handle($event);
                ++$applied;
            }
            // Checkpoint advances past non-matching events too: they
            // have been observed, replaying them would be waste.
            $this->checkpoints->set($projectionId, $event->globalSequence);
            if ($batchLimit !== null && $applied >= $batchLimit) {
                return true;
            }
        }

        return false;
    }
}
