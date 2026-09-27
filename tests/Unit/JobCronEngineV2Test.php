<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\CronExpression;

/**
 * v2.31.0 — Cron Engine v2: UTC-exact matching (immune to the process
 * timezone), day-of-week 7 normalization, static never-fires detection with
 * fast-fail nextRunAfter(), and the timezone-aware matchesIn/nextRunAfterIn
 * API. Regression coverage for audit findings C-6, N-14 and P-19.
 *
 * @internal
 */
final class JobCronEngineV2Test extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        $this->originalTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTz);
    }

    // -------------------------------------------------- UTC exactness (C-6)

    public function testMatchesUtcIgnoresProcessTimezone(): void
    {
        date_default_timezone_set('Asia/Jakarta'); // UTC+7, the audit's repro
        $cron = CronExpression::parse('0 9 * * *');

        self::assertTrue($cron->matchesUtc($this->tsUtc('2026-06-15T09:00:00+00:00')));
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-06-15T09:01:00+00:00')));
        // 09:00 WIB = 02:00 UTC — must NOT match a UTC 9-o'clock schedule
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-06-15T02:00:00+00:00')));
    }

    public function testNextRunAfterIsUtcAnchored(): void
    {
        date_default_timezone_set('Asia/Jakarta');
        $cron = CronExpression::parse('0 9 * * *');

        $next = $cron->nextRunAfter($this->nano($this->tsUtc('2026-06-15T00:00:00+00:00')));
        self::assertSame(
            $this->nano($this->tsUtc('2026-06-15T09:00:00+00:00')),
            $next,
            'must fire at 09:00 UTC, not at 02:00 UTC (= 09:00 process-local)',
        );
    }

    public function testMatchesUtcStillCorrectUnderUtcProcessTimezone(): void
    {
        date_default_timezone_set('UTC');
        $cron = CronExpression::parse('*/15 * * * *');

        self::assertTrue($cron->matchesUtc($this->tsUtc('2026-09-20T00:00:00+00:00')));
        self::assertTrue($cron->matchesUtc($this->tsUtc('2026-09-20T12:45:00+00:00')));
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-09-20T12:46:00+00:00')));
    }

    // -------------------------------------------------- day-of-week 7 (N-14)

    public function testDowSevenIsAcceptedAndMeansSunday(): void
    {
        $seven = CronExpression::parse('0 0 * * 7');
        $zero = CronExpression::parse('0 0 * * 0');
        $sunday = $this->tsUtc('2026-06-21T00:00:00+00:00'); // gmdate('w') === '0'

        self::assertSame([0], $seven->daysOfWeek, '7 normalizes to 0 (Sunday)');
        self::assertTrue($seven->matchesUtc($sunday));
        self::assertTrue($zero->matchesUtc($sunday));

        $monday = $this->nano($this->tsUtc('2026-06-15T00:00:00+00:00'));
        self::assertSame($zero->nextRunAfter($monday), $seven->nextRunAfter($monday));
        self::assertSame($this->nano($sunday), $seven->nextRunAfter($monday));
    }

    public function testDowRangesAcceptSeven(): void
    {
        $range = CronExpression::parse('0 0 * * 5-7');
        self::assertSame([0, 5, 6], $range->daysOfWeek, '7 collapses onto Sunday and the list stays sorted');

        $full = CronExpression::parse('0 0 * * 0-7');
        self::assertSame(range(0, 6), $full->daysOfWeek);
        self::assertFalse($full->dowRestricted, '0-7 collapses to the full week = wildcard');
    }

    public function testDowListWithSeven(): void
    {
        $list = CronExpression::parse('0 0 * * 1,7');
        self::assertSame([0, 1], $list->daysOfWeek);
    }

    public function testDowEightStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CronExpression::parse('0 0 * * 8');
    }

    // -------------------------------------------------- never-fires (P-19)

    public function testImpossibleDomMonthCombinationFailsFast(): void
    {
        $cron = CronExpression::parse('0 0 31 2 *'); // February never has a 31st
        self::assertTrue($cron->isNeverFire());
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-02-01T00:00:00+00:00')));

        $start = hrtime(true);

        try {
            $cron->nextRunAfter($this->nano($this->tsUtc('2026-01-01T00:00:00+00:00')));
            self::fail('never-firing expression must throw instead of scanning 4 years');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('never fire', $e->getMessage());
        }
        $elapsedNs = hrtime(true) - $start;
        self::assertLessThan(500_000_000, $elapsedNs, 'must fail fast (legacy scan burned ~1.3s)');
    }

    public function testFebruaryTwentyNineIsPossible(): void
    {
        $cron = CronExpression::parse('30 2 29 2 *');
        self::assertFalse($cron->isNeverFire(), 'February reaches 29 on leap years');
        self::assertTrue($cron->matchesUtc($this->tsUtc('2028-02-29T02:30:00+00:00')));
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-02-28T02:30:00+00:00')));
    }

    public function testNeverFiresDetectionMatrix(): void
    {
        self::assertTrue(CronExpression::parse('0 0 31 2,4,6,9,11 *')->isNeverFire());
        self::assertTrue(CronExpression::parse('0 0 30 2 *')->isNeverFire());
        self::assertFalse(CronExpression::parse('0 0 30 2,4 *')->isNeverFire(), 'April reaches 30');
        self::assertFalse(CronExpression::parse('0 0 31 * *')->isNeverFire(), 'wildcard months always qualify');
        self::assertFalse(CronExpression::parse('0 0 * 2 *')->isNeverFire(), 'wildcard dom always qualifies');
    }

    // -------------------------------------------------- timezone-aware API

    public function testMatchesInEvaluatesInGivenTimezone(): void
    {
        $cron = CronExpression::parse('0 9 * * *');
        $jakarta = new \DateTimeZone('Asia/Jakarta');

        self::assertTrue($cron->matchesIn($jakarta, $this->tsUtc('2026-06-15T02:00:00+00:00')), '09:00 WIB');
        self::assertFalse($cron->matchesIn($jakarta, $this->tsUtc('2026-06-15T09:00:00+00:00')), '09:00 UTC = 16:00 WIB');
        self::assertFalse($cron->matchesUtc($this->tsUtc('2026-06-15T02:00:00+00:00')), 'UTC matcher disagrees — independent contracts');
    }

    public function testNextRunAfterInIsTimezoneAnchored(): void
    {
        $cron = CronExpression::parse('0 9 * * *');

        $next = $cron->nextRunAfterIn(
            new \DateTimeZone('Asia/Jakarta'),
            $this->nano($this->tsUtc('2026-06-15T00:00:00+00:00')),
        );
        self::assertSame(
            $this->nano($this->tsUtc('2026-06-15T02:00:00+00:00')),
            $next,
            '09:00 WIB = 02:00 UTC',
        );

        $nextUtc = $cron->nextRunAfterIn(
            new \DateTimeZone('UTC'),
            $this->nano($this->tsUtc('2026-06-15T00:00:00+00:00')),
        );
        self::assertSame(
            $this->nano($this->tsUtc('2026-06-15T09:00:00+00:00')),
            $nextUtc,
        );
    }

    public function testNextRunAfterInNearDstBoundaryUsesLocalWallClock(): void
    {
        // Europe/Amsterdam: 2026-03-29 is the DST transition (CET → CEST).
        // A 02:30 local schedule simply does not exist that day (02:00 → 03:00);
        // the scan must continue to the next matching local minute without error.
        $cron = CronExpression::parse('30 2 * * *');
        $ams = new \DateTimeZone('Europe/Amsterdam');

        $next = $cron->nextRunAfterIn($ams, $this->nano($this->tsUtc('2026-03-29T00:00:00+00:00')));
        $nextLocal = new \DateTimeImmutable('@' . intdiv($next, 1_000_000_000))->setTimezone($ams);
        self::assertSame('2026-03-30', $nextLocal->format('Y-m-d'), '02:30 local does not exist on the transition day');
        self::assertSame('02:30', $nextLocal->format('H:i'));
    }

    // -------------------------------------------------- semantics preserved

    public function testDomDowOrSemanticsPreserved(): void
    {
        $both = CronExpression::parse('0 0 1 * 1'); // dom restricted AND dow restricted → OR

        self::assertTrue($both->matchesUtc($this->tsUtc('2026-06-01T00:00:00+00:00')), 'Monday AND the 1st');
        self::assertTrue($both->matchesUtc($this->tsUtc('2026-06-08T00:00:00+00:00')), 'a plain Monday');
        self::assertFalse($both->matchesUtc($this->tsUtc('2026-06-02T00:00:00+00:00')), 'Tuesday the 2nd: neither');
    }

    public function testDescribeUnchanged(): void
    {
        self::assertSame('30 2 29 2 * (UTC)', CronExpression::parse('30 2 29 2 *')->describe());
    }

    private function tsUtc(string $iso): int
    {
        return new \DateTimeImmutable($iso)->getTimestamp();
    }

    private function nano(int $unixSeconds): int
    {
        return $unixSeconds * 1_000_000_000;
    }
}
