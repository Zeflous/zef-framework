<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Single JSON codec for the Event Sourcing zone.
 *
 * Every payload/metadata map passes through {@see encode()} at least twice
 * in its life (VO validation + adapter persistence) and through
 * {@see decode()} on read — one place to keep depth limits, error mapping
 * and the list/map round-trip contract consistent.
 */
final class EventJson
{
    private const int JSON_DEPTH = 512;

    private function __construct() {}

    /**
     * @param array<mixed> $value
     */
    public static function encode(array $value, string $field): string
    {
        try {
            // JSON_PRESERVE_ZERO_FRACTION (P-15, issue #170): without it the
            // round-trip rewrites float 10.0 into int 10 — payload field types
            // silently change between append and replay. PdoJobQueue already
            // uses the flag; EventJson is the event-sourcing counterpart.
            return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new EventSourcingException("{$field} must be JSON-encodable: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function decode(string $json, string $field): array
    {
        try {
            $decoded = json_decode($json, true, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EventSourcingException("Corrupt {$field}: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new EventSourcingException("Corrupt {$field}: expected a JSON object or array.");
        }

        return $decoded;
    }
}
