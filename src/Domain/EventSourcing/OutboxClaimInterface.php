<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.31.0 (Outbox lease claiming: safe concurrent relay without
 * double dispatch — multiple relay processes coordinate through short
 * leases instead of racing over due()).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Lease-claim extension for {@see OutboxStoreInterface} implementations.
 *
 * Pulling {@see OutboxStoreInterface::due()} from N concurrent relay
 * processes hands every entry to all N relays (at-least-once degenerates
 * to N-times delivery). Claiming fixes this: {@see claimBatch()} atomically
 * moves up to $limit due entries into the caller's custody ($owner) for a
 * bounded window ($leaseSeconds). Other relays skip entries whose lease is
 * still active and reclaim only entries whose lease has expired — a crashed
 * relay's backlog self-heals once its lease lapses.
 *
 * Delivery remains at-least-once: a relay that dies AFTER dispatch but
 * BEFORE markProcessed replays the entry as soon as its lease expires.
 * Consumers must stay idempotent, exactly as with due().
 */
interface OutboxClaimInterface
{
    /**
     * Atomically claim up to $limit due entries for $owner.
     *
     * An entry is claimable when it is `pending`, its `nextAttemptAt` has
     * passed, and it carries no active lease (never leased, or leased with
     * `leaseUntil <= now`). Claimed entries keep status `pending` and gain
     * lease metadata; every mark* transition clears the lease.
     *
     * @param string   $owner        claim token (1..64 chars, unique per relay process)
     * @param int      $limit        >= 1 — maximum number of entries to claim
     * @param int      $leaseSeconds >= 1 — how long the claim holds
     * @param null|int $nowUnixNano  when null, the adapter's clock decides
     *
     * @return list<OutboxEntry> claimed entries, ordered by (createdAt, id) ascending
     */
    public function claimBatch(string $owner, int $limit, int $leaseSeconds, ?int $nowUnixNano = null): array;

    /**
     * Release every lease currently held by $owner — graceful-shutdown
     * helper: call before a relay worker exits so another worker can pick
     * the claimed entries up immediately instead of waiting out the lease.
     *
     * @param string $owner claim token previously passed to {@see claimBatch()}
     *
     * @return int number of leases released
     */
    public function releaseLease(string $owner): int;
}
