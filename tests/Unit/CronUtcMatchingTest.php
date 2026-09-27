<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-06 (issue #160):
 * CronExpression::matchesUtc() must evaluate in UTC regardless of the
 * process timezone.
 *
 * Pre-fix, matchesUtc() used getdate(), which resolves the timestamp in the
 * DEFAULT PROCESS timezone: on a host set to Asia/Jakarta, a daily 09:00 UTC
 * schedule fired at 02:00 UTC instead (09:00 local), and every weekday /
 * day-of-month / month boundary shifted silently. The suite only stayed
 * green because CI defaults to UTC.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\CronExpression;

/**
 * @internal
 */
final class CronUtcMatchingTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    /**
     * The audit repro: a daily 09:00 UTC schedule must match the 09:00 UTC
     * timestamp under every process timezone (Jakarta = UTC+7 would read it
     * as 16:00 local and miss; New York = UTC-5 would read 04:00).
     *
     * @dataProvider provideTimezones
     */
    public function testDailyScheduleMatchesInUtcRegardlessOfProcessTimezone(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $cron = CronExpression::parse('0 9 * * *');
        $nineUtc = new \DateTimeImmutable('2026-03-15T09:00:00+00:00')->getTimestamp();

        self::assertTrue($cron->matchesUtc($nineUtc), "[{$timezone}] 09:00 UTC must match a 09:00 UTC schedule");

        // And the neighbouring hours must not.
        self::assertFalse($cron->matchesUtc($nineUtc - 3600), "[{$timezone}] 08:00 UTC must not match");
        self::assertFalse($cron->matchesUtc($nineUtc + 3600), "[{$timezone}] 10:00 UTC must not match");
    }

    /** Weekday boundaries shift at midnight UTC — a Monday-00:00 UTC schedule must stay a Monday. @dataProvider provideTimezones */
    public function testWeekdayBoundariesAreUtc(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $cron = CronExpression::parse('0 0 * * 1'); // Monday 00:00 UTC
        $mondayUtc = new \DateTimeImmutable('2026-03-16T00:00:00+00:00')->getTimestamp(); // a Monday

        self::assertTrue($cron->matchesUtc($mondayUtc), "[{$timezone}] Monday 00:00 UTC must match");
        self::assertFalse($cron->matchesUtc($mondayUtc - 60), "[{$timezone}] Sunday 23:59 UTC must not match");
        self::assertFalse($cron->matchesUtc($mondayUtc + 86400), "[{$timezone}] Tuesday 00:00 UTC must not match");
    }

    /** dom/month boundaries: Feb 1st UTC stays Feb 1st. @dataProvider provideTimezones */
    public function testCalendarBoundariesAreUtc(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $cron = CronExpression::parse('30 12 1 2 *'); // Feb 1st, 12:30 UTC
        $febFirst = new \DateTimeImmutable('2026-02-01T12:30:00+00:00')->getTimestamp();

        self::assertTrue($cron->matchesUtc($febFirst), "[{$timezone}] Feb 1st 12:30 UTC must match");
        self::assertFalse($cron->matchesUtc($febFirst + 86400), "[{$timezone}] Feb 2nd must not match");
        self::assertFalse($cron->matchesUtc($febFirst + 31 * 86400 - 86400 + 86400), "[{$timezone}] March must not match");
    }

    /** nextRunAfter() must schedule the NEXT UTC occurrence, not a local-time one. @dataProvider provideTimezones */
    public function testNextRunAfterFiresAtTheUtcSlot(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $cron = CronExpression::parse('0 9 * * *');
        $eightUtc = new \DateTimeImmutable('2026-03-15T08:00:00+00:00')->getTimestamp();
        $expected = new \DateTimeImmutable('2026-03-15T09:00:00+00:00')->getTimestamp();

        self::assertSame($expected, intdiv($cron->nextRunAfter($eightUtc * 1_000_000_000), 1_000_000_000), "[{$timezone}] the next slot is 09:00 UTC, not 09:00 local");
    }

    /** Classic cron OR-semantics (dom OR dow when both restricted) hold in UTC too. @dataProvider provideTimezones */
    public function testDomDwUnionSemanticsHoldInUtc(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $cron = CronExpression::parse('0 0 1 * 1'); // 1st of month OR Monday
        $monday = new \DateTimeImmutable('2026-03-16T00:00:00+00:00')->getTimestamp();
        $first = new \DateTimeImmutable('2026-04-01T00:00:00+00:00')->getTimestamp(); // a Wednesday
        $neither = new \DateTimeImmutable('2026-03-17T00:00:00+00:00')->getTimestamp(); // Tuesday the 17th

        self::assertTrue($cron->matchesUtc($monday), "[{$timezone}] Monday matches via dow");
        self::assertTrue($cron->matchesUtc($first), "[{$timezone}] the 1st matches via dom");
        self::assertFalse($cron->matchesUtc($neither), "[{$timezone}] Tuesday the 17th matches neither");
    }

    /** @return iterable<string, list<string>> */
    public static function provideTimezones(): iterable
    {
        yield 'UTC' => ['UTC'];

        yield 'Asia/Jakarta (UTC+7, the audit repro)' => ['Asia/Jakarta'];

        yield 'America/New_York (UTC-5/-4 DST)' => ['America/New_York'];

        yield 'Pacific/Kiritimati (UTC+14, day flips)' => ['Pacific/Kiritimati'];

        yield 'Pacific/Niue (UTC-11, day flips back)' => ['Pacific/Niue'];
    }
}
