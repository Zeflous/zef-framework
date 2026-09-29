<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.31.0: lease-claim transaction extracted from PdoOutbox so the
 * store facade stays under the maintainability size budgets (Sonar
 * php:S2042). Pure move: claiming behavior identical.
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlQuery;

/**
 * One-transaction lease claim for concurrent outbox relays: lock the
 * candidates (FOR UPDATE family), stamp the whole batch with ONE bulk
 * UPDATE, and hydrate the entries straight from the locked rows — no
 * per-row UPDATEs, no post-commit re-fetch loop, and therefore no
 * read-after-commit window either (Kilo review, PR #178: 2N+1
 * round-trips for a batch of N became 2).
 */
final readonly class PdoOutboxClaimer
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
        private string $quotedTable,
    ) {}

    /**
     * @return list<OutboxEntry> claimed entries, unordered (caller sorts)
     */
    public function claim(string $owner, int $limit, int $now, int $leaseUntil): array
    {
        $claim = function () use ($owner, $limit, $now, $leaseUntil): array {
            $rows = $this->connection->fetchAll($this->candidatesQuery($now, $limit));
            $ids = [];
            $candidates = [];
            foreach ($rows as $row) {
                $id = RowCast::string($row['id'] ?? null);
                if ($id === '') {
                    continue;
                }
                $ids[] = $id;
                $candidates[] = $row;
            }
            if ($ids === []) {
                return [];
            }
            $this->connection->execute(
                QueryBuilder::table($this->table)
                    ->update(['lease_owner' => $owner, 'lease_until' => $leaseUntil])
                    ->whereIn('id', $ids)
                    ->build(),
            );

            return array_map(
                fn (array $row): OutboxEntry => PdoOutboxHydrator::hydrate($row, $owner, $leaseUntil),
                $candidates,
            );
        };

        return $this->connection->transaction($claim);
    }

    /**
     * Candidate select for claimBatch(): claimable = pending + due + not
     * actively leased. Selects EVERY outbox column so claim() hydrates
     * the claimed entries directly from the row-locked candidates — no
     * re-fetch pass is needed. Integers are pre-validated; the table name is
     * quoted at construction. Appends the strongest row-lock suffix the
     * platform accepts (auto-detected once per connection).
     */
    private function candidatesQuery(int $now, int $limit): SqlQuery
    {
        $columns = implode(', ', array_map(
            static fn (string $column): string => '"' . $column . '"',
            PdoOutboxHydrator::COLUMNS,
        ));
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->quotedTable
            . " WHERE \"status\" = '" . OutboxEntry::STATUS_PENDING
            . "' AND \"next_attempt_at\" <= " . $now
            . ' AND ("lease_until" IS NULL OR "lease_until" <= ' . $now
            . ') ORDER BY "created_at" ASC, "id" ASC LIMIT ' . $limit
            . (new PdoOutboxLockSuffix($this->connection, $this->quotedTable))->suffix();

        return SqlQuery::raw($sql);
    }
}
