<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects).
 * Wire-format parsing for FilterSpec (php:S2042): turns the nested/flat
 * query-string forms into the validated condition list. Extracted from
 * FilterSpec as a byte-identical move — no behavioural changes.
 */

namespace Zef\Framework\Resource;

/**
 * Internal parser behind {@see FilterSpec::fromQuery()}.
 *
 * Both wire forms are supported simultaneously:
 *   nested:  ?filter[status]=open&filter[price_gte]=100
 *   flat:    ?filter_status=open&filter_price_gte=100
 */
final class FilterQueryParser
{
    /**
     * @param array<string,mixed> $query already-parsed query values
     * @param list<string> $whitelist allowed base field names
     *
     * @return list<FilterCondition>
     */
    public static function parse(array $query, array $whitelist, string $prefix): array
    {
        $allowed = array_fill_keys($whitelist, true);
        $conditions = self::parseNestedForm($query, $prefix, $allowed);

        return self::parseFlatForm($query, $prefix, $allowed, $conditions);
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
            if (count($conditions) >= FilterSpec::MAX_CONDITIONS) {
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
            if (count($conditions) >= FilterSpec::MAX_CONDITIONS) {
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
        if ($key === '' || strlen($key) > FilterSpec::MAX_FIELD_BYTES + 8) {
            return null;
        }
        // Split operator suffix (_gte etc.) from the base field.
        $op = FilterCondition::EQ;
        $field = $key;
        if (
            preg_match('/^([A-Za-z0-9_]{1,' . FilterSpec::MAX_FIELD_BYTES . '}?)_('
            . implode('|', FilterCondition::OPS) . ')$/', $key, $m) === 1
        ) {
            $field = $m[1];
            $op = $m[2];
        }
        if ($field === '' || strlen($field) > FilterSpec::MAX_FIELD_BYTES || !isset($allowed[$field])) {
            return null; // lenient: unknown fields are dropped
        }
        $rawValue = strlen($rawValue) > FilterSpec::MAX_VALUE_BYTES
            ? substr($rawValue, 0, FilterSpec::MAX_VALUE_BYTES)
            : $rawValue;
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
}
