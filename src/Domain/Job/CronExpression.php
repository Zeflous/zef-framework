<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 * v2.31.0 — Cron Engine v2: UTC-exact matching via gmdate() (getdate() used
 * the process timezone), day-of-week 7 accepted and normalized to 0, static
 * never-fires detection for impossible day-of-month × month combinations
 * (fast-fail instead of a 4-year minute scan), and timezone-aware
 * matchesIn()/nextRunAfterIn() variants.
 */

namespace Zef\Framework\Job;

/**
 * Five-field cron expression (minute hour day-of-month month day-of-week).
 *
 * Supported syntax per field: wildcard, single values, lists (`a,b`), ranges
 * (`a-b`), and stepped values (wildcard-slash-n, `a-b/n`, `a/n` = `a-max/n`).
 * Day-of-week uses 0..6 with 0 = Sunday; 7 is accepted and normalized to 0.
 *
 * All matching is performed in UTC (no DST discontinuities) for
 * {@see matchesUtc()} / {@see nextRunAfter()}; runs are aligned to whole
 * minutes. Use {@see matchesIn()} / {@see nextRunAfterIn()} to evaluate the
 * same expression against an explicit timezone instead.
 *
 * Classic cron OR-semantics apply when both day-of-month and day-of-week
 * are restricted (a date matches when either field matches).
 *
 * Never-firing expressions (e.g. `0 0 31 2 *` — February never has a 31st)
 * are detected statically at construction: {@see isNeverFire()} reports it,
 * matchers return false without scanning, and {@see nextRunAfter()} fails
 * fast instead of burning CPU on a 4-year minute scan.
 */
final readonly class CronExpression implements ScheduleInterface
{
    private const int MAX_SCAN_MINUTES = 1461 * 24 * 60; // one full leap cycle

    private const array FIELD_RANGES = [
        'minute' => ['min' => 0, 'max' => 59],
        'hour' => ['min' => 0, 'max' => 23],
        'dom' => ['min' => 1, 'max' => 31],
        'month' => ['min' => 1, 'max' => 12],
        'dow' => ['min' => 0, 'max' => 7],
    ];

    /** Highest day-of-month each month can reach (February reaches 29 on leap years). */
    private const array MONTH_MAX_DOM = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

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
        private bool $neverFires
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
        $parsed['dow'] = self::normalizeDow($parsed['dow']);
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
            self::computeNeverFires($parsed['dom'], $parsed['month'], !$domWildcard),
        );
    }

    #[\Override]
    public function nextRunAfter(int $nowUnixNano): int
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Cron time must be non-negative.');
        }
        if ($this->neverFires) {
            throw new \RuntimeException(
                "Cron expression '{$this->expression}' can never fire (the restricted day-of-month has no valid date in the restricted months).",
            );
        }

        return $this->scanNext(fn (int $unixSeconds): bool => $this->matchesUtc($unixSeconds), $nowUnixNano);
    }

    /**
     * Next run strictly after $nowUnixNano, evaluated in $timeZone local
     * wall-clock time (v2.31.0). Same whole-minute alignment and scan budget
     * as {@see nextRunAfter()}.
     */
    public function nextRunAfterIn(\DateTimeZone $timeZone, int $nowUnixNano): int
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Cron time must be non-negative.');
        }
        if ($this->neverFires) {
            throw new \RuntimeException(
                "Cron expression '{$this->expression}' can never fire (the restricted day-of-month has no valid date in the restricted months).",
            );
        }

        return $this->scanNext(fn (int $unixSeconds): bool => $this->matchesIn($timeZone, $unixSeconds), $nowUnixNano);
    }

    /**
     * Match against UTC wall-clock fields (v2.31.0: computed with gmdate(),
     * immune to the process default timezone — the previous getdate()-based
     * implementation silently matched in the process timezone instead).
     */
    public function matchesUtc(int $unixSeconds): bool
    {
        if ($this->neverFires) {
            return false;
        }

        return $this->matchesFields(
            (int) gmdate('i', $unixSeconds),
            (int) gmdate('G', $unixSeconds),
            (int) gmdate('j', $unixSeconds),
            (int) gmdate('n', $unixSeconds),
            (int) gmdate('w', $unixSeconds),
        );
    }

    /**
     * Match against the wall-clock fields of $timeZone (v2.31.0) — for
     * applications that run schedules in a business timezone rather than UTC.
     */
    public function matchesIn(\DateTimeZone $timeZone, int $unixSeconds): bool
    {
        if ($this->neverFires) {
            return false;
        }
        $local = new \DateTimeImmutable('@' . $unixSeconds)->setTimezone($timeZone);

        return $this->matchesFields(
            (int) $local->format('i'),
            (int) $local->format('G'),
            (int) $local->format('j'),
            (int) $local->format('n'),
            (int) $local->format('w'),
        );
    }

    /**
     * True when the restricted day-of-month has no valid date in any of the
     * restricted months (e.g. `0 0 31 2 *`, `0 0 30 2 *`, `0 0 31 2,4,6,9,11 *`).
     * February counts with its leap-year maximum of 29, so `0 0 29 2 *` is
     * possible and NOT a never-firing expression.
     */
    public function isNeverFire(): bool
    {
        return $this->neverFires;
    }

    #[\Override]
    public function describe(): string
    {
        return $this->expression . ' (UTC)';
    }

    private function scanNext(\Closure $matches, int $nowUnixNano): int
    {
        $nowSec = intdiv($nowUnixNano, 1_000_000_000) + 1;
        $minuteStart = $nowSec - ($nowSec % 60);
        for ($i = 0; $i < self::MAX_SCAN_MINUTES; ++$i) {
            $candidate = $minuteStart + $i * 60;
            if ($candidate * 1_000_000_000 > $nowUnixNano && $matches($candidate)) {
                return $candidate * 1_000_000_000;
            }
        }

        throw new \RuntimeException("Cron expression '{$this->expression}' has no matching minute within 4 years.");
    }

    /**
     * Field matching against caller-provided wall-clock fields. UTC/TZ
     * exactness is the caller's contract: {@see matchesUtc()} computes the
     * fields with gmdate() (ZEF-DEEP-06: immune to the process default
     * timezone — the previous getdate()-based implementation silently
     * matched in the process timezone), {@see matchesIn()} via
     * DateTimeImmutable::setTimezone().
     */
    private function matchesFields(int $minute, int $hour, int $dom, int $month, int $dow): bool
    {
        if (!in_array($minute, $this->minutes, true)) {
            return false;
        }
        if (!in_array($hour, $this->hours, true)) {
            return false;
        }
        if (!in_array($month, $this->months, true)) {
            return false;
        }
        $domMatch = in_array($dom, $this->daysOfMonth, true);
        $dowMatch = in_array($dow, $this->daysOfWeek, true);
        if ($this->domRestricted && $this->dowRestricted) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }

    /**
     * Static never-fires detection: with a restricted day-of-month, every
     * restricted month must be able to reach the smallest required day.
     *
     * @param array<int,int> $daysOfMonth
     * @param array<int,int> $months
     */
    private static function computeNeverFires(array $daysOfMonth, array $months, bool $domRestricted): bool
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
     * Day-of-week 7 is accepted as Sunday (normalized to 0), matching the
     * documented contract; duplicates collapse and the list stays sorted.
     *
     * @param array<int,int> $values
     *
     * @return array<int,int>
     */
    private static function normalizeDow(array $values): array
    {
        $mapped = array_map(static fn (int $value): int => $value === 7 ? 0 : $value, $values);
        $unique = array_values(array_unique($mapped));
        sort($unique);

        return $unique;
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
