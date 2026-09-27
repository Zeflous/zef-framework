<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Job;

/**
 * Five-field cron expression (minute hour day-of-month month day-of-week).
 *
 * Supported syntax per field: wildcard, single values, lists (`a,b`), ranges
 * (`a-b`), and stepped values (wildcard-slash-n, `a-b/n`, `a/n` = `a-max/n`).
 * Day-of-week uses
 * 0..6 with 0 = Sunday (7 is normalized to 0). All matching is performed in
 * UTC (no DST discontinuities); runs are aligned to whole minutes.
 *
 * Classic cron OR-semantics apply when both day-of-month and day-of-week
 * are restricted (a date matches when either field matches).
 */
final readonly class CronExpression implements ScheduleInterface
{
    private const int MAX_SCAN_MINUTES = 1461 * 24 * 60; // one full leap cycle

    private const array FIELD_RANGES = [
        'minute' => ['min' => 0, 'max' => 59],
        'hour' => ['min' => 0, 'max' => 23],
        'dom' => ['min' => 1, 'max' => 31],
        'month' => ['min' => 1, 'max' => 12],
        'dow' => ['min' => 0, 'max' => 6],
    ];

    private function __construct(
        /** @var array<int,int> sorted allowed values */
        public array $minutes,
        public array $hours,
        public array $daysOfMonth,
        public array $months,
        public array $daysOfWeek,
        public string $expression,
        public bool $domRestricted,
        public bool $dowRestricted,
    ) {}

    public static function parse(string $expression): self
    {
        $expression = trim($expression);
        $split = preg_split('/\s+/', $expression);
        $fields = is_array($split) ? $split : [];
        if (count($fields) !== 5) {
            throw new \InvalidArgumentException("Cron expression '{$expression}' must have exactly 5 fields (minute hour dom month dow).");
        }
        $parsed = [];
        foreach (array_combine(['minute', 'hour', 'dom', 'month', 'dow'], $fields) as $field => $raw) {
            $parsed[$field] = self::parseField($field, $raw);
        }
        $domWildcard = $parsed['dom'] === range(1, 31);

        // Mark "explicit wildcard" via full-range detection; 1..31 always covers all possible dates.
        return new self(
            $parsed['minute'],
            $parsed['hour'],
            $parsed['dom'],
            $parsed['month'],
            $parsed['dow'],
            $expression,
            !$domWildcard,
            $parsed['dow'] !== range(0, 6),
        );
    }

    #[\Override]
    public function nextRunAfter(int $nowUnixNano): int
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Cron time must be non-negative.');
        }
        $nowSec = intdiv($nowUnixNano, 1_000_000_000) + 1;
        $minuteStart = $nowSec - ($nowSec % 60);
        for ($i = 0; $i < self::MAX_SCAN_MINUTES; ++$i) {
            $candidate = $minuteStart + $i * 60;
            if ($candidate * 1_000_000_000 > $nowUnixNano && $this->matchesUtc($candidate)) {
                return $candidate * 1_000_000_000;
            }
        }

        throw new \RuntimeException("Cron expression '{$this->expression}' has no matching minute within 4 years.");
    }

    public function matchesUtc(int $unixSeconds): bool
    {
        // ZEF-DEEP-06: getdate() resolves the timestamp in the PROCESS
        // timezone, silently shifting every schedule on non-UTC hosts (the
        // docblock and describe() promise "All matching is performed in
        // UTC"). gmdate() is UTC-exact by definition; the pipe-joined format
        // keeps the hot path (nextRunAfter's minute scan) to one call.
        [$minute, $hour, $month, $mday, $wday] = explode('|', gmdate('i|G|n|j|w', $unixSeconds));
        if (!in_array((int) $minute, $this->minutes, true)) {
            return false;
        }
        if (!in_array((int) $hour, $this->hours, true)) {
            return false;
        }
        if (!in_array((int) $month, $this->months, true)) {
            return false;
        }
        $domMatch = in_array((int) $mday, $this->daysOfMonth, true);
        $dowMatch = in_array((int) $wday, $this->daysOfWeek, true);
        if ($this->domRestricted && $this->dowRestricted) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    #[\Override]
    public function describe(): string
    {
        return $this->expression . ' (UTC)';
    }

    /** @return list<int> sorted allowed values for one field */
    private static function parseField(string $field, string $raw): array
    {
        $range = self::FIELD_RANGES[$field];
        $allowed = [];
        foreach (explode(',', $raw) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$base, $stepRaw] = explode('/', $part, 2);
                if (!self::isValidStep($stepRaw)) {
                    throw new \InvalidArgumentException("Cron field '{$field}' has invalid step '{$stepRaw}'.");
                }
                $step = (int) $stepRaw;
                if ($step < 1) {
                    throw new \InvalidArgumentException("Cron field '{$field}' step must be >= 1.");
                }
            } else {
                $base = $part;
            }
            if ($base === '*' || $base === '') {
                if ($base === '') {
                    throw new \InvalidArgumentException("Cron field '{$field}' has an empty component.");
                }
                $start = $range['min'];
                $end = $range['max'];
            } elseif (str_contains($base, '-')) {
                [$startRaw, $endRaw] = explode('-', $base, 2);
                if (!self::isValidValue($startRaw) || !self::isValidValue($endRaw)) {
                    throw new \InvalidArgumentException("Cron field '{$field}' has invalid range '{$base}'.");
                }
                $start = (int) $startRaw;
                $end = (int) $endRaw;
                if ($start < $range['min'] || $end > $range['max'] || $start > $end) {
                    throw new \InvalidArgumentException("Cron field '{$field}' range '{$base}' out of bounds.");
                }
            } elseif (self::isValidValue($base)) {
                $start = (int) $base;
                // "a/n" means "a to max, stepped by n" (classic cron).
                $end = $step > 1 ? $range['max'] : $start;
                if ($start < $range['min'] || $start > $range['max']) {
                    throw new \InvalidArgumentException("Cron field '{$field}' value '{$base}' out of bounds.");
                }
            } else {
                throw new \InvalidArgumentException("Cron field '{$field}' has invalid component '{$base}'.");
            }
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

    private static function isValidValue(string $value): bool
    {
        return preg_match('/^\d{1,2}$/', $value) === 1;
    }

    private static function isValidStep(string $value): bool
    {
        return preg_match('/^\d{1,2}$/', $value) === 1;
    }
}
