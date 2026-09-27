<?php

declare(strict_types=1);

/*
 * ZEF Framework — regression suite for ZEF-DEEP-14 (issue #168).
 *
 * TieredCache L2->L1 promotion used to re-arm the FULL l1TtlSeconds,
 * ignoring the remaining lifetime of the L2 entry, so a value kept being
 * served (stale) past the TTL its original set() asked for. The promotion
 * is now capped at min(l1Ttl, remaining L2 TTL) — floored by contract —
 * and entries already at their deadline are not promoted at all.
 *
 * Every case below drives a single MutableTPClock shared by both tiers,
 * so all boundaries are exact and never sleep-based.
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Cache\TieredCache;

/**
 * @internal
 */
final class TieredCachePromotionTtlTest extends TestCase
{
    private const int SEC = 1_000_000_000;

    private MutableTPClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableTPClock(1_000_000_000 * 1_700_000_000);
    }

    // ------------------------------------------------------------------
    // The audit repro: set(ttl=3) with l1Ttl=2 must be dead by t+3,
    // not served stale from L1 until t+4 (the pre-fix overshoot).
    // ------------------------------------------------------------------

    public function testPromotionNeverOutlivesTheSourceDeadline(): void
    {
        $l1 = $this->memoryCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 2);

        $tiered->set('k', 'v', 3); // L1 dies at t+2 (min(3, cap)), L2 at t+3.

        $this->clock->now += 2 * self::SEC; // L1 miss, L2 live, remaining = 1.
        self::assertSame('v', $tiered->get('k')); // promotes min(2, 1) = 1.

        $this->clock->now += 1 * self::SEC; // t+3: BOTH tiers must be dead.
        self::assertNull($tiered->get('k'), 'stale value must not be served past the set() TTL');
        self::assertFalse($tiered->has('k'));
    }

    // ------------------------------------------------------------------
    // Exact promotion TTLs via the spy L1.
    // ------------------------------------------------------------------

    public function testPromotionIsCappedByRemainingL2Ttl(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v', 100);
        $l1->delete('k'); // evict the hot copy to force the promotion path.

        $this->clock->now += 50 * self::SEC; // remaining = 50 < cap 60.
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(50, $l1->lastTtl, 'promotion TTL must equal the remaining L2 TTL when below the cap');
    }

    public function testConstructorCapStillAppliesWhenLongerThanRemaining(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v', 100);
        $l1->delete('k');

        $this->clock->now += 10 * self::SEC; // remaining = 90 > cap 60.
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(60, $l1->lastTtl, 'constructor cap must apply when the remaining TTL is longer');
    }

    public function testImmediatePromotionUsesExactWholeSeconds(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v', 10);
        $l1->delete('k');

        self::assertSame('v', $tiered->get('k')); // remaining = exactly 10.
        self::assertSame(10, $l1->lastTtl, 'remaining lifetime must floor to exact whole seconds');
    }

    public function testUncappedL1PromotesWithRemainingTtl(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), null);

        $tiered->set('k', 'v', 5);
        $l1->delete('k');

        $this->clock->now += 2 * self::SEC; // remaining = 3, no cap.
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(3, $l1->lastTtl, 'uncapped L1 must still respect the L2 deadline');
    }

    // ------------------------------------------------------------------
    // Sub-second remainders floor to zero => no promotion (never overshoot).
    // ------------------------------------------------------------------

    public function testSubSecondRemainingIsNotPromotedButStillServed(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v', 3);
        $l1->delete('k');

        $this->clock->now += 2 * self::SEC + 500_000_000; // remaining = 0.5s.
        self::assertSame('v', $tiered->get('k'), 'live L2 value is still served');
        self::assertFalse($l1->has('k'), 'a sub-second remainder must not be promoted (would overshoot the deadline)');

        $this->clock->now += 1 * self::SEC; // past the deadline.
        self::assertNull($tiered->get('k'));
    }

    // ------------------------------------------------------------------
    // BC: deadline-less entries and opaque L2 adapters keep the old
    // promotion behaviour (full constructor cap).
    // ------------------------------------------------------------------

    public function testDeadlinelessEntryPromotesWithFullCap(): void
    {
        $l1 = new SpyPromotionCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v'); // no deadline anywhere.
        $l1->delete('k');

        $this->clock->now += 100 * self::SEC;
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(60, $l1->lastTtl, 'deadline-less entries keep the constructor cap on promotion');
    }

    public function testOpaqueL2FallsBackToConstructorCap(): void
    {
        $l1 = new SpyPromotionCache();
        $opaqueL2 = new SpyPromotionCache(); // plain CacheInterface, no introspection.
        $tiered = new TieredCache($l1, $opaqueL2, 60);

        $tiered->set('k', 'v', 100);
        $l1->delete('k');

        $this->clock->now += 50 * self::SEC;
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(60, $l1->lastTtl, 'opaque L2 adapters keep the pre-fix promotion (bounded by the cap only)');
    }

    // ------------------------------------------------------------------
    // The HIGH-severity window: default cap 60 must not stretch a short
    // TTL entry past its deadline (pre-fix served stale up to t+61).
    // ------------------------------------------------------------------

    public function testStaleServeWindowIsBoundedByUserTtl(): void
    {
        $l1 = $this->memoryCache();
        $tiered = new TieredCache($l1, $this->memoryCache(), 60);

        $tiered->set('k', 'v', 10);
        $l1->delete('k');

        $this->clock->now += 9 * self::SEC; // remaining = 1.
        self::assertSame('v', $tiered->get('k')); // promotes min(60, 1) = 1.

        $this->clock->now += 1 * self::SEC + 1; // t+10: deadline reached.
        self::assertFalse($tiered->has('k'), 'entry must be gone exactly at the user TTL');

        $this->clock->now += 50 * self::SEC; // t+60: deep inside the old stale window.
        self::assertNull($tiered->get('k'), 'the stale serve window may not extend past the user TTL');
    }

    // ------------------------------------------------------------------
    // TaggableCache wrapping an L2 forwards the remaining TTL.
    // ------------------------------------------------------------------

    public function testTaggableL2ForwardsRemainingTtl(): void
    {
        $l1 = new SpyPromotionCache();
        $taggedL2 = new TaggableCache($this->memoryCache());
        $tiered = new TieredCache($l1, $taggedL2, 60);

        $tiered->set('k', 'v', 100);
        $l1->delete('k');

        $this->clock->now += 50 * self::SEC; // remaining = 50 via delegation.
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(50, $l1->lastTtl, 'TaggableCache must forward remaining-TTL introspection to its inner cache');
    }

    public function testTaggableOpaqueInnerStaysOpaque(): void
    {
        $l1 = new SpyPromotionCache();
        $taggedL2 = new TaggableCache(new SpyPromotionCache()); // inner cannot introspect.
        $tiered = new TieredCache($l1, $taggedL2, 60);

        $tiered->set('k', 'v', 100);
        $l1->delete('k');

        $this->clock->now += 50 * self::SEC;
        self::assertSame('v', $tiered->get('k'));
        self::assertSame(60, $l1->lastTtl, 'a TaggableCache over an opaque inner keeps the cap-only promotion');
    }

    // ------------------------------------------------------------------
    // InMemoryCache::getRemainingTtlSeconds() contract.
    // ------------------------------------------------------------------

    public function testMemoryCacheReportsFlooredRemainingTtl(): void
    {
        $cache = $this->memoryCache();

        self::assertNull($cache->getRemainingTtlSeconds('absent'), 'absent keys report null');

        $cache->set('none', 'v');
        self::assertNull($cache->getRemainingTtlSeconds('none'), 'deadline-less entries report null');

        $cache->set('k', 'v', 10);
        $this->clock->now += 3 * self::SEC + 500_000_000; // 6.5s remaining.
        self::assertSame(6, $cache->getRemainingTtlSeconds('k'), 'remaining TTL floors to whole seconds');

        $cache->set('short', 'v', 2);
        $this->clock->now += 2 * self::SEC; // store purges at the deadline.
        self::assertNull($cache->getRemainingTtlSeconds('short'), 'expired keys are absent again');
    }

    public function testRemainingTtlFloorsExactNanosBelowASecondMultiple(): void
    {
        $cache = $this->memoryCache();

        $cache->set('k', 'v', 2); // expiry at now + exactly 2s.
        $this->clock->now += 2; // 1_999_999_998 ns remaining — floors to 1, never rounds up to 2.
        self::assertSame(1, $cache->getRemainingTtlSeconds('k'));
    }

    public function testRemainingTtlNeverReportsNegativePastTheDeadline(): void
    {
        // Scripted ticks: the store's expiry check reads the clock just before
        // the deadline, the introspection's own clock read happens >1s after
        // it — the defensive floor must still report 0, never a negative TTL.
        $clock = new ScriptedTickClock(1_000_000_000 * 1_700_000_000, [0, 900_000_000, 1_200_000_000]);
        $cache = new InMemoryCache(new InMemoryCacheStore(50, $clock), clock: $clock);

        $cache->set('k', 'v', 1);
        self::assertSame(0, $cache->getRemainingTtlSeconds('k'));
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    private function memoryCache(): InMemoryCache
    {
        return new InMemoryCache(new InMemoryCacheStore(50, $this->clock), clock: $this->clock);
    }
}

/**
 * @internal
 */
final class MutableTPClock implements CacheClockInterface
{
    public function __construct(public int $now) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        return $this->now;
    }
}

/**
 * @internal
 */
final class ScriptedTickClock implements CacheClockInterface
{
    private int $tickIndex = 0;

    /** @param list<int> $ticksNano increments applied on each successive read */
    public function __construct(private int $now, private readonly array $ticksNano) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        $tick = $this->ticksNano[$this->tickIndex] ?? 0;
        ++$this->tickIndex;
        $this->now += $tick;

        return $this->now;
    }
}

/**
 * @internal
 */
final class SpyPromotionCache implements CacheInterface
{
    public int $lastTtl = -1;

    /** @var array<string,mixed> */
    public array $items = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->items[$key] = $value;
        $this->lastTtl = $ttlSeconds ?? -1;
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->items);
    }

    #[\Override]
    public function clear(): void
    {
        $this->items = [];
    }
}
