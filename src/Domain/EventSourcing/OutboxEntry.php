<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * One queued message in the transactional outbox.
 *
 * Lifecycle: `pending` → (relayed) → `processed`, or `pending` →
 * (attempts exhausted) → `failed` (dead letter). `attempts` counts relay
 * failures recorded by the store; `nextAttemptAtUnixNano` gates retry
 * eligibility (exponential backoff computed by the relay).
 *
 * Lease metadata (v2.31.0): while a claim-based relay processes an entry
 * it is `pending` AND leased to a claim token until `leaseUntilUnixNano`.
 * An active lease hides the entry from other relays' {@see OutboxClaimInterface::claimBatch()}
 * calls; every mark* transition clears it, and an expired lease is
 * reclaimable (crashed-relay recovery). Both fields are set or both are
 * null — partial lease metadata is rejected.
 */
final readonly class OutboxEntry
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSED = 'processed';
    public const string STATUS_FAILED = 'failed';

    private const array STATUSES = [
        self::STATUS_PENDING => true,
        self::STATUS_PROCESSED => true,
        self::STATUS_FAILED => true,
    ];

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $messageType,
        public array $payload,
        public array $metadata,
        public int $attempts,
        public string $status,
        public int $nextAttemptAtUnixNano,
        public ?string $lastError,
        public int $createdAtUnixNano,
        public ?string $leaseOwner = null,
        public ?int $leaseUntilUnixNano = null,
    ) {
        EventGrammar::assertEventId($id, 'outbox entry id');
        EventGrammar::assertEventType($messageType, 'outbox message type');
        EventGrammar::assertPayload($payload, 'Outbox entry payload');
        EventGrammar::assertPayload($metadata, 'Outbox entry metadata');
        if ($attempts < 0) {
            throw new EventSourcingException("Outbox entry attempts must be >= 0 (got {$attempts}).");
        }
        if (!isset(self::STATUSES[$status])) {
            throw new EventSourcingException(
                "Invalid outbox status '{$status}' (allowed: pending, processed, failed).",
            );
        }
        EventGrammar::assertUnixNano($nextAttemptAtUnixNano, 'nextAttemptAtUnixNano');
        EventGrammar::assertUnixNano($createdAtUnixNano, 'createdAtUnixNano');
        if (($leaseOwner === null) !== ($leaseUntilUnixNano === null)) {
            throw new EventSourcingException(
                'Outbox lease metadata must be set as a pair (leaseOwner + leaseUntilUnixNano), got exactly one.',
            );
        }
        if ($leaseOwner !== null && ($leaseOwner === '' || \strlen($leaseOwner) > 64)) {
            throw new EventSourcingException('Outbox lease owner must be 1..64 chars.');
        }
        if ($leaseUntilUnixNano !== null) {
            EventGrammar::assertUnixNano($leaseUntilUnixNano, 'leaseUntilUnixNano');
        }
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * True when the entry carries a complete lease pair (owner + deadline).
     * Note: a lease may be present yet already EXPIRED — use
     * {@see hasActiveLease()} to ask whether other relays must skip it.
     */
    public function isLeased(): bool
    {
        return $this->leaseOwner !== null && $this->leaseUntilUnixNano !== null;
    }

    /**
     * True when the entry is leased to a claim token whose lease has not
     * expired at $nowUnixNano — other relays must skip it.
     */
    public function hasActiveLease(int $nowUnixNano): bool
    {
        return $this->isLeased() && $this->leaseUntilUnixNano > $nowUnixNano;
    }
}
