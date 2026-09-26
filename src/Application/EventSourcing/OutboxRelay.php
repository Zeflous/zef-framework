<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Event\EventBusInterface;

/**
 * Relays due outbox entries onto the event bus and records the outcome.
 *
 * Two relay modes:
 * - {@see relay()} — single-worker pull over {@see OutboxStoreInterface::due()}.
 *   Every due entry is handed to THIS relay; correct only when exactly one
 *   relay process runs (N relays would each dispatch every entry).
 * - {@see relayLeased()} (v2.31.0) — concurrent-safe mode over
 *   {@see OutboxClaimInterface::claimBatch()}: each worker claims a batch
 *   under a short lease, so N relays share the backlog without double
 *   dispatch, and a crashed worker's entries become claimable again once
 *   its lease expires. Preferred mode for production workers.
 *
 * Per entry:
 * - dispatch succeeds → {@see OutboxStoreInterface::markProcessed()};
 * - dispatch throws   → attempts+1; below `maxAttempts` the entry stays
 *   pending with an exponential backoff (`base * 2^(attempt-1)`, capped),
 *   otherwise it is dead-lettered via {@see OutboxStoreInterface::markDead()}.
 *
 * Delivery is at-least-once: a listener crash after side effects but before
 * the commit replays the entry — consumers must be idempotent.
 */
final readonly class OutboxRelay
{
    public const int DEFAULT_MAX_ATTEMPTS = 5;
    public const int DEFAULT_BACKOFF_BASE_MS = 1_000;
    public const int DEFAULT_BACKOFF_CAP_MS = 60_000;
    public const int DEFAULT_LEASE_SECONDS = 30;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /** Claim token identifying THIS relay instance across claim/release. */
    private string $owner;

    /**
     * @param OutboxStoreInterface  $outbox        outbox port
     * @param EventBusInterface     $bus           target bus (OutboxMessage dispatch)
     * @param null|(\Closure(): int) $clock         now source in nanoseconds (default: realtime)
     * @param int                   $maxAttempts   >= 1 — dispatch tries before dead-lettering
     * @param int                   $backoffBaseMs >= 1 — first retry delay
     * @param int                   $backoffCapMs  >= backoffBaseMs — retry delay ceiling
     */
    public function __construct(
        private OutboxStoreInterface $outbox,
        private EventBusInterface $bus,
        ?\Closure $clock = null,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private int $backoffBaseMs = self::DEFAULT_BACKOFF_BASE_MS,
        private int $backoffCapMs = self::DEFAULT_BACKOFF_CAP_MS,
    ) {
        if ($maxAttempts < 1) {
            throw new EventSourcingException("maxAttempts must be >= 1 (got {$maxAttempts}).");
        }
        if ($backoffBaseMs < 1) {
            throw new EventSourcingException("backoffBaseMs must be >= 1 (got {$backoffBaseMs}).");
        }
        if ($backoffCapMs < $backoffBaseMs) {
            throw new EventSourcingException(
                "backoffCapMs ({$backoffCapMs}) must be >= backoffBaseMs ({$backoffBaseMs}).",
            );
        }
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
        $this->owner = bin2hex(random_bytes(8));
    }

    /**
     * Claim-based relay for CONCURRENT workers (v2.31.0): claims up to $limit
     * due entries under a $leaseSeconds lease, dispatches them, and records
     * the outcome (processed / retry with backoff / dead letter) exactly like
     * {@see relay()}. Other relay instances running against the same store
     * never see entries this instance still holds under an active lease.
     *
     * The lease is cleared implicitly by every mark* transition. A worker
     * that dies mid-batch leaves its entries leased until the deadline;
     * they become claimable again after expiry (at-least-once preserved).
     * Call {@see releaseLease()} on graceful shutdown to hand unfinished
     * entries back immediately.
     *
     * @param int        $limit        >= 1 — maximum entries to claim and dispatch
     * @param int        $leaseSeconds >= 1 — lease window; must comfortably exceed
     *                                 the expected batch dispatch time
     * @param null|string $owner       claim token override (default: this relay's
     *                                 instance token generated at construction)
     *
     * @return int number of entries successfully dispatched and marked processed
     *
     * @throws EventSourcingException when the store does not implement
     *                                {@see OutboxClaimInterface} or arguments are invalid
     */
    public function relayLeased(int $limit = 100, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS, ?string $owner = null): int
    {
        if (!$this->outbox instanceof OutboxClaimInterface) {
            throw new EventSourcingException(
                'relayLeased() requires an outbox store implementing OutboxClaimInterface ('
                . $this->outbox::class . ' does not).',
            );
        }
        if ($limit < 1) {
            throw new EventSourcingException("relayLeased() limit must be >= 1 (got {$limit}).");
        }
        if ($leaseSeconds < 1) {
            throw new EventSourcingException("relayLeased() leaseSeconds must be >= 1 (got {$leaseSeconds}).");
        }
        $claimed = $this->outbox->claimBatch($owner ?? $this->owner, $limit, $leaseSeconds);
        $processed = 0;
        $now = ($this->clock)();
        foreach ($claimed as $entry) {
            try {
                $this->bus->dispatch(new OutboxMessage($entry));
            } catch (\Throwable $error) {
                $this->recordFailure($entry, $error, $now);

                continue;
            }
            $this->outbox->markProcessed($entry->id);
            ++$processed;
        }

        return $processed;
    }

    /**
     * Release the leases held by $owner (default: this relay's token) —
     * graceful-shutdown helper so a stopping worker hands its unfinished
     * entries straight back to the pool instead of letting the lease run out.
     *
     * @return int number of leases released
     *
     * @throws EventSourcingException when the store does not implement {@see OutboxClaimInterface}
     */
    public function releaseLease(?string $owner = null): int
    {
        if (!$this->outbox instanceof OutboxClaimInterface) {
            throw new EventSourcingException(
                'releaseLease() requires an outbox store implementing OutboxClaimInterface ('
                . $this->outbox::class . ' does not).',
            );
        }

        return $this->outbox->releaseLease($owner ?? $this->owner);
    }

    /**
     * This relay instance's claim token (stable across its lifetime).
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Relay up to $limit due entries.
     *
     * @param int $limit >= 1
     *
     * @return int number of entries successfully dispatched and marked processed
     */
    public function relay(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new EventSourcingException("relay() limit must be >= 1 (got {$limit}).");
        }
        $now = ($this->clock)();
        $entries = $this->outbox->due($limit, $now);
        $processed = 0;
        foreach ($entries as $entry) {
            try {
                $this->bus->dispatch(new OutboxMessage($entry));
            } catch (\Throwable $error) {
                $this->recordFailure($entry, $error, $now);

                continue;
            }
            $this->outbox->markProcessed($entry->id);
            ++$processed;
        }

        return $processed;
    }

    /**
     * Dead letters (status failed), most urgent first.
     *
     * @return list<OutboxEntry>
     */
    public function deadLetters(int $limit = 100): array
    {
        return $this->outbox->failed($limit);
    }

    /**
     * Give dead letters a fresh retry budget (v2.23.0): status back to
     * pending, attempts reset to 0, eligible on the next {@see relay()}.
     * Use after fixing the root cause; the original error stays visible on
     * each entry until its next dispatch attempt.
     *
     * Requeued entries are STAGGERED one millisecond apart (per position in
     * the batch) so a requeued herd re-enters the downstream spread out
     * instead of all at once.
     *
     * @param int $limit >= 1
     *
     * @return int number of entries requeued
     */
    public function requeueDeadLetters(int $limit = 100): int
    {
        if ($limit < 1) {
            throw new EventSourcingException("requeueDeadLetters() limit must be >= 1 (got {$limit}).");
        }
        $now = ($this->clock)();
        $requeued = 0;
        foreach ($this->outbox->failed($limit) as $entry) {
            $this->outbox->requeue($entry->id, $now + $requeued * 1_000_000);
            ++$requeued;
        }

        return $requeued;
    }

    /**
     * Exponential backoff for the 1-based attempt number:
     * `base * 2^(attempt-1)`, capped at backoffCapMs.
     */
    public function retryDelayMs(int $attempt): int
    {
        if ($attempt < 1) {
            throw new EventSourcingException("attempt must be >= 1 (got {$attempt}).");
        }
        $delay = $this->backoffBaseMs;
        for ($i = 1; $i < $attempt && $delay < $this->backoffCapMs; ++$i) {
            $delay *= 2;
            if ($delay >= $this->backoffCapMs) {
                return $this->backoffCapMs;
            }
        }

        return min($delay, $this->backoffCapMs);
    }

    private function recordFailure(OutboxEntry $entry, \Throwable $error, int $now): void
    {
        $message = $error->getMessage();
        if ($message === '') {
            $message = $error::class;
        }
        $attempts = $entry->attempts + 1;
        if ($attempts >= $this->maxAttempts) {
            $this->outbox->markDead($entry->id, $message);

            return;
        }
        $this->outbox->markFailed($entry->id, $message, $now + $this->retryDelayMs($attempts) * 1_000_000);
    }
}
