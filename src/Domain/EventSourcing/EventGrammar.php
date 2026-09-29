<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

use Zef\Framework\Validation\Identifier;

/**
 * Centralised grammar + validation for the Event Sourcing zone.
 *
 * Mirrors the role of {@see Identifier} but with
 * event-sourcing-specific bounds:
 * - aggregate type/id and projection id: 1..128 bytes of [A-Za-z0-9._:-];
 * - event type: 1..191 bytes of the same class (matches the outbox
 *   `message_type` column width);
 * - event id: exactly 32 lowercase hex characters;
 * - every payload/metadata map must be JSON-encodable up front so a stream
 *   can never contain an event that the PDO adapter would later fail to
 *   serialise.
 */
final class EventGrammar
{
    public const string AGGREGATE_TYPE_PATTERN = '/^[A-Za-z0-9._:\-]{1,128}$/';
    public const string EVENT_TYPE_PATTERN = '/^[A-Za-z0-9._:\-]{1,191}$/';
    public const string EVENT_ID_PATTERN = '/^[0-9a-f]{32}$/';

    /** Hard upper bound for one read batch (projections page through the store). */
    public const int MAX_PAGE = 10_000;

    private function __construct()
    {
        // Intentionally empty: static-only validation helper — the private
        // constructor exists solely to prevent instantiation of this class.
    }

    public static function assertAggregateType(string $value, string $field = 'aggregate type'): void
    {
        if (preg_match(self::AGGREGATE_TYPE_PATTERN, $value) !== 1) {
            throw new EventSourcingException("Invalid {$field} '{$value}' (1..128 bytes of [A-Za-z0-9._:-]).");
        }
    }

    public static function assertEventType(string $value, string $field = 'event type'): void
    {
        if (preg_match(self::EVENT_TYPE_PATTERN, $value) !== 1) {
            throw new EventSourcingException("Invalid {$field} '{$value}' (1..191 bytes of [A-Za-z0-9._:-]).");
        }
    }

    public static function assertEventId(string $value, string $field = 'event id'): void
    {
        if (preg_match(self::EVENT_ID_PATTERN, $value) !== 1) {
            throw new EventSourcingException("Invalid {$field} '{$value}' (32 lowercase hex characters expected).");
        }
    }

    /**
     * @param array<mixed> $value
     */
    public static function assertPayload(array $value, string $field): void
    {
        EventJson::encode($value, $field);
    }

    public static function assertVersion(int $value, string $field = 'version'): void
    {
        if ($value < 1) {
            throw new EventSourcingException("{$field} must be >= 1 (got {$value}).");
        }
    }

    public static function assertGlobalSequence(int $value, string $field = 'global sequence'): void
    {
        if ($value < 1) {
            throw new EventSourcingException("{$field} must be >= 1 (got {$value}).");
        }
    }

    public static function assertUnixNano(int $value, string $field): void
    {
        if ($value < 0) {
            throw new EventSourcingException("{$field} must be >= 0 (got {$value}).");
        }
    }
}
