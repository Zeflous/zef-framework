<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.31.0: entry reads and the requeue transition extracted from
 * PdoOutbox so the store facade stays under the maintainability size
 * budgets (Sonar php:S2042). Pure move: queries, ordering and exception
 * behavior identical.
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;

/**
 * Entry-level reads against the outbox table (due, failed, pending count)
 * plus the failed -> pending requeue transition, FIFO by (created_at, id).
 */
final readonly class PdoOutboxReader
{
    /** @var (\Closure(): int) */
    private \Closure $clock;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /** @return list<OutboxEntry> */
    public function due(int $limit, ?int $nowUnixNano = null): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("due() limit must be >= 1 (got {$limit}).");
        }

        return array_map(
            PdoOutboxHydrator::hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->where('next_attempt_at', '<=', $nowUnixNano ?? ($this->clock)())
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    /** @return list<OutboxEntry> */
    public function failed(int $limit): array
    {
        if ($limit < 1) {
            throw new EventSourcingException("failed() limit must be >= 1 (got {$limit}).");
        }

        return array_map(
            PdoOutboxHydrator::hydrate(...),
            $this->connection->fetchAll($this->selectQb()
                ->where('status', '=', OutboxEntry::STATUS_FAILED)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->build()),
        );
    }

    public function countPending(): int
    {
        $row = $this->connection->fetchOne(
            QueryBuilder::table($this->table)
                ->aggregate('COUNT', '*')
                ->where('status', '=', OutboxEntry::STATUS_PENDING)
                ->build(),
        );
        $value = $row['aggregate'] ?? null;
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new EventSourcingException('countPending() returned no aggregate value.');
        }

        return RowCast::int($value);
    }

    public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
    {
        $now = $nextAttemptAtUnixNano ?? ($this->clock)();
        EventGrammar::assertUnixNano($now, 'nextAttemptAtUnixNano');
        $row = $this->connection->fetchOne(
            $this->selectQb()->where('id', '=', $id)->build(),
        );
        if ($row === null) {
            throw new EventSourcingException("Unknown outbox entry '{$id}'.");
        }
        $status = RowCast::string($row['status'] ?? null);
        if ($status !== OutboxEntry::STATUS_FAILED) {
            throw new EventSourcingException(
                "Only failed entries can be requeued (entry '{$id}' is '{$status}').",
            );
        }
        $this->updateById($id, [
            'status' => OutboxEntry::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'lease_owner' => null,
            'lease_until' => null,
        ]);
        $updated = $this->connection->fetchOne(
            $this->selectQb()->where('id', '=', $id)->build(),
        );
        if ($updated === null) {
            throw new EventSourcingException("Outbox entry '{$id}' vanished during requeue.");
        }

        return PdoOutboxHydrator::hydrate($updated);
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

    private function selectQb(): QueryBuilder
    {
        return QueryBuilder::table($this->table)->select(...PdoOutboxHydrator::COLUMNS);
    }
}
