<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.31.0: row hydration extracted from PdoOutbox so the store
 * facade stays under the maintainability size budgets (Sonar php:S2042).
 * Pure move: mapping behavior identical.
 */

namespace Zef\Framework\EventSourcing;

final class PdoOutboxHydrator
{
    /** Columns of the outbox table in canonical order (selects, DDL, probes). */
    public const array COLUMNS = [
        'id', 'message_type', 'payload', 'metadata', 'attempts', 'status',
        'next_attempt_at', 'last_error', 'created_at', 'lease_owner', 'lease_until',
    ];

    private function __construct() {}

    /**
     * @param array<string, mixed> $row
     * @param null|string          $claimedOwner         lease owner stamped on the row by claimBatch()'s bulk update
     * @param null|int             $claimedUntilUnixNano lease deadline stamped on the row by claimBatch()'s bulk update
     */
    public static function hydrate(
        array $row,
        ?string $claimedOwner = null,
        ?int $claimedUntilUnixNano = null,
    ): OutboxEntry {
        $lastError = $row['last_error'] ?? null;
        $leaseOwner = $claimedOwner ?? ($row['lease_owner'] ?? null);
        $leaseUntil = $claimedUntilUnixNano ?? ($row['lease_until'] ?? null);

        return new OutboxEntry(
            id: RowCast::string($row['id'] ?? null),
            messageType: RowCast::string($row['message_type'] ?? null),
            payload: EventJson::decode(RowCast::string($row['payload'] ?? null), 'outbox entry payload'),
            metadata: EventJson::decode(RowCast::string($row['metadata'] ?? null), 'outbox entry metadata'),
            attempts: RowCast::int($row['attempts'] ?? null),
            status: RowCast::string($row['status'] ?? null),
            nextAttemptAtUnixNano: RowCast::int($row['next_attempt_at'] ?? null),
            lastError: $lastError === null ? null : RowCast::string($lastError),
            createdAtUnixNano: RowCast::int($row['created_at'] ?? null),
            leaseOwner: $leaseOwner === null ? null : RowCast::string($leaseOwner),
            leaseUntilUnixNano: $leaseUntil === null ? null : RowCast::int($leaseUntil),
        );
    }
}
