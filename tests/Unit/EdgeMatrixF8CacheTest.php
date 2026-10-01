<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — Fase 8 (mutation round 3): Infrastructure\Cache zone.
 * Satu test dirancang membunuh satu mutan spesifik yang lolos pada baseline
 * f8-infra-a (TaggableCache 21, TieredCache 8, DefaultCacheKeyNormalizer 5,
 * InMemoryCache 4, InMemoryCacheStore 5). Mutan ±1 integer pada batas kapasitas
 * dibunuh dua arah (in-range + out-of-range).
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\Cache\CacheItem;
use Zef\Framework\Cache\DefaultCacheKeyNormalizer;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\SystemCacheClock;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Cache\TieredCache;

/**
 * @internal
 */
final class EdgeMatrixF8CacheTest extends TestCase
{
    // ------------------------------------------------------------------
    // TaggableCache
    // ------------------------------------------------------------------

    /** Membunuh UnwrapArrayUnique:38 — duplikat tag harus didedup sebelum cek 16. */
    public function testSetWithTagsDeduplicatesBeforeMaxCount(): void
    {
        $tags = [];
        for ($i = 0; $i < 16; ++$i) {
            $tags[] = 'tag' . $i;
        }
        $tags[] = 'tag0'; // duplikat → tanpa unique: count 17 → throw

        $cache = new TaggableCache($this->rawStore());
        $cache->setWithTags('k', 1, null, $tags);

        self::assertSame(16, \count($cache->tagsFor('k')));
    }

    /** Membunuh GreaterThan:39 dua arah — 16 sah, 17 throw dengan pesan persis. */
    public function testMaxTagsBoundaryIsExactlySixteen(): void
    {
        $cache = new TaggableCache($this->rawStore());
        $tags = [];
        for ($i = 0; $i < 16; ++$i) {
            $tags[] = 't' . $i;
        }
        $cache->setWithTags('k', 1, null, $tags); // == 16 sah
        self::assertNotNull($cache->get('k'));

        $tooMany = $tags;
        $tooMany[] = 't99';

        try {
            $cache->setWithTags('k2', 1, null, $tooMany); // 17 → throw
            self::fail('17 tags must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Too many tags for a single key (max 16).', $e->getMessage());
        }
    }

    /** Membunuh PregMatchRemoveCaret:44 + Throw_:45 — tag berawalan invalid. */
    public function testTagGrammarRejectsBadPrefix(): void
    {
        $cache = new TaggableCache($this->rawStore());

        try {
            $cache->setWithTags('k', 1, null, ['!!bad']);
            self::fail('tag "!!bad" must be rejected (caret anchor).');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid cache tag '!!bad'.", $e->getMessage());
        }
    }

    /** Membunuh PregMatchRemoveDollar:44 — tag berakhiran invalid. */
    public function testTagGrammarRejectsBadSuffix(): void
    {
        $cache = new TaggableCache($this->rawStore());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid cache tag 'bad!!'.");
        $cache->setWithTags('k', 1, null, ['bad!!']);
    }

    /** Membunuh LogicalOr:44 — tag non-string harus dilempar sebagai InvalidArgument. */
    public function testTagMustBeString(): void
    {
        $cache = new TaggableCache($this->rawStore());
        // Mutan && mengubah lemparan menjadi TypeError (preg_match(int)) —
        // expectException gagal pada TypeError → mutan terbunuh.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid cache tag '5'.");
        $cache->setWithTags('k', 1, null, [5]); // @phpstan-ignore-line
    }

    /** Membunuh PregMatchRemoveCaret/Dollar:63 pada invalidateTag. */
    public function testInvalidateTagGrammarIsAnchored(): void
    {
        $cache = new TaggableCache($this->rawStore());

        try {
            $cache->invalidateTag('!!bad');
            self::fail('invalidateTag("!!bad") must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid cache tag '!!bad'.", $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid cache tag 'bad!'.");
        $cache->invalidateTag('bad!');
    }

    /** Membunuh MethodCallRemoval:73, MethodCallRemoval/Concat×3:75 — index internal wajib dibersihkan. */
    public function testInvalidateTagPurgesTagAndReverseIndexes(): void
    {
        $inner = $this->rawStore();
        $cache = new TaggableCache($inner);
        $cache->setWithTags('k', 1, null, ['t']);

        self::assertSame(1, $cache->invalidateTag('t'));
        // Mutan yang melompati delete() membiarkan key internal masih ada.
        self::assertFalse($inner->has("\0zef-tag:t"), 'tag index must be deleted');
        self::assertFalse($inner->has("\0zef-keytags:k"), 'reverse index must be deleted');
        self::assertSame([], $cache->tagsFor('k'));
    }

    /** Membunuh ReturnRemoval:160 — writeKeyTags([]) tidak boleh menulis "[]". */
    public function testInvalidateTagDoesNotResurrectReverseKey(): void
    {
        $inner = $this->rawStore();
        $cache = new TaggableCache($inner);
        $cache->setWithTags('k', 1, null, ['t']);
        $cache->invalidateTag('t');
        // Mutan return-removal menulis ulang reverse key berisi "[]".
        self::assertFalse($inner->has("\0zef-keytags:k"));
    }

    /** Membunuh UnwrapArrayFilter:94 — anggota non-string dibuang. */
    public function testTagsForDropsNonStringEntries(): void
    {
        $inner = $this->rawStore();
        $cache = new TaggableCache($inner);
        $inner->set("\0zef-keytags:k", json_encode(['a', 5, 'b', null], JSON_THROW_ON_ERROR));

        self::assertSame(['a', 'b'], $cache->tagsFor('k'));
    }

    /** Membunuh UnwrapArrayValues:94 — hasil wajib list (kunci JSON asosiatif dinormalkan). */
    public function testTagsForReturnsPlainList(): void
    {
        $inner = $this->rawStore();
        $cache = new TaggableCache($inner);
        $inner->set("\0zef-keytags:k", '{"3":"x","7":"y"}');

        self::assertSame(['x', 'y'], $cache->tagsFor('k'));
    }

    /** Membunuh UnwrapArrayFilter/Values:138 via anggota tag korup yang tetap dipakai. */
    public function testCorruptedTagMembersAreSanitizedOnInvalidate(): void
    {
        $inner = $this->rawStore();
        $cache = new TaggableCache($inner);
        $cache->setWithTags('k', 1, null, ['t']);
        // Korupsi index SETELAH penulisan normal: berisi non-string.
        $inner->set("\0zef-tag:t", json_encode(['k', 9, ''], JSON_THROW_ON_ERROR));
        $cache->setWithTags('k2', 2, null, ['t']);
        // invalidateTag harus menghapus k dan k2 (bukan mencoba menghapus 9/'').
        self::assertSame(2, $cache->invalidateTag('t'));
        self::assertFalse($inner->has('k'));
        self::assertFalse($inner->has('k2'));
    }

    /** Membunuh jalur passthrough + guard reserved prefix. */
    public function testReservedPrefixesAndEmptyKeysAreRejected(): void
    {
        $cache = new TaggableCache($this->rawStore());
        foreach ([
            "\0zef-tag:x",
            "\0zef-keytags:x",
            '',
        ] as $bad) {
            try {
                $cache->get($bad);
                self::fail('key ' . var_export($bad, true) . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(
                    $e->getMessage() === 'Cache key must not be empty.'
                    || $e->getMessage() === 'Cache key uses a reserved internal prefix.',
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // TieredCache
    // ------------------------------------------------------------------

    /** Membunuh Decrement/IncrementInteger:25 — default l1 TTL = 60 persis. */
    public function testDefaultL1TtlIsExactly60(): void
    {
        $l1 = $this->spyCache();
        $l2 = new InMemoryCache(new InMemoryCacheStore(50, $this->mutableClock()), clock: $this->mutableClock());
        $tiered = new TieredCache($l1, $l2);
        $tiered->set('k', 'v');

        self::assertSame(60, $l1->lastTtl);
    }

    /** Membunuh NotIdentical/LessThan/LogicalAnd:27 — batas guard TTL L1. */
    public function testL1TtlGuardBoundaries(): void
    {
        $ok = new TieredCache(new InMemoryCache(new InMemoryCacheStore(5)), new InMemoryCache(new InMemoryCacheStore(5)), 1); // sah
        self::assertNotNull($ok); // @phpstan-ignore-line
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('L1 TTL must be >= 1 second (or null).');
        new TieredCache(new InMemoryCache(new InMemoryCacheStore(5)), new InMemoryCache(new InMemoryCacheStore(5)), 0);
    }

    /** Membunuh LogicalAndAllSubExprNegation:27 — null TTL sah (tidak melempar). */
    public function testNullL1TtlIsAccepted(): void
    {
        $tiered = new TieredCache(new InMemoryCache(new InMemoryCacheStore(5)), new InMemoryCache(new InMemoryCacheStore(5)), null);
        self::assertNotNull($tiered); // @phpstan-ignore-line
        $tiered->set('k', 1);
        self::assertTrue($tiered->has('k'));
    }

    /** Membunuh Identical:52 — TTL null pada set() mengalirkan l1TtlSeconds (60), bukan min(null,..). */
    public function testNullSetTtlFallsBackToConstructorTtl(): void
    {
        $l1 = $this->spyCache();
        $l2 = new InMemoryCache(new InMemoryCacheStore(50, $this->mutableClock()), clock: $this->mutableClock());
        $tiered = new TieredCache($l1, $l2, 60);
        $tiered->set('k', 'v');

        self::assertSame(60, $l1->lastTtl);
    }

    /** Membunuh NotIdentical:54 — min(ttl, l1TtlSeconds) diterapkan saat keduanya int. */
    public function testExplicitTtlIsCappedByConstructorTtl(): void
    {
        $l1 = $this->spyCache();
        $l2 = new InMemoryCache(new InMemoryCacheStore(50, $this->mutableClock()), clock: $this->mutableClock());
        $tiered = new TieredCache($l1, $l2, 10);

        $tiered->set('a', 1, 30);
        self::assertSame(10, $l1->lastTtl, 'L1 TTL harus min(30, 10) = 10');

        $tiered->set('b', 1, 5);
        self::assertSame(5, $l1->lastTtl, 'L1 TTL harus min(5, 10) = 5');
    }

    /** Membunuh LogicalOr:70 + promosi L2→L1. */
    public function testHasSpansTiersAndGetPromotesToL1(): void
    {
        $l1 = $this->spyCache();
        $clock = $this->mutableClock();
        $l2 = new InMemoryCache(new InMemoryCacheStore(50, $clock), clock: $clock);
        $l2->set('onlyL2', 'x');
        $tiered = new TieredCache($l1, $l2, 60);

        self::assertTrue($tiered->has('onlyL2'), 'L2-only key must be visible');
        self::assertSame('x', $tiered->get('onlyL2'));
        self::assertSame('x', $l1->lastValue, 'hit L2 harus dipromosikan ke L1');
        self::assertSame(60, $l1->lastTtl);
        self::assertFalse($tiered->has('missing'));
    }

    // ------------------------------------------------------------------
    // DefaultCacheKeyNormalizer
    // ------------------------------------------------------------------

    /** Membunuh GreaterThan:19 — panjang tepat 250 sah. */
    public function testKeyLength250IsAccepted(): void
    {
        $key = str_repeat('a', 250);
        self::assertSame($key, new DefaultCacheKeyNormalizer()->normalize($key));
    }

    /** Membunuh LogicalOr×2:19 — 251 karakter tata-bahasa valid TETAP ditolak. */
    public function testKeyLength251ValidGrammarIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache key.');
        new DefaultCacheKeyNormalizer()->normalize(str_repeat('a', 251));
    }

    /** Membunuh caret/dollar sisa:19 + short-invalid untuk mutan &&. */
    public function testKeyGrammarIsFullyAnchored(): void
    {
        $n = new DefaultCacheKeyNormalizer();
        // Audit #327: whitespace-padded variants join the rejected set —
        // trim() used to alias ' user:42 ' onto 'user:42'.
        foreach (['!abc', 'abc!', 'a b', 'a#', '', ' k ', " k \t", "\nk", 'k '] as $bad) {
            try {
                $n->normalize($bad);
                self::fail('key ' . var_export($bad, true) . ' must be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid cache key.', $e->getMessage());
            }
        }
        self::assertSame('k', $n->normalize('k'));
        self::assertSame('a.b:c/d-e_9', $n->normalize('a.b:c/d-e_9'));
        // Audit #327: TaggableCache routing keys keep their NUL marker verbatim
        // (a plain user key 'zef-tag:x' can never collide with the index).
        self::assertSame("\0zef-tag:products", $n->normalize("\0zef-tag:products"));
        self::assertSame("\0zef-keytags:k", $n->normalize("\0zef-keytags:k"));

        try {
            $n->normalize("\0other");
            self::fail('an unreserved NUL-prefixed key must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid cache key.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // InMemoryCache (precision + TTL guard)
    // ------------------------------------------------------------------

    /** Membunuh LessThan:32 + LogicalAndAllSubExprNegation:32 + 999999999/1000000001:37. */
    public function testExpiryPrecisionUsesExactlyOneBillionNanosPerSecond(): void
    {
        $clock = $this->mutableClock(1_000_000_000_000);
        $cache = new InMemoryCache(new InMemoryCacheStore(50, $clock), clock: $clock);

        try {
            $cache->set('bad', 1, 0);
            self::fail('TTL 0 must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Cache TTL must be positive.', $e->getMessage());
        }

        $cache->set('k', 'v', 2); // expiresAt = t0 + 2e9 persis
        $cache->set('one', 'v', 1); // TTL tepat 1 sah (membunuh <=)
        $clock->now = 1_000_000_000_000 + 1_000_000_000 - 1;
        self::assertTrue($cache->has('one'), 'TTL 1 tetap hidup sampai nanodetik terakhir');
        $clock->now = 1_000_000_000_000 + 2_000_000_000 - 1;
        self::assertTrue($cache->has('k'), '1 nanodetik sebelum kadaluarsa masih hidup');
        self::assertSame('v', $cache->get('k'));

        $clock->now = 1_000_000_000_000 + 2_000_000_000;
        self::assertFalse($cache->has('k'), 'tepat di kadaluarsa sudah mati');
        self::assertSame('def', $cache->get('k', 'def'));
    }

    /** Membunuh jalur TTL null — tidak pernah kadaluarsa. */
    public function testNullTtlNeverExpires(): void
    {
        $clock = $this->mutableClock(5_000_000_000_000);
        $cache = new InMemoryCache(new InMemoryCacheStore(50, $clock), clock: $clock);
        $cache->set('k', 'v');
        $clock->now += 400 * 86400 * 1_000_000_000; // +400 hari
        self::assertSame('v', $cache->get('k'));
    }

    // ------------------------------------------------------------------
    // InMemoryCacheStore (kapasitas & eviksi)
    // ------------------------------------------------------------------

    /** Membunuh LessThan:24 dua arah — kapasitas 1 sah, 0 ditolak. */
    public function testStoreCapacityGuard(): void
    {
        self::assertNotNull(new InMemoryCacheStore(1)); // @phpstan-ignore-line
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache capacity must be positive.');
        new InMemoryCacheStore(0);
    }

    /** Membunuh GreaterThanOrEqualTo:48 — eviksi tepat saat count == max. */
    public function testEvictionHappensExactlyAtCapacity(): void
    {
        $store = new InMemoryCacheStore(2);
        $store->set('a', new CacheItem(1));
        $store->set('b', new CacheItem(2));
        $store->set('c', new CacheItem(3)); // penuh → evict a

        self::assertFalse($store->has('a'));
        self::assertTrue($store->has('b'));
        self::assertTrue($store->has('c'));
        self::assertSame(2, $store->size());
    }

    /** Membunuh LogicalNot:48 — overwrite key yang sudah ada TIDAK boleh mengevict. */
    public function testOverwriteAtCapacityDoesNotEvictOthers(): void
    {
        $store = new InMemoryCacheStore(2);
        $store->set('a', new CacheItem(1));
        $store->set('b', new CacheItem(2));
        $store->set('a', new CacheItem(11)); // overwrite, bukan entry baru

        self::assertSame(11, $store->get('a')?->value);
        self::assertTrue($store->has('b'), 'overwrite tidak boleh mengevict tetangga');
    }

    /** Membunuh Decrement/IncrementInteger:21 — default kapasitas 10000 persis. */
    public function testDefaultCapacityIsExactlyTenThousand(): void
    {
        $store = new InMemoryCacheStore();
        for ($i = 0; $i <= 10_000; ++$i) {
            $store->set('k' . $i, new CacheItem($i));
        }
        self::assertFalse($store->has('k0'), 'k0 ter-evict oleh k10000');
        self::assertTrue($store->has('k1'));
        self::assertTrue($store->has('k10000'));
    }

    // ------------------------------------------------------------------
    // SystemCacheClock (P-24, issue #172)
    // ------------------------------------------------------------------

    /** Regresi P-24 (issue #172): unix-ns nyata (~1.77e18), bukan hrtime sejak boot (~4.2e12). */
    public function testSystemClockReturnsUnixNanoMagnitude(): void
    {
        $now = new SystemCacheClock()->nowUnixNano();

        self::assertGreaterThan(1_000_000_000_000_000_000, $now, 'hrtime sejak boot hanya ~4.2e12');
        $reference = (int) round(microtime(true) * 1_000_000_000);
        self::assertLessThan(5_000_000_000, abs($now - $reference), 'harus ~microtime dalam toleransi 5 detik');
    }

    /** Regresi P-24: deadline yang ditulis clock ini kedaluwarsa relatif kepadanya. */
    public function testCacheItemWrittenWithSystemClockExpiresRelativeToIt(): void
    {
        $clock = new SystemCacheClock();
        $store = new InMemoryCacheStore(50, $clock);
        $cache = new InMemoryCache($store, clock: $clock);

        $cache->set('fresh', 'v', 3600);
        self::assertSame('v', $cache->get('fresh'));
        self::assertGreaterThanOrEqual(3590, $cache->getRemainingTtlSeconds('fresh'));
        self::assertLessThanOrEqual(3600, $cache->getRemainingTtlSeconds('fresh'));

        // Deadline tepat satu nanodetik di masa lalu → expired (inclusive >=).
        $store->set('stale', new CacheItem('old', $clock->nowUnixNano() - 1));
        self::assertNull($store->get('stale'));
        self::assertFalse($cache->has('stale'));

        // Jalur default CacheItem::isExpired() (wall clock unix-ns, tanpa
        // argumen) juga menganggap deadline masa lalu sebagai expired.
        self::assertTrue(new CacheItem('old', $clock->nowUnixNano() - 1_000_000_000)->isExpired());
        self::assertFalse(new CacheItem('v', $clock->nowUnixNano() + 10_000_000_000)->isExpired());
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Spy CacheInterface yang merekam TTL set() dan menyimpan nilai sendiri. */
    private function spyCache(): SpyTtlCache
    {
        return new SpyTtlCache();
    }

    private function mutableClock(int $startNano = 1_000_000_000_000): MutableF8Clock
    {
        return new MutableF8Clock($startNano);
    }

    /** Store mentah (tanpa normalizer) sebagai inner TaggableCache — kunci internal dibaca langsung. */
    private function rawStore(): SpyTtlCache
    {
        return new SpyTtlCache();
    }
}

/**
 * @internal
 */
final class SpyTtlCache implements CacheInterface
{
    public int $lastTtl = -1;
    public mixed $lastValue = null;

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
        $this->lastValue = $value;
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

/**
 * @internal
 */
final class MutableF8Clock implements CacheClockInterface
{
    public function __construct(public int $now) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        return $this->now;
    }
}
