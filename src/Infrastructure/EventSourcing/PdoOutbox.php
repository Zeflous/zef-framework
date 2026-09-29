<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlExpression;

/**
 * PDO-backed {@see OutboxStoreInterface} built on the Database Core port.
 *
 * - `due()`/`failed()`/`countPending()`/`requeue()` delegate to
 *   {@see PdoOutboxReader} (FIFO by (created_at, id));
 * - `markFailed()` bumps attempts with `attempts + 1` expressed as a raw
 *   {@see SqlExpression} (read-then-write would race under concurrent relays);
 * - shares the ambient-transaction contract with PdoEventStore: enqueue
 *   joins an open transaction, so repository.persist commits events and
 *   outbox entries atomically over one connection;
 * - `claimBatch()` (lease claiming, v2.31.0) implements {@see
 *   OutboxClaimInterface} for concurrent relays and delegates to {@see
 *   PdoOutboxClaimer}: one transaction takes row locks with `FOR UPDATE
 *   SKIP LOCKED` when the platform supports it (auto-detected once per
 *   connection by {@see PdoOutboxLockSuffix}, with graceful degradation to
 *   plain `FOR UPDATE` and finally to lock-free selects on SQLite) and
 *   stamps lease metadata on the claimed rows. Expired leases are
 *   reclaimable, so a crashed relay's backlog self-heals;
 * - `createSchema()` delegates to {@see PdoOutboxSchema}, which also
 *   maintains a `(status, next_attempt_at, created_at)` relay index and
 *   upgrades legacy tables that predate the lease columns.
 */
final readonly class PdoOutbox implements OutboxStoreInterface, OutboxClaimInterface
{
    private string $table;

    private string $quotedTable;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port (shared connection enables atomic persist)
     * @param string                 $table      outbox table name
     * @param null|(\Closure(): int) $clock      createdAt/now source (default: realtime nanoseconds)
     */
    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_outbox',
        ?\Closure $clock = null,
    ) {
        $this->table = $table;
        $this->quotedTable = new QueryBuilder()->quoteIdentifier($table, 'table');
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Create the outbox table (portable DDL, safe to run repeatedly), plus
     * the relay index and the legacy-table lease-column upgrade.
     */
    public function createSchema(): void
    {
        (new PdoOutboxSchema($this->connection, $this->table, $this->quotedTable))->create();
    }

    #[\Override]
    public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
    {
        $now = ($this->clock)();
        $entry = new OutboxEntry(
            id: bin2hex(random_bytes(16)),
            messageType: $messageType,
            payload: $payload,
            metadata: $metadata,
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: $now,
            lastError: null,
            createdAtUnixNano: $now,
        );
        $this->connection->execute(
            QueryBuilder::table($this->table)->insert([
                'id' => $entry->id,
                'message_type' => $entry->messageType,
                'payload' => EventJson::encode($entry->payload, 'Outbox entry payload'),
                'metadata' => EventJson::encode($entry->metadata, 'Outbox entry metadata'),
                'status' => $entry->status,
                'attempts' => $entry->attempts,
                'next_attempt_at' => $entry->nextAttemptAtUnixNano,
                'last_error' => $entry->lastError,
                'created_at' => $entry->createdAtUnixNano,
            ])->build(),
        );

        return $entry;
    }

    #[\Override]
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        return $this->reader()->due($limit, $nowUnixNano);
    }

    #[\Override]
    public function markProcessed(string $id): void
    {
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PROCESSED,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
    }

    #[\Override]
    public function markFailed(string $id, string $error, int $retryAtUnixNano): void
    {
        EventGrammar::assertUnixNano($retryAtUnixNano, 'retryAtUnixNano');
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PENDING,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'next_attempt_at' => $retryAtUnixNano,
            'last_error' => $error,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
    }

    #[\Override]
    public function markDead(string $id, string $error): void
    {
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_FAILED,
            'attempts' => new SqlExpression('"attempts" + 1'),
            'last_error' => $error,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
    }

    #[\Override]
    public function failed(int $limit): array
    {
        return $this->reader()->failed($limit);
    }

    #[\Override]
    public function countPending(): int
    {
        return $this->reader()->countPending();
    }

    #[\Override]
    public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
    {
        return $this->reader()->requeue($id, $nextAttemptAtUnixNano);
    }

    // -------------------------------------------------- lease claiming (v2.31.0)

    #[\Override]
    public function claimBatch(string $owner, int $limit, int $leaseSeconds, ?int $nowUnixNano = null): array
    {
        if ($owner === '' || \strlen($owner) > 64) {
            throw new EventSourcingException('claimBatch() owner must be 1..64 chars.');
        }
        if ($limit < 1) {
            throw new EventSourcingException("claimBatch() limit must be >= 1 (got {$limit}).");
        }
        if ($leaseSeconds < 1) {
            throw new EventSourcingException("claimBatch() leaseSeconds must be >= 1 (got {$leaseSeconds}).");
        }
        $now = $nowUnixNano ?? ($this->clock)();
        $leaseUntil = $now + $leaseSeconds * 1_000_000_000;
        EventGrammar::assertUnixNano($leaseUntil, 'lease deadline');

        $entries = (new PdoOutboxClaimer($this->connection, $this->table, $this->quotedTable))
            ->claim($owner, $limit, $now, $leaseUntil);
        usort($entries, self::compareFifo(...));

        return $entries;
    }

    #[\Override]
    public function releaseLease(string $owner): int
    {
        if ($owner === '') {
            throw new EventSourcingException('releaseLease() owner must be non-empty.');
        }

        return $this->connection->execute(
            QueryBuilder::table($this->table)
                ->update(['lease_owner' => null, 'lease_until' => null])
                ->where('lease_owner', '=', $owner)
                ->build(),
        );
    }

    private function reader(): PdoOutboxReader
    {
        return new PdoOutboxReader($this->connection, $this->table, $this->clock);
    }

    /** FIFO order used by claimBatch(): (createdAt, id), both ascending. */
    private static function compareFifo(OutboxEntry $a, OutboxEntry $b): int
    {
        return [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id];
    }

    /**
     * @param array<string, mixed> $pairs
     */
    private function updateById(string $id, array $pairs): void
    {
        $affected = $this->connection->execute(
            QueryBuilder::table($this->table)
                ->update($pairs)
                ->where('id', '=', $id)
                ->build(),
        );
        if ($affected === 0) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }
    }
}
