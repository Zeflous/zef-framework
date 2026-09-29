<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * Filter specification parsed from a query string against a mandatory whitelist.
 *
 * Wire formats (both supported simultaneously):
 *   nested:  ?filter[status]=open&filter[price_gte]=100
 *   flat:    ?filter_status=open&filter_price_gte=100
 *
 * Operator suffixes: _eq (default), _neq, _gt, _gte, _lt, _lte, _like, _in
 * (`_in` splits the value on commas).
 *
 * Security stance: the whitelist is the injection boundary — only whitelisted
 * base fields can ever reach the caller, values are bounded scalar strings,
 * and unknown fields/operators/oversized input are dropped leniently
 * (a listing endpoint must not 500 because of client input).
 *
 * @phpstan-type ORDERED_OP 'gt'|'gte'|'lt'|'lte'
 */
final readonly class FilterSpec
{
    public const int MAX_CONDITIONS = 32;
    public const int MAX_FIELD_BYTES = 64;
    public const int MAX_VALUE_BYTES = 256;

    /** The four ordered-comparison operators routed to compareWithin(). */
    private const array ORDERED_OPERATORS = [
        FilterCondition::GT,
        FilterCondition::GTE,
        FilterCondition::LT,
        FilterCondition::LTE,
    ];

    /**
     * @var list<FilterCondition>
     */
    public array $conditions;

    /** @param list<FilterCondition> $conditions */
    public function __construct(array $conditions = [])
    {
        $resolved = [];
        foreach ($conditions as $condition) {
            if (!$condition instanceof FilterCondition) {
                throw new \InvalidArgumentException('FilterSpec expects a list of FilterCondition instances.');
            }
            $resolved[] = $condition;
        }
        $this->conditions = $resolved;
    }

    /**
     * @param array<string,mixed> $query already-parsed query values
     * @param list<string> $whitelist allowed base field names
     */
    public static function fromQuery(array $query, array $whitelist, string $prefix = 'filter'): self
    {
        $allowed = array_fill_keys($whitelist, true);
        $conditions = self::parseNestedForm($query, $prefix, $allowed);
        $conditions = self::parseFlatForm($query, $prefix, $allowed, $conditions);

        return new self($conditions);
    }

    /** @return list<FilterCondition> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * In-memory filtering over a list of rows. Null row values never match
     * any operator except `neq` (null is "not equal" to any given value).
     *
     * @param list<array<string,mixed>|object> $rows
     *
     * @return list<array<string,mixed>|object>
     */
    public function applyTo(array $rows): array
    {
        if ($this->conditions === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => array_all(
                $this->conditions,
                fn (FilterCondition $condition): bool => $this->rowMatches($row, $condition),
            ),
        ));
    }

    /**
     * Nested wire form: `filter[status]=open`, `filter[price_gte]=100`.
     *
     * @param array<string,mixed> $query
     * @param array<string,true> $allowed
     *
     * @return list<FilterCondition>
     */
    private static function parseNestedForm(array $query, string $prefix, array $allowed): array
    {
        $nested = $query[$prefix] ?? null;
        if (!is_array($nested)) {
            return [];
        }
        $conditions = [];
        foreach ($nested as $key => $value) {
            if (count($conditions) >= self::MAX_CONDITIONS) {
                break;
            }
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $parsed = self::parseEntry($key, (string) $value, $allowed);
            if ($parsed instanceof FilterCondition) {
                $conditions[] = $parsed;
            }
        }

        return $conditions;
    }

    /**
     * Flat wire form: `filter_status=open`, `filter_price_gte=100`.
     *
     * @param array<string,mixed> $query
     * @param array<string,true> $allowed
     * @param list<FilterCondition> $conditions conditions parsed from the nested form
     *
     * @return list<FilterCondition>
     */
    private static function parseFlatForm(array $query, string $prefix, array $allowed, array $conditions): array
    {
        $flatPrefix = $prefix . '_';
        foreach ($query as $key => $value) {
            if (count($conditions) >= self::MAX_CONDITIONS) {
                break;
            }
            if (!is_string($key) || !str_starts_with($key, $flatPrefix) || !is_scalar($value)) {
                continue;
            }
            $fieldPart = substr($key, strlen($flatPrefix));
            if ($fieldPart === '') {
                continue;
            }
            $parsed = self::parseEntry($fieldPart, (string) $value, $allowed);
            if ($parsed instanceof FilterCondition) {
                $conditions[] = $parsed;
            }
        }

        return $conditions;
    }

    /** @param array<string,true> $allowed */
    private static function parseEntry(string $key, string $rawValue, array $allowed): ?FilterCondition
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > self::MAX_FIELD_BYTES + 8) {
            return null;
        }
        // Split operator suffix (_gte etc.) from the base field.
        $op = FilterCondition::EQ;
        $field = $key;
        if (
            preg_match('/^([A-Za-z0-9_]{1,' . self::MAX_FIELD_BYTES . '}?)_('
            . implode('|', FilterCondition::OPS) . ')$/', $key, $m) === 1
        ) {
            $field = $m[1];
            $op = $m[2];
        }
        if ($field === '' || strlen($field) > self::MAX_FIELD_BYTES || !isset($allowed[$field])) {
            return null; // lenient: unknown fields are dropped
        }
        $rawValue = strlen($rawValue) > self::MAX_VALUE_BYTES ? substr($rawValue, 0, self::MAX_VALUE_BYTES) : $rawValue;
        if ($op === FilterCondition::IN) {
            return self::parseInValues($field, $rawValue);
        }

        return new FilterCondition($field, $op, $rawValue);
    }

    /**
     * `_in` values split on commas; blank parts are dropped. A value list
     * that ends up empty drops the whole condition (lenient parse).
     */
    private static function parseInValues(string $field, string $rawValue): ?FilterCondition
    {
        $values = [];
        foreach (explode(',', $rawValue) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $values[] = $part;
            }
        }
        if ($values === []) {
            return null;
        }

        return new FilterCondition($field, FilterCondition::IN, $values);
    }

    private function rowMatches(mixed $row, FilterCondition $condition): bool
    {
        $actual = $this->valueOf($row, $condition->field);
        $expected = $condition->value;

        // `match` (php:S131/S1151): every operator has exactly one arm and the
        // terminal default keeps unknown operators from matching anything.
        return match ($condition->op) {
            FilterCondition::NEQ => $actual === null || !$this->equals($actual, $expected),
            FilterCondition::EQ => $actual !== null && $this->equals($actual, $expected),
            FilterCondition::IN => $this->inList($actual, $expected),
            FilterCondition::LIKE => $this->like($actual, $expected),
            FilterCondition::GT,
            FilterCondition::GTE,
            FilterCondition::LT,
            FilterCondition::LTE => $this->compareWithin($actual, $expected, $condition->op),
            default => false,
        };
    }

    /**
     * `in` operator matching: any candidate equal to the actual value wins.
     *
     * @param list<string>|string $expected
     */
    private function inList(mixed $actual, array|string $expected): bool
    {
        if ($actual === null) {
            return false;
        }
        foreach ($expected as $candidate) {
            if ($this->equals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `like` operator: case-insensitive substring match over scalar text.
     *
     * @param list<string>|string $expected
     */
    private function like(mixed $actual, array|string $expected): bool
    {
        return (is_string($actual) || is_numeric($actual))
            && str_contains(strtolower((string) $actual), strtolower((string) $expected));
    }

    /**
     * Ordered operators (gt/gte/lt/lte); a null actual value never matches.
     *
     * @param ORDERED_OP $op
     * @param list<string>|string $expected
     */
    private function compareWithin(mixed $actual, array|string $expected, string $op): bool
    {
        if (!in_array($op, self::ORDERED_OPERATORS, true)) {
            return false;
        }
        if ($actual === null) {
            return false;
        }
        $cmp = $this->compare($actual, $expected);

        return match ($op) {
            FilterCondition::GT => $cmp > 0,
            FilterCondition::GTE => $cmp >= 0,
            FilterCondition::LT => $cmp < 0,
            FilterCondition::LTE => $cmp <= 0,
            default => false,
        };
    }

    private function equals(mixed $actual, string $expected): bool
    {
        if (is_bool($actual)) {
            $actual = $actual ? '1' : '0';
        }
        if (is_float($actual) && floor($actual) === $actual) {
            $actual = (string) (int) $actual; // 5.0 matches "5"
        }

        return (is_string($actual) || is_int($actual) || is_float($actual))
            && (string) $actual === $expected;
    }

    private function compare(mixed $actual, string $expected): int
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }

        return (is_string($actual) || is_numeric($actual) ? (string) $actual : '') <=> $expected;
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
