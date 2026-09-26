<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\DatabaseException;

/**
 * Repository base for event-sourced aggregates: load (snapshot + replay),
 * persist (append + optional snapshot + optional outbox enqueue).
 *
 * Atomicity: when a {@see ConnectionInterface} is provided AND the event
 * store / outbox store are backed by that same connection (the PDO
 * adapters), the whole persist runs inside one transaction — events,
 * snapshots and outbox entries commit together or not at all. Without a
 * connection each store call is its own unit of work; pending events are
 * cleared after append succeeds, even if a later outbox/snapshot call fails.
 * In that mode the caller must not wrap the stores in an ambient transaction.
 *
 * With a connection, persist must own the outermost transaction: ambient
 * transactions are rejected before writing because releasing a savepoint
 * does not confirm a commit. Pending events are cleared only after the
 * transaction commits, so a rollback leaves the same aggregate retryable.
 *
 * Concurrency: {@see persist()} uses the aggregate's `pendingVersion()` as
 * the expected version; a concurrent writer surfaces as
 * {@see ConcurrencyException} and the caller should reload and retry.
 *
 * Upcasting: when an {@see EventUpcaster} registry is provided, stored
 * events are transformed to their current schema shape during reconstitution
 * (v2.23.0) — old streams replay against current code without a rewrite.
 */
class AggregateRepository
{
    private readonly SnapshotPolicy $policy;

    /** @var (\Closure(): int) */
    private readonly \Closure $clock;

    /**
     * @param EventStoreInterface         $store      stream port
     * @param null|SnapshotStoreInterface $snapshots  snapshot port (optional)
     * @param null|SnapshotPolicy         $policy     snapshot cadence (default: every 100 versions)
     * @param null|OutboxRecorder         $outbox     transactional outbox recorder (optional)
     * @param null|ConnectionInterface    $connection shared connection used to wrap persist in one transaction
     * @param null|(\Closure(): int)      $clock      snapshot timestamp source (default: realtime nanoseconds)
     * @param null|EventUpcaster          $upcasters  event schema-evolution registry applied during replay (optional)
     */
    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly ?SnapshotStoreInterface $snapshots = null,
        ?SnapshotPolicy $policy = null,
        private readonly ?OutboxRecorder $outbox = null,
        private readonly ?ConnectionInterface $connection = null,
        ?\Closure $clock = null,
        private readonly ?EventUpcaster $upcasters = null,
    ) {
        if ($policy instanceof SnapshotPolicy && !$this->snapshots instanceof SnapshotStoreInterface) {
            throw new EventSourcingException('SnapshotPolicy provided without a SnapshotStore.');
        }
        $this->policy = $policy ?? SnapshotPolicy::default();
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Commit every pending event of the aggregate.
     *
     * @return list<StoredEvent> committed events; empty when the aggregate had nothing pending
     *
     * @throws EventSourcingException when pending events would join an ambient transaction
     * @throws DatabaseException when a database operation or commit fails
     */
    public function persist(AggregateRoot $aggregate): array
    {
        if (!$aggregate->hasPendingEvents()) {
            return [];
        }
        if ($this->connection instanceof ConnectionInterface) {
            if ($this->connection->transactionLevel() > 0) {
                throw new EventSourcingException('Aggregate persist must own the outermost transaction.');
            }
            $stored = $this->connection->transaction(fn (): array => $this->doPersist($aggregate));
            $aggregate->markCommitted();

            return $stored;
        }

        return $this->doPersist($aggregate);
    }

    /**
     * Load an aggregate by replaying its stream (optionally seeded from a
     * snapshot). Returns null when neither snapshot nor events exist.
     *
     * @template T of AggregateRoot
     *
     * @param class-string<T> $aggregateClass
     *
     * @return null|T
     */
    public function find(string $aggregateClass, string $aggregateId): ?AggregateRoot
    {
        if (!is_subclass_of($aggregateClass, AggregateRoot::class)) {
            throw new EventSourcingException("{$aggregateClass} is not an AggregateRoot subclass.");
        }
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        $type = $aggregateClass::aggregateType();

        $snapshot = $this->snapshots?->load($type, $aggregateId);
        if ($snapshot instanceof Snapshot) {
            $aggregate = $aggregateClass::restoreFromSnapshot($snapshot->state, $snapshot->version);
            $this->replayAfter($aggregate, $type, $aggregateId, $snapshot->version);

            return $aggregate;
        }

        $events = $this->store->loadStream($type, $aggregateId);
        if ($events === []) {
            return null;
        }
        $aggregate = $aggregateClass::createEmpty($aggregateId);
        foreach ($events as $event) {
            $aggregate->applyStored($this->upcast($event));
        }

        return $aggregate;
    }

    /**
     * {@see find()} but raising {@see AggregateNotFoundException} instead
     * of returning null.
     *
     * @template T of AggregateRoot
     *
     * @param class-string<T> $aggregateClass
     *
     * @return T
     */
    public function findOrFail(string $aggregateClass, string $aggregateId): AggregateRoot
    {
        return $this->find($aggregateClass, $aggregateId)
            ?? throw new AggregateNotFoundException($aggregateClass, $aggregateId);
    }

    /** @return list<StoredEvent> */
    private function doPersist(AggregateRoot $aggregate): array
    {
        $pending = $aggregate->pendingEvents();
        $stored = $this->store->appendToStream(
            $aggregate::aggregateType(),
            $aggregate->aggregateId(),
            $aggregate->pendingVersion(),
            ...$pending,
        );
        if (!$this->connection instanceof ConnectionInterface) {
            // No shared transaction: the append is already durable.
            $aggregate->markCommitted();
        }

        if ($this->outbox instanceof OutboxRecorder) {
            $this->outbox->record($stored);
        }

        $version = $aggregate->version();
        if ($this->snapshots instanceof SnapshotStoreInterface && $this->policy->shouldSnapshot($version)) {
            $this->snapshots->save(new Snapshot(
                $aggregate::aggregateType(),
                $aggregate->aggregateId(),
                $version,
                $aggregate->snapshotState(),
                ($this->clock)(),
            ));
        }

        return $stored;
    }

    private function replayAfter(AggregateRoot $aggregate, string $type, string $aggregateId, int $afterVersion): void
    {
        foreach ($this->store->loadStream($type, $aggregateId) as $event) {
            if ($event->version <= $afterVersion) {
                continue;
            }
            $aggregate->applyStored($this->upcast($event));
        }
    }

    /**
     * Stored events are replayed in their CURRENT schema shape: events
     * without registered upcasters pass through untouched, legacy ones walk
     * the upcaster chain (v2.23.0).
     */
    private function upcast(StoredEvent $event): StoredEvent
    {
        return $this->upcasters instanceof EventUpcaster
            ? $this->upcasters->transform($event)
            : $event;
    }
}
