<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Extracted from InMemoryOutbox so the store itself stays under its size
 * budget: every immutable OutboxEntry state transition lives here.
 */

namespace Zef\Framework\EventSourcing;

/**
 * Immutable state transitions of {@see OutboxEntry}.
 *
 * Every method returns a new entry with the named fields changed and the
 * rest copied verbatim (OutboxEntry is a readonly value object, so a
 * transition is always a full reconstruction).
 */
final class OutboxEntryTransitions
{
    /** Relay succeeded: the entry leaves the lifecycle as processed. */
    public static function processed(OutboxEntry $entry): OutboxEntry
    {
        return new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts,
            status: OutboxEntry::STATUS_PROCESSED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $entry->lastError,
            createdAtUnixNano: $entry->createdAtUnixNano,
        );
    }

    /** Relay failed but the retry budget is not exhausted: schedule a retry. */
    public static function retryScheduled(OutboxEntry $entry, string $error, int $retryAtUnixNano): OutboxEntry
    {
        return new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $retryAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        );
    }

    /** Retry budget exhausted: the entry becomes a dead letter. */
    public static function dead(OutboxEntry $entry, string $error): OutboxEntry
    {
        return new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: $entry->attempts + 1,
            status: OutboxEntry::STATUS_FAILED,
            nextAttemptAtUnixNano: $entry->nextAttemptAtUnixNano,
            lastError: $error,
            createdAtUnixNano: $entry->createdAtUnixNano,
        );
    }

    /** Ops requeue of a dead letter: back to pending with a fresh attempt budget. */
    public static function requeued(OutboxEntry $entry, int $nextAttemptAtUnixNano): OutboxEntry
    {
        return new OutboxEntry(
            id: $entry->id,
            messageType: $entry->messageType,
            payload: $entry->payload,
            metadata: $entry->metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $nextAttemptAtUnixNano,
            lastError: $entry->lastError,
            createdAtUnixNano: $entry->createdAtUnixNano,
        );
    }

    /** Claim-based relay took the entry: attach the lease pair. */
    public static function leased(OutboxEntry $entry, string $owner, int $leaseUntilUnixNano): OutboxEntry
    {
        return new OutboxEntry(
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
            leaseUntilUnixNano: $leaseUntilUnixNano,
        );
    }

    /** Lease ended (released by the owner): drop the lease pair. */
    public static function leaseReleased(OutboxEntry $entry): OutboxEntry
    {
        return new OutboxEntry(
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
    }
}
