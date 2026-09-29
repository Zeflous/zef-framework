<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * Sort specification parsed from a query string against a mandatory whitelist.
 *
 * Wire format:  ?sort=-price,name        ('-' prefix = descending, '+' = ascending)
 *
 * Security & robustness stance (mirrors PageRequest): listing endpoints must
 * never 500 because of client input. Fields outside the whitelist are dropped
 * silently; an empty result falls back to the configured defaults. The
 * whitelist is also the injection boundary — callers may map fields to column
 * names knowing only whitelisted identifiers can ever arrive.
 */
final readonly class SortSpec
{
    public const int MAX_KEYS = 8;
    public const int MAX_FIELD_BYTES = 64;

    /**
     * @var list<SortKey>
     */
    public array $keys;

    /**
     * @param list<string> $defaultFields applied when no valid sort field arrives
     */
    public function __construct(array $keys, array $defaultFields = [], bool $defaultDesc = false)
    {
        $resolved = [];
        foreach ($keys as $key) {
            if (!$key instanceof SortKey) {
                throw new \InvalidArgumentException('SortSpec expects a list of SortKey instances.');
            }
            $resolved[] = $key;
        }
        if ($resolved === [] && $defaultFields !== []) {
            $resolved = self::defaultKeys($defaultFields, $defaultDesc);
        }
        $this->keys = $resolved;
    }

    /**
     * @param array<string,mixed> $query already-parsed query values
     * @param list<string> $whitelist allowed field names
     */
    public static function fromQuery(
        array $query,
        array $whitelist,
        string $sortKey = 'sort',
        array $defaultFields = [],
        bool $defaultDesc = false,
    ): self {
        $allowed = array_fill_keys($whitelist, true);
        $raw = $query[$sortKey] ?? null;
        $keys = is_string($raw) && $raw !== ''
            ? self::parseSortKeys($raw, $allowed)
            : [];

        return new self($keys, $defaultFields, $defaultDesc);
    }

    /** @return list<SortKey> resolved keys (defaults included when the query had none) */
    public function keys(): array
    {
        return $this->keys;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /**
     * In-memory multi-key stable sort over a list of rows.
     *
     * @param list<array<string,mixed>|object> $rows
     *
     * @return list<array<string,mixed>|object>
     */
    public function applyTo(array $rows): array
    {
        if ($this->keys === [] || count($rows) < 2) {
            return $rows;
        }
        usort($rows, function (mixed $a, mixed $b): int {
            foreach ($this->keys as $key) {
                $cmp = $this->valueOf($a, $key->field) <=> $this->valueOf($b, $key->field);
                if ($key->desc) {
                    $cmp = -$cmp;
                }
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0; // PHP 8 usort is stable
        });

        return $rows;
    }

    /** @return null|string wire representation, null when no keys */
    public function toQuery(): ?string
    {
        if ($this->keys === []) {
            return null;
        }
        $parts = [];
        foreach ($this->keys as $key) {
            $parts[] = ($key->desc ? '-' : '') . $key->field;
        }

        return implode(',', $parts);
    }

    /**
     * @param list<string> $defaultFields
     *
     * @return list<SortKey>
     */
    private static function defaultKeys(array $defaultFields, bool $defaultDesc): array
    {
        $resolved = [];
        foreach ($defaultFields as $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException('Default sort fields must be non-empty strings.');
            }
            $resolved[] = new SortKey($field, $defaultDesc);
        }

        return $resolved;
    }

    /**
     * @param array<string, true> $allowed
     *
     * @return list<SortKey>
     */
    private static function parseSortKeys(string $raw, array $allowed): array
    {
        if (strlen($raw) > 512) {
            $raw = substr($raw, 0, 512);
        }
        $keys = [];
        foreach (explode(',', $raw) as $candidate) {
            if (count($keys) >= self::MAX_KEYS) {
                break;
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            [$candidate, $desc] = self::splitDirection($candidate);
            if ($candidate === '' || strlen($candidate) > self::MAX_FIELD_BYTES) {
                continue;
            }
            if (!isset($allowed[$candidate])) {
                continue; // lenient: unknown fields never abort the request
            }
            if (self::hasKey($keys, $candidate)) {
                continue; // first occurrence wins
            }
            $keys[] = new SortKey($candidate, $desc);
        }

        return $keys;
    }

    /**
     * @return array{string, bool} the bare field name and its descending flag
     */
    private static function splitDirection(string $candidate): array
    {
        $first = $candidate[0];
        $desc = $first === '-';
        if ($first === '-' || $first === '+') {
            // Direction prefix stripped; a plain field name falls through.
            $candidate = ltrim(substr($candidate, 1), '+- ');
        }

        return [$candidate, $desc];
    }

    /**
     * @param list<SortKey> $keys
     */
    private static function hasKey(array $keys, string $candidate): bool
    {
        foreach ($keys as $existing) {
            if ($existing->field === $candidate) {
                return true;
            }
        }

        return false;
    }

    private function valueOf(mixed $row, string $field): mixed
    {
        if (is_array($row)) {
            return $row[$field] ?? null;
        }
        if (is_object($row)) {
            return $row->{$field} ?? null;
        }

        return null;
    }
}
