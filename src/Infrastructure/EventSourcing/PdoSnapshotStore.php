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
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\SqlState;

/**
 * PDO-backed {@see SnapshotStoreInterface} built on the Database Core port.
 *
 * `save()` is a portable upsert (DELETE + INSERT inside the surrounding
 * transaction) — no driver-specific ON CONFLICT syntax. Like the event
 * store it joins an ambient transaction when one is open.
 *
 * v2.23.0 hardening: `save()` checks the stored version first and refuses
 * to regress — a snapshot older than the persisted one is discarded, so a
 * slow writer can never clobber a newer snapshot saved concurrently.
 *
 * Regresi P-2 (issue #170): the guard alone only covers the snapshot that
 * was already visible when it ran. Between the guard and the write a
 * concurrent save can still commit a NEWER snapshot, which the previously
 * unconditional DELETE removed and the older INSERT replaced — a silent
 * regression. The DELETE is now version-bounded (never touches a newer
 * row) and a unique violation on the INSERT's identity constraint is
 * treated as the definitive lost-race signal: the version is re-checked
 * and a strictly newer snapshot wins silently.
 */
final readonly class PdoSnapshotStore implements SnapshotStoreInterface
{
    private string $table;

    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_snapshots',
    ) {
        $this->assertValidTable($table);
        $this->table = $table;
    }

    /**
     * Create the snapshots table (portable DDL, safe to run repeatedly).
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(implode('', [
            'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ("aggregate_type" VARCHAR(128) NOT NULL, ',
            '"aggregate_id" VARCHAR(128) NOT NULL, ',
            '"version" BIGINT NOT NULL, ',
            '"state" TEXT NOT NULL, ',
            '"created_at" BIGINT NOT NULL, ',
            'CONSTRAINT "uq_' . $this->table . '_identity" UNIQUE ("aggregate_type", "aggregate_id"))',
        ])));
    }

    #[\Override]
    public function save(Snapshot $snapshot): void
    {
        $own = $this->connection->transactionLevel() === 0;
        if (!$own) {
            $this->doSave($snapshot);

            return;
        }
        $this->connection->beginTransaction();

        try {
            $this->doSave($snapshot);
        } catch (\Throwable $error) {
            $this->connection->rollBack();

            throw $error;
        }
        $this->connection->commit();
    }

    #[\Override]
    public function load(string $aggregateType, string $aggregateId): ?Snapshot
    {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');

        $row = $this->connection->fetchOne(
            $this->selectQb()
                ->where('aggregate_type', '=', $aggregateType)
                ->where('aggregate_id', '=', $aggregateId)
                ->orderBy('version', 'DESC')
                ->limit(1)
                ->build(),
        );
        if ($row === null) {
            return null;
        }

        return new Snapshot(
            aggregateType: RowCast::string($row['aggregate_type'] ?? null),
            aggregateId: RowCast::string($row['aggregate_id'] ?? null),
            version: RowCast::int($row['version'] ?? null),
            state: EventJson::decode(RowCast::string($row['state'] ?? null), 'snapshot state'),
            createdAtUnixNano: RowCast::int($row['created_at'] ?? null),
        );
    }

    #[\Override]
    public function delete(string $aggregateType, string $aggregateId): bool
    {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');

        return $this->connection->execute(
            $this->deleteQb()
                ->where('aggregate_type', '=', $aggregateType)
                ->where('aggregate_id', '=', $aggregateId)
                ->build(),
        ) > 0;
    }

    /** Validates $table through the shared identifier grammar (throws on bad names). */
    private function assertValidTable(string $table): void
    {
        new QueryBuilder()->quoteIdentifier($table, 'table');
    }

    private function doSave(Snapshot $snapshot): void
    {
        $existingVersion = $this->storedVersion($snapshot);
        if ($existingVersion !== null && $existingVersion > $snapshot->version) {
            return; // a concurrent writer already saved a newer snapshot
        }

        // Regresi P-2 (issue #170): bound the DELETE to rows at or below the
        // version being saved — a snapshot committed by a concurrent writer
        // between the guard above and this write must survive it.
        $this->connection->execute(
            $this->deleteQb()
                ->where('aggregate_type', '=', $snapshot->aggregateType)
                ->where('aggregate_id', '=', $snapshot->aggregateId)
                ->where('version', '<=', $snapshot->version)
                ->build(),
        );

        try {
            $this->connection->execute(
                QueryBuilder::table($this->table)->insert([
                    'aggregate_type' => $snapshot->aggregateType,
                    'aggregate_id' => $snapshot->aggregateId,
                    'version' => $snapshot->version,
                    'state' => EventJson::encode($snapshot->state, 'Snapshot state'),
                    'created_at' => $snapshot->createdAtUnixNano,
                ])->build(),
            );
        } catch (QueryException $error) {
            if (!SqlState::isUniqueViolation($error)) {
                throw $error;
            }
            $existingVersion = $this->storedVersion($snapshot);
            if ($existingVersion === null || $existingVersion <= $snapshot->version) {
                throw $error;
            }

            return; // lost the race to a strictly newer snapshot — never move backwards
        }
    }

    /**
     * Version of the currently stored snapshot for $snapshot's identity
     * (null when the identity has no row yet).
     */
    private function storedVersion(Snapshot $snapshot): ?int
    {
        $row = $this->connection->fetchOne(
            $this->selectQb()
                ->where('aggregate_type', '=', $snapshot->aggregateType)
                ->where('aggregate_id', '=', $snapshot->aggregateId)
                ->build(),
        );

        return $row === null ? null : RowCast::int($row['version'] ?? null);
    }

    private function selectQb(): QueryBuilder
    {
        return QueryBuilder::table($this->table);
    }

    private function deleteQb(): QueryBuilder
    {
        return QueryBuilder::table($this->table)->delete();
    }
}
