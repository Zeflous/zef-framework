<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.23.0 (Event Sourcing Hardening: registry that chains
 * UpcasterInterface implementations per event type during replay).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Ordered registry of {@see UpcasterInterface} implementations, applied by
 * {@see AggregateRepository} while reconstituting aggregates.
 *
 * - Upcasters are chained per event type in registration order, and an
 *   upcaster may rename the event into a type that owns its own upcasters —
 *   the walk continues, so a legacy payload traverses the full migration
 *   path (v1→v2→v3); a rename cycle is rejected after {@see MAX_HOPS};
 *   precise semantics: ALL upcasters registered for the event's CURRENT
 *   type run to completion (in registration order) before the chain walks
 *   on — if an earlier upcaster in the same batch renames the event, the
 *   remaining same-type upcasters in that batch still run and see the
 *   reshaped event, so register ONE upcaster per schema step per type to
 *   stay type-exact;
 * - {@see transform()} is a pass-through for event types without any
 *   upcaster, so streams recorded in the current schema pay zero cost;
 * - identity guard: an upcaster may rename the event or reshape its
 *   payload/metadata, but the stream coordinates (event id, aggregate
 *   type/id, version, global sequence, recorded-at) are frozen — a
 *   violation throws {@see EventSourcingException} instead of silently
 *   corrupting the replay.
 */
final readonly class EventUpcaster
{
    /** Guard against an upcaster rename cycle (A→B→A...): max type hops per transform. */
    public const int MAX_HOPS = 16;

    /** @var array<string, list<UpcasterInterface>> */
    private array $byType;

    public function __construct(UpcasterInterface ...$upcasters)
    {
        $byType = [];
        foreach ($upcasters as $upcaster) {
            $types = $upcaster->eventTypes();
            if ($types === []) {
                throw new EventSourcingException('Upcaster ' . $upcaster::class . ' declares no event types.');
            }
            foreach ($types as $type) {
                EventGrammar::assertEventType($type, 'upcaster event type');
                $byType[$type][] = $upcaster;
            }
        }
        $this->byType = $byType;
    }

    /**
     * Run every upcaster registered for the event's current type, in
     * registration order — and keep walking: when an upcaster RENAMES the
     * event to a type that has its own upcasters, the chain continues from
     * there, so multi-step migrations (v1→v2→v3) compose naturally. Events
     * without registered upcasters pass through unchanged.
     */
    public function transform(StoredEvent $event): StoredEvent
    {
        $hops = 0;
        while (isset($this->byType[$event->eventType])) {
            ++$hops;
            if ($hops > self::MAX_HOPS) {
                throw new EventSourcingException(
                    'Upcaster chain exceeded ' . self::MAX_HOPS . " hops at '{$event->eventType}' (rename cycle?).",
                );
            }
            $typeBeforeBatch = $event->eventType;
            foreach ($this->byType[$event->eventType] as $upcaster) {
                $event = $this->apply($upcaster, $event);
            }
            // Audit #301: a batch that does NOT rename the event type is done
            // (payload/metadata-only upcasters are contract-legal — see
            // UpcasterInterface). Re-running the same batch would count up to
            // MAX_HOPS and then throw a false "rename cycle?" on replay.
            if ($event->eventType === $typeBeforeBatch) {
                break;
            }
        }

        return $event;
    }

    /**
     * @return list<string> event types that have at least one upcaster,
     *                      in first-registered order
     */
    public function registeredTypes(): array
    {
        return array_keys($this->byType);
    }

    private function apply(UpcasterInterface $upcaster, StoredEvent $event): StoredEvent
    {
        $upcast = $upcaster->upcast($event);
        $identityFrozen = $upcast->eventId === $event->eventId
            && $upcast->aggregateType === $event->aggregateType
            && $upcast->aggregateId === $event->aggregateId;
        $coordinatesFrozen = $upcast->version === $event->version
            && $upcast->globalSequence === $event->globalSequence
            && $upcast->recordedAtUnixNano === $event->recordedAtUnixNano;
        if (!$identityFrozen || !$coordinatesFrozen) {
            throw new EventSourcingException(
                'Upcaster ' . $upcaster::class . ' mutated the stream identity of event ' . $event->eventId . '.',
            );
        }

        return $upcast;
    }
}
