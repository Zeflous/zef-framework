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
    // Audit #325: leap-aware bound. One 4-year "leap cycle" is NOT enough:
    // across a non-leap century year (2100) the gap between consecutive
    // February 29s is exactly 2922 days (2096-02-29 -> 2104-02-29), so a
    // Feb-29-only expression asked from 2096/2097 missed its next fire and
    // threw a spurious "no matching minute". Eight full years counted as
    // worst-case leap years (8*366 = 2928 days) covers that gap with
    // margin, including the strictly-after-"now" off-by-one.
    private const int MAX_SCAN_MINUTES = 8 * 366 * 24 * 60;

    private const string NEVER_FIRES_MESSAGE = <<<'MSG'
        Cron expression '%s' can never fire (the restricted day-of-month has no valid date in the restricted months).
        MSG;

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
        $split = preg_split('/\s+/u', $expression);
        $fields = is_array($split) ? $split : [];
        if (count($fields) !== 5) {
            throw new \InvalidArgumentException(
                "Cron expression '{$expression}' must have exactly 5 fields (minute hour dom month dow).",
            );
        }
        $parsed = [];
        foreach (array_combine(['minute', 'hour', 'dom', 'month', 'dow'], $fields) as $field => $raw) {
            $parsed[$field] = CronFieldParser::parseField($field, $raw);
        }
        $parsed['dow'] = CronFieldParser::normalizeDow($parsed['dow']);
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
            CronFieldParser::computeNeverFires($parsed['dom'], $parsed['month'], !$domWildcard),
        );
    }

    #[\Override]
    public function nextRunAfter(int $nowUnixNano): int
    {
        $this->assertNonNegativeTime($nowUnixNano);
        $this->assertFires();

        return $this->scanNext(fn (int $unixSeconds): bool => $this->matchesUtc($unixSeconds), $nowUnixNano);
    }

    /**
     * Next run strictly after $nowUnixNano, evaluated in $timeZone local
     * wall-clock time (v2.31.0). Same whole-minute alignment and scan budget
     * as {@see nextRunAfter()}.
     */
    public function nextRunAfterIn(\DateTimeZone $timeZone, int $nowUnixNano): int
    {
        $this->assertNonNegativeTime($nowUnixNano);
        $this->assertFires();

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

    private function assertNonNegativeTime(int $nowUnixNano): void
    {
        if ($nowUnixNano < 0) {
            throw new \InvalidArgumentException('Cron time must be non-negative.');
        }
    }

    private function assertFires(): void
    {
        if (!$this->neverFires) {
            return;
        }

        throw new CronExpressionException(sprintf(
            self::NEVER_FIRES_MESSAGE,
            $this->expression,
        ));
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

        throw new CronExpressionException(
            "Cron expression '{$this->expression}' has no matching minute within 8 years.",
        );
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
        if (
            !in_array($minute, $this->minutes, true)
            || !in_array($hour, $this->hours, true)
            || !in_array($month, $this->months, true)
        ) {
            return false;
        }
        $domMatch = in_array($dom, $this->daysOfMonth, true);
        $dowMatch = in_array($dow, $this->daysOfWeek, true);
        if ($this->domRestricted && $this->dowRestricted) {
            return $domMatch || $dowMatch;
        }

        return $domMatch && $dowMatch;
    }
}
