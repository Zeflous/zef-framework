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
 * PDO-backed {@see EventStoreInterface} built on the Database Core port.
 *
 * Concurrency: the append verifies the stream's last version inside the
 * surrounding transaction and aborts with {@see ConcurrencyException}
 * before writing anything — the UNIQUE(aggregate_type, aggregate_id,
 * version) index in {@see createSchema()} is the database-level backstop.
 * When the guard passes but a concurrent append still wins one of the
 * store's unique constraints (a repeatable-read snapshot of the stream, a
 * racing MAX(global_sequence) computation), the loser's driver error is
 * mapped onto the same {@see ConcurrencyException} instead of a raw
 * QueryException (Regresi P-3, issue #170) — catch-based retry logic
 * never misses a real conflict, and the driver error stays chained as
 * the exception's cause.
 *
 * Global sequence: `MAX(global_sequence) + 1` computed inside the same
 * transaction — portable across MySQL/SQLite/PostgreSQL without relying
 * on driver-specific auto-increment semantics for multi-row inserts.
 *
 * Transactions: when the connection already has an open transaction
 * (ambient mode, e.g. driven by AggregateRepository) the adapter joins it;
 * standalone calls open and commit their own. This is what makes event
 * appends and outbox enqueues atomic when both share one connection.
 *
 * `createSchema()` issues portable DDL (TEXT columns, no driver-specific
 * index syntax); production tuning belongs in user migrations.
 *
 * v2.23.0 hardening: the schema carries two store-wide backstops —
 * UNIQUE(event_id) and UNIQUE(global_sequence). The in-transaction
 * `MAX(global_sequence) + 1` computation can race under a database's
 * repeatable-read snapshot; without the unique index the loser would
 * silently double-assign the projection currency, breaking checkpointed
 * consumers. With it, the loser fails loudly and the caller retries.
 * Deployments created before v2.23.0 should add both indexes manually
 * (see CHANGELOG-v2.23.0.md) — CREATE TABLE IF NOT EXISTS does not alter
 * existing tables.
 */
final readonly class PdoEventStore implements EventStoreInterface
{
    /** DDL prefix shared by the three store-wide unique constraints. */
    private const UNIQUE_CONSTRAINT_PREFIX = 'CONSTRAINT "uq_';

    /** Every stored-event column, in storage order. */
    private const STORED_EVENT_COLUMNS = [
        'global_sequence',
        'event_id',
        'aggregate_type',
        'aggregate_id',
        'version',
        'event_type',
        'payload',
        'metadata',
        'recorded_at',
    ];

    private string $table;

    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param ConnectionInterface    $connection database port (shared connection enables atomic persist)
     * @param string                 $table      events table name
     * @param null|(\Closure(): int) $clock      recordedAt source (default: realtime nanoseconds)
     */
    public function __construct(
        private ConnectionInterface $connection,
        string $table = 'zef_events',
        ?\Closure $clock = null,
    ) {
        self::assertValidTableName($table);
        $this->table = $table;
        $this->clock = $clock ?? static fn (): int => (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Create the events table (portable DDL, safe to run repeatedly).
     */
    public function createSchema(): void
    {
        $this->connection->execute(SqlQuery::raw(
            'CREATE TABLE IF NOT EXISTS "' . $this->table . '" ("global_sequence" BIGINT NOT NULL, '
            . '"event_id" VARCHAR(64) NOT NULL, '
            . '"aggregate_type" VARCHAR(128) NOT NULL, '
            . '"aggregate_id" VARCHAR(128) NOT NULL, '
            . '"version" BIGINT NOT NULL, '
            . '"event_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"metadata" TEXT NOT NULL, '
            . '"recorded_at" BIGINT NOT NULL, '
            . self::UNIQUE_CONSTRAINT_PREFIX . $this->table . '_stream" UNIQUE ("aggregate_type", "aggregate_id", "version"), '
            . self::UNIQUE_CONSTRAINT_PREFIX . $this->table . '_event_id" UNIQUE ("event_id"), '
            . self::UNIQUE_CONSTRAINT_PREFIX . $this->table . '_global" UNIQUE ("global_sequence"))',
        ));
    }

    #[\Override]
    public function appendToStream(
        string $aggregateType,
        string $aggregateId,
        int $expectedVersion,
        PendingEvent ...$events
    ): array {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        if ($expectedVersion < 0) {
            throw new EventSourcingException("Expected version must be >= 0 (got {$expectedVersion}).");
        }
        if ($events === []) {
            throw new EventSourcingException('appendToStream() requires at least one event.');
        }

        $own = $this->connection->transactionLevel() === 0;
        if (!$own) {
            // Ambient transaction (e.g. AggregateRepository persist): join it.
            return $this->doAppend($aggregateType, $aggregateId, $expectedVersion, $events);
        }
        $this->connection->beginTransaction();

        try {
            $created = $this->doAppend($aggregateType, $aggregateId, $expectedVersion, $events);
        } catch (\Throwable $error) {
            $this->connection->rollBack();

            throw $error;
        }
        $this->connection->commit();

        return $created;
    }

    #[\Override]
    public function loadStream(string $aggregateType, string $aggregateId, int $afterVersion = 0): array
    {
        EventGrammar::assertAggregateType($aggregateType);
        EventGrammar::assertAggregateType($aggregateId, 'aggregate ID');
        if ($afterVersion < 0) {
            throw new EventSourcingException("afterVersion must be >= 0 (got {$afterVersion}).");
        }

        $query = QueryBuilder::table($this->table)
            ->select(...self::STORED_EVENT_COLUMNS)
            ->where('aggregate_type', '=', $aggregateType)
            ->where('aggregate_id', '=', $aggregateId)
        ;
        if ($afterVersion > 0) {
            // Regresi I-10 (issue #175): the snapshot tail cut runs in SQL —
            // snapshot-seeded replay no longer pays full-stream I/O just to
            // skip the pre-snapshot events in PHP.
            $query->where('version', '>', $afterVersion);
        }

        $rows = $this->connection->fetchAll($query->orderBy('version', 'ASC')->build());

        return array_map($this->hydrate(...), $rows);
    }

    #[\Override]
    public function streamAll(int $fromGlobalSequence = 1, ?int $limit = null): array
    {
        if ($fromGlobalSequence < 1) {
            throw new EventSourcingException("fromGlobalSequence must be >= 1 (got {$fromGlobalSequence}).");
        }
        if ($limit !== null && $limit < 1) {
            throw new EventSourcingException("limit must be >= 1 when provided (got {$limit}).");
        }
        $qb = QueryBuilder::table($this->table)
            ->select(...self::STORED_EVENT_COLUMNS)
            ->where('global_sequence', '>=', $fromGlobalSequence)
            ->orderBy('global_sequence', 'ASC')
        ;
        if ($limit !== null) {
            $qb->limit($limit);
        }

        return array_map($this->hydrate(...), $this->connection->fetchAll($qb->build()));
    }

    /**
     * Reject malformed table names early: the QueryBuilder's identifier
     * quoting doubles as the grammar check (it throws on invalid
     * identifiers), keeping the store's SQL fully quoted from the start.
     */
    private static function assertValidTableName(string $table): void
    {
        new QueryBuilder()->quoteIdentifier($table, 'table');
    }

    /**
     * @param non-empty-array<int|string, PendingEvent> $events
     *
     * @return list<StoredEvent>
     */
    private function doAppend(string $aggregateType, string $aggregateId, int $expectedVersion, array $events): array
    {
        $actual = $this->currentVersion($aggregateType, $aggregateId);
        if ($actual !== $expectedVersion) {
            throw new ConcurrencyException($expectedVersion, $actual);
        }

        $maxRow = $this->connection->fetchOne(
            QueryBuilder::table($this->table)->aggregate('MAX', 'global_sequence')->build(),
        );
        $sequence = RowCast::int($maxRow['aggregate'] ?? null);

        $now = ($this->clock)();
        $version = $expectedVersion;
        $created = [];
        foreach ($events as $pending) {
            ++$version;
            ++$sequence;
            $created[] = new StoredEvent(
                eventId: bin2hex(random_bytes(16)),
                aggregateType: $aggregateType,
                aggregateId: $aggregateId,
                version: $version,
                globalSequence: $sequence,
                eventType: $pending->eventType,
                payload: $pending->payload,
                metadata: $pending->metadata,
                recordedAtUnixNano: $now,
            );
        }
        foreach ($created as $event) {
            try {
                $this->connection->execute(
                    QueryBuilder::table($this->table)->insert([
                        'global_sequence' => $event->globalSequence,
                        'event_id' => $event->eventId,
                        'aggregate_type' => $event->aggregateType,
                        'aggregate_id' => $event->aggregateId,
                        'version' => $event->version,
                        'event_type' => $event->eventType,
                        'payload' => EventJson::encode($event->payload, 'Stored event payload'),
                        'metadata' => EventJson::encode($event->metadata, 'Stored event metadata'),
                        'recorded_at' => $event->recordedAtUnixNano,
                    ])->build(),
                );
            } catch (QueryException $error) {
                // Regresi P-3 (issue #170): the version guard above can pass
                // while a concurrent append has already committed the same
                // stream version (repeatable-read snapshot) or grabbed the
                // same global sequence — the unique index rejects the loser,
                // who must surface the port's ConcurrencyException so
                // catch-based retry logic sees the real conflict. The
                // re-selected stream version fills the actual slot; the
                // driver error stays chained as the cause.
                if (!SqlState::isUniqueViolation($error)) {
                    throw $error;
                }

                throw new ConcurrencyException(
                    $expectedVersion,
                    $this->currentVersion($aggregateType, $aggregateId),
                    $error,
                );
            }
        }

        return $created;
    }

    /**
     * Last committed version of the stream (0 when the stream is empty) —
     * the optimistic-concurrency guard and the insert-time backstop's
     * re-check read the same coordinate.
     */
    private function currentVersion(string $aggregateType, string $aggregateId): int
    {
        $last = $this->connection->fetchOne(
            QueryBuilder::table($this->table)
                ->select('version')
                ->where('aggregate_type', '=', $aggregateType)
                ->where('aggregate_id', '=', $aggregateId)
                ->orderBy('version', 'DESC')
                ->limit(1)
                ->build(),
        );

        return isset($last['version']) ? RowCast::int($last['version']) : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StoredEvent
    {
        return new StoredEvent(
            eventId: RowCast::string($row['event_id'] ?? null),
            aggregateType: RowCast::string($row['aggregate_type'] ?? null),
            aggregateId: RowCast::string($row['aggregate_id'] ?? null),
            version: RowCast::int($row['version'] ?? null),
            globalSequence: RowCast::int($row['global_sequence'] ?? null),
            eventType: RowCast::string($row['event_type'] ?? null),
            payload: EventJson::decode(RowCast::string($row['payload'] ?? null), 'stored event payload'),
            metadata: EventJson::decode(RowCast::string($row['metadata'] ?? null), 'stored event metadata'),
            recordedAtUnixNano: RowCast::int($row['recorded_at'] ?? null),
        );
    }
}
