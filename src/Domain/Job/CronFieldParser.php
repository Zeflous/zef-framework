<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Extracted from CronExpression during the sonar-zero campaign
 * (behavior-preserving move; no public API change).
 */

namespace Zef\Framework\Job;

/**
 * @internal
 *
 * Field-level parsing and validation for {@see CronExpression}.
 *
 * Supported syntax per field: wildcard, single values, lists (`a,b`),
 * ranges (`a-b`), and stepped values (wildcard-slash-n, `a-b/n`, `a/n` =
 * `a-max/n`). Day-of-week uses 0..6 with 0 = Sunday; 7 is accepted and
 * normalized to 0.
 */
final class CronFieldParser
{
    private const array FIELD_RANGES = [
        'minute' => ['min' => 0, 'max' => 59],
        'hour' => ['min' => 0, 'max' => 23],
        'dom' => ['min' => 1, 'max' => 31],
        'month' => ['min' => 1, 'max' => 12],
        'dow' => ['min' => 0, 'max' => 7],
    ];

    /** Highest day-of-month each month can reach (February reaches 29 on leap years). */
    private const array MONTH_MAX_DOM = [
        1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
        7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31,
    ];

    /** @return list<int> sorted allowed values for one field */
    public static function parseField(string $field, string $raw): array
    {
        $allowed = [];
        foreach (explode(',', $raw) as $part) {
            [$start, $end, $step] = self::parseComponent($field, $part);
            for ($value = $start; $value <= $end; $value += $step) {
                $allowed[$value] = true;
            }
        }
        if ($allowed === []) {
            throw new \InvalidArgumentException("Cron field '{$field}' matches no values.");
        }
        $values = array_keys($allowed);
        sort($values);

        return $values;
    }

    /**
     * Day-of-week 7 is accepted as Sunday (normalized to 0), matching the
     * documented contract; duplicates collapse and the list stays sorted.
     *
     * @param array<int,int> $values
     *
     * @return array<int,int>
     */
    public static function normalizeDow(array $values): array
    {
        $mapped = array_map(static fn (int $value): int => $value === 7 ? 0 : $value, $values);
        $unique = array_values(array_unique($mapped));
        sort($unique);

        return $unique;
    }

    /**
     * Static never-fires detection: with a restricted day-of-month, every
     * restricted month must be able to reach the smallest required day.
     *
     * @param array<int,int> $daysOfMonth
     * @param array<int,int> $months
     */
    public static function computeNeverFires(array $daysOfMonth, array $months, bool $domRestricted): bool
    {
        if (!$domRestricted || $daysOfMonth === []) {
            return false; // wildcard dom covers 1..31 — every month qualifies
        }
        $required = min($daysOfMonth);
        foreach ($months as $month) {
            if ($required <= self::MONTH_MAX_DOM[$month]) {
                return false; // at least one month can host this day
            }
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int, 2: int} the [start, end, step] bounds
     */
    private static function parseComponent(string $field, string $part): array
    {
        $range = self::FIELD_RANGES[$field];
        [$base, $step] = self::parseStep($field, $part);
        if ($base === '*') {
            return [$range['min'], $range['max'], $step];
        }
        if ($base === '') {
            throw new \InvalidArgumentException("Cron field '{$field}' has an empty component.");
        }
        if (str_contains($base, '-')) {
            return self::parseRange($field, $base, $range, $step);
        }

        return self::parseSingleValue($field, $base, $range, $step);
    }

    /**
     * @return array{0: string, 1: int} [base, step]
     */
    private static function parseStep(string $field, string $part): array
    {
        if (!str_contains($part, '/')) {
            return [$part, 1];
        }
        [$base, $stepRaw] = explode('/', $part, 2);
        if (!self::isValidStep($stepRaw)) {
            throw new \InvalidArgumentException("Cron field '{$field}' has invalid step '{$stepRaw}'.");
        }
        $step = (int) $stepRaw;
        if ($step < 1) {
            throw new \InvalidArgumentException("Cron field '{$field}' step must be >= 1.");
        }

        return [$base, $step];
    }

    /**
     * @param array{min: int, max: int} $range
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function parseRange(string $field, string $base, array $range, int $step): array
    {
        [$startRaw, $endRaw] = explode('-', $base, 2);
        if (!self::isValidValue($startRaw) || !self::isValidValue($endRaw)) {
            throw new \InvalidArgumentException("Cron field '{$field}' has invalid range '{$base}'.");
        }
        $start = (int) $startRaw;
        $end = (int) $endRaw;
        if ($start < $range['min'] || $end > $range['max'] || $start > $end) {
            throw new \InvalidArgumentException("Cron field '{$field}' range '{$base}' out of bounds.");
        }

        return [$start, $end, $step];
    }

    /**
     * @param array{min: int, max: int} $range
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function parseSingleValue(string $field, string $base, array $range, int $step): array
    {
        if (!self::isValidValue($base)) {
            throw new \InvalidArgumentException("Cron field '{$field}' has invalid component '{$base}'.");
        }
        $start = (int) $base;
        // "a/n" means "a to max, stepped by n" (classic cron).
        $end = $step > 1 ? $range['max'] : $start;
        if ($start < $range['min'] || $start > $range['max']) {
            throw new \InvalidArgumentException("Cron field '{$field}' value '{$base}' out of bounds.");
        }

        return [$start, $end, $step];
    }

    private static function isValidValue(string $value): bool
    {
        return preg_match('/^\d{1,2}$/', $value) === 1;
    }

    /**
     * A step shares the same shape contract as a bare value: one or two
     * ASCII digits (delegates to {@see isValidValue()} to keep the two
     * rules from drifting apart).
     */
    private static function isValidStep(string $value): bool
    {
        return self::isValidValue($value);
    }
}
