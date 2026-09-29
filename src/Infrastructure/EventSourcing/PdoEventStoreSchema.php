<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Event Sourcing (Infrastructure layer: outbound adapters)
 * Schema collaborator of PdoEventStore (php:S2042 split, 2026-09-29): the
 * store keeps the SQL data paths, this class owns the portable schema DDL.
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\SqlQuery;

/**
 * Portable events-table DDL.
 *
 * create() issues portable DDL (TEXT columns, no driver-specific index
 * syntax); production tuning belongs in user migrations. v2.23.0 hardening:
 * the schema carries two store-wide backstops — UNIQUE(event_id) and
 * UNIQUE(global_sequence) — so a racing MAX(global_sequence) computation
 * fails loudly instead of silently double-assigning projection currency.
 * Deployments created before v2.23.0 should add both indexes manually (see
 * CHANGELOG-v2.23.0.md) — CREATE TABLE IF NOT EXISTS does not alter
 * existing tables.
 */
final class PdoEventStoreSchema
{
    /** Every stored-event column, in storage order. */
    public const array STORED_EVENT_COLUMNS = [
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

    /** DDL prefix shared by the three store-wide unique constraints. */
    private const string UNIQUE_CONSTRAINT_PREFIX = 'CONSTRAINT "uq_';

    /**
     * Create the events table (portable DDL, safe to run repeatedly).
     *
     * The DDL is assembled from column/constraint fragments (php:S2005: no
     * adjacent string-literal concatenation, php:S103: no overlong lines);
     * the resulting statement is byte-identical to the former inline chain
     * inside PdoEventStore::createSchema().
     */
    public static function create(ConnectionInterface $connection, string $table): void
    {
        $columns = [
            '"global_sequence" BIGINT NOT NULL',
            '"event_id" VARCHAR(64) NOT NULL',
            '"aggregate_type" VARCHAR(128) NOT NULL',
            '"aggregate_id" VARCHAR(128) NOT NULL',
            '"version" BIGINT NOT NULL',
            '"event_type" VARCHAR(191) NOT NULL',
            '"payload" TEXT NOT NULL',
            '"metadata" TEXT NOT NULL',
            '"recorded_at" BIGINT NOT NULL',
        ];
        $constraints = [
            self::UNIQUE_CONSTRAINT_PREFIX . $table
                . '_stream" UNIQUE ("aggregate_type", "aggregate_id", "version")',
            self::UNIQUE_CONSTRAINT_PREFIX . $table . '_event_id" UNIQUE ("event_id")',
            self::UNIQUE_CONSTRAINT_PREFIX . $table . '_global" UNIQUE ("global_sequence")',
        ];
        $ddl = 'CREATE TABLE IF NOT EXISTS "' . $table . '" ('
            . implode(', ', [...$columns, ...$constraints])
            . ')';
        $connection->execute(SqlQuery::raw($ddl));
    }
}
