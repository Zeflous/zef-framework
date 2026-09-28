<?php

declare(strict_types=1);

/*
 * ZEF Framework — regression suite for ZEF-DEEP-15 (issue #169):
 * Security/Storage/Cache edge cases from the deep audit.
 *
 * - P-1  ApcuRateLimiter: fixed-window bucketing replaces the two-entry
 *        window+counter scheme, removing the check-then-act rollover race.
 * - P-8  CurlS3HttpTransport: bodyless PUT/DELETE carry an explicit
 *        Content-Length: 0 (real S3 answers 411 Length Required without it;
 *        MinIO tolerates the omission, hiding it in dev).
 * - P-9  S3CompatibleStorage: ListObjectsV2 pagination is followed until
 *        IsTruncated is false and S3's lexicographic order is preserved.
 * - P-10 TaggableCache: the per-tag index gets a sliding TTL lease and a
 *        membership cap (single-writer approximation, documented in class).
 *
 * Everything is deterministic: the APCu cases guard extension availability
 * like the sibling suites, the transport cases run on the shadow cURL
 * harness (tests/Unit/ShadowCurl.php), and the S3 storage cases run on the
 * recording fake transport. No sleeps, no wall-clock races.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Storage\CurlS3HttpTransport;
use Zef\Framework\Storage\S3CompatibleStorage;
use Zef\Framework\Storage\S3HttpResponse;
use Zef\Framework\Storage\ShadowCurlState;
use Zef\Framework\Storage\StorageException;
use Zef\Test\Unit\FakeS3Transport;

/**
 * @internal
 */
final class ZefDeep15StorageTest extends TestCase
{
    private const int SEC = 1_000_000_000;

    private static bool $shadowLoaded = false;

    // ------------------------------------------------------------------
    // P-1 — ApcuRateLimiter: fixed-window bucketing
    // ------------------------------------------------------------------

    /**
     * Regresi P-1 (issue #169): bucket id murni fungsi wall clock
     * (intdiv(now, windowSeconds)) — counter bucket LAMA tidak pernah
     * terbaca lagi, sehingga hit pada detik T dan T+window jatuh ke dua
     * bucket berbeda dan hitungan reset tanpa entri window (skema lama
     * window+counter divalidasi dengan fetch-then-store yang bisa saling
     * menginjak antar-proses). Emulasi deterministik: pre-seed bucket
     * sebelumnya dengan counter penuh.
     */
    public function testApcuFixedWindowBucketingResetsAtBucketBoundary(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $window = 30;
        $key = 'zef-deep-15-p1-stale-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        $previousBucket = \intdiv(\time(), $window) - 1;
        \apcu_store('zef:ratelimit:c:' . $hash . ':' . $previousBucket, 99, 600);

        $first = $limiter->check($key, 3, $window);
        self::assertTrue($first->allowed, 'counter bucket lama tidak boleh bocor ke bucket sekarang');
        self::assertSame(2, $first->remaining);

        $second = $limiter->check($key, 3, $window);
        self::assertSame(1, $second->remaining, 'counter bucket sama berlanjut (satu apcu_inc atomik)');
    }

    /**
     * Regresi P-1 (issue #169): counter bucket SEKARANG dipakai apa adanya —
     * pre-seed 2 meniru dua hit yang sudah terjadi; keputusan berikutnya
     * meng-increment DI ATASNYA (bukan menimpa), membuktikan kunci per-bucket
     * diteruskan ke apcu_inc tanpa dibaca-tulis ulang. Bucket saat ini DAN
     * penerusnya di-seed sama agar hasilnya bebas dari detik yang beranjak
     * di antara seeding dan check (bucket mana pun yang dilangkahi, count 3).
     */
    public function testApcuCountsOnTopOfTheCurrentBucketCounter(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $window = 30;
        $key = 'zef-deep-15-p1-live-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        $bucket = \intdiv(\time(), $window);
        \apcu_store('zef:ratelimit:c:' . $hash . ':' . $bucket, 2, 600);
        \apcu_store('zef:ratelimit:c:' . $hash . ':' . ($bucket + 1), 2, 600);

        $decision = $limiter->check($key, 2, $window);
        self::assertFalse($decision->allowed, 'pre-seed 2 + inc 1 → count 3 > limit 2');
        self::assertSame(0, $decision->remaining);
        self::assertGreaterThanOrEqual(1, $decision->retryAfter);
        self::assertLessThanOrEqual($window, $decision->retryAfter);
    }

    /**
     * Regresi P-1 (issue #169): jalur pemulihan — counter yang ter-poison
     * (entri non-int pada kunci bucket) membuat apcu_inc gagal; seeding
     * apcu_add kalah (kunci sudah ada) dan re-inc pun gagal, jadi apcu_store
     * memulihkan hitungan dari 1 dan keputusan tetap sah. Bucket saat ini
     * DAN penerusnya di-poison agar hasilnya bebas dari detik yang beranjak
     * di antara poisoning dan check.
     */
    public function testApcuRecoversFromAPoisonedBucketCounter(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $window = 30;
        $key = 'zef-deep-15-p1-poison-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        $bucket = \intdiv(\time(), $window);
        \apcu_store('zef:ratelimit:c:' . $hash . ':' . $bucket, 'poisoned', 600);
        \apcu_store('zef:ratelimit:c:' . $hash . ':' . ($bucket + 1), 'poisoned', 600);

        $decision = $limiter->check($key, 3, $window);
        self::assertTrue($decision->allowed);
        self::assertSame(2, $decision->remaining);
    }

    // ------------------------------------------------------------------
    // P-8 — CurlS3HttpTransport: explicit Content-Length: 0
    // ------------------------------------------------------------------

    /**
     * Regresi P-8 (issue #169): PUT/DELETE tanpa body wajib membawa
     * Content-Length: 0 eksplisit — S3 asli menjawab 411 Length Required
     * tanpanya (MinIO toleran sehingga cacatnya tersembunyi di dev).
     */
    public function testCurlTransportSendsZeroContentLengthForBodylessPutAndDelete(): void
    {
        $this->loadShadow();
        ShadowCurlState::$execResult = '';
        $transport = new CurlS3HttpTransport();

        $transport->request('PUT', 'https://example.com/bucket/k', ['X-Test' => '1'], '');
        self::assertSame(['X-Test: 1', 'Content-Length: 0'], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);

        $transport->request('DELETE', 'https://example.com/bucket/k', [], '');
        self::assertSame(['Content-Length: 0'], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, ShadowCurlState::$opts);
    }

    /**
     * Regresi P-8 (issue #169): request ber-body TIDAK boleh menambah
     * Content-Length eksplisit (curl menghitungnya dari POSTFIELDS — header
     * ganda akan konflik), GET/HEAD kosong juga tidak membawanya, dan header
     * Content-Length buatan pemanggil tidak digandakan (case-insensitive).
     */
    public function testCurlTransportNeverDuplicatesContentLength(): void
    {
        $this->loadShadow();
        $transport = new CurlS3HttpTransport();

        ShadowCurlState::$execResult = 'x';
        $transport->request('PUT', 'https://example.com/bucket/k', ['X-Test' => '1'], 'payload');
        self::assertSame(['X-Test: 1'], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);
        self::assertSame('payload', ShadowCurlState::$opts[CURLOPT_POSTFIELDS]);

        ShadowCurlState::$execResult = '';
        $transport->request('GET', 'https://example.com/bucket/k', [], '');
        self::assertSame([], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);

        $transport->request('HEAD', 'https://example.com/bucket/k', [], '');
        self::assertSame([], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);

        $transport->request('DELETE', 'https://example.com/bucket/k', ['content-length' => '0'], '');
        self::assertSame(['content-length: 0'], ShadowCurlState::$opts[CURLOPT_HTTPHEADER]);
    }

    // ------------------------------------------------------------------
    // P-9 — S3CompatibleStorage: pagination + server order
    // ------------------------------------------------------------------

    /**
     * Regresi P-9 (issue #169): listing wajib mengikuti pagination
     * ListObjectsV2 — setiap halaman ter-truncate digenapi lewat query
     * continuation-token sampai IsTruncated=false; dulu hanya satu halaman
     * yang diambil sehingga hasilnya terpotong diam-diam saat jumlah objek
     * melewati satu halaman.
     */
    public function testS3ListFollowsContinuationTokensUntilComplete(): void
    {
        $pageOne = new S3HttpResponse(200, [], '<?xml version="1.0"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>true</IsTruncated>'
            . '<NextContinuationToken>abc/def+ghi=</NextContinuationToken>'
            . '<Contents><Key>logs/a.txt</Key></Contents>'
            . '<Contents><Key>logs/b.txt</Key></Contents>'
            . '</ListBucketResult>');
        $pageTwo = new S3HttpResponse(200, [], '<?xml version="1.0"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<IsTruncated>false</IsTruncated>'
            . '<Contents><Key>logs/c.txt</Key></Contents>'
            . '</ListBucketResult>');
        $transport = new FakeS3Transport([$pageOne, $pageTwo]);

        self::assertSame(
            ['logs/a.txt', 'logs/b.txt', 'logs/c.txt'],
            $this->s3($transport)->list('logs/', 10),
        );
        self::assertCount(2, $transport->requests, 'pagination must issue exactly one request per page');
        self::assertSame(
            'https://s3.us-east-1.amazonaws.com/zef-bucket?continuation-token=abc%2Fdef%2Bghi%3D&list-type=2&max-keys=10&prefix=logs%2F',
            $transport->requests[1]['url'],
            'halaman kedua wajib membawa continuation-token (ter-enkode sekali, urutan kanonik)',
        );
    }

    /**
     * Regresi P-9 (issue #169): urutan kunci S3 (leksikografik) dipertahankan
     * apa adanya — sort() lokal sudah dihapus karena dengan pagination ia
     * justru merusak urutan halaman yang digabung.
     */
    public function testS3ListPreservesServerOrderWithoutLocalSort(): void
    {
        $page = new S3HttpResponse(200, [], '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>false</IsTruncated>'
            . '<Contents><Key>logs/2026/b.txt</Key></Contents>'
            . '<Contents><Key>logs/2026/a.txt</Key></Contents>'
            . '</ListBucketResult>');

        self::assertSame(
            ['logs/2026/b.txt', 'logs/2026/a.txt'],
            $this->s3(new FakeS3Transport([$page]))->list('logs/', 100),
        );
    }

    /**
     * Regresi P-9 (issue #169): ketika satu halaman sudah memuat SEJUMLAH
     * $limit kunci (max-keys dipass = limit), listing berhenti di situ —
     * kontrak list() hanya menjanjikan maksimum $limit kunci, jadi tidak ada
     * permintaan halaman kedua yang diboroskan meski IsTruncated=true.
     */
    public function testS3ListStopsOnceTheLimitIsSatisfied(): void
    {
        $page = new S3HttpResponse(200, [], '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>true</IsTruncated>'
            . '<NextContinuationToken>next</NextContinuationToken>'
            . '<Contents><Key>logs/a.txt</Key></Contents>'
            . '<Contents><Key>logs/b.txt</Key></Contents>'
            . '<Contents><Key>logs/c.txt</Key></Contents>'
            . '</ListBucketResult>');
        $transport = new FakeS3Transport([$page]);

        self::assertSame(
            ['logs/a.txt', 'logs/b.txt', 'logs/c.txt'],
            $this->s3($transport)->list('logs/', 3),
        );
        self::assertCount(1, $transport->requests, 'limit tercapai di halaman pertama → tidak ada permintaan kedua');
    }

    /**
     * Regresi P-9 (issue #169): halaman ter-truncate tanpa continuation token
     * adalah respons ListObjectsV2 cacat — wajib gagal nyaring
     * (StorageException), bukan berputar tanpa batas dan bukan memotong
     * hasil diam-diam.
     */
    public function testS3ListRejectsTruncatedPageWithoutContinuationToken(): void
    {
        $page = new S3HttpResponse(200, [], '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>true</IsTruncated>'
            . '<Contents><Key>logs/a.txt</Key></Contents>'
            . '</ListBucketResult>');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('S3 listing response is truncated without a continuation token.');
        $this->s3(new FakeS3Transport([$page]))->list('logs/');
    }

    /**
     * Regresi P-9 (issue #169): safety cap pagination — stream yang tidak
     * pernah selesai (halaman kosong + token terus-menerus, mis. server
     * rusak/hostil) harus berhenti nyaring setelah batas halaman, bukan
     * loop tanpa akhir.
     */
    public function testS3ListPaginationSafetyCapFailsLoudly(): void
    {
        $endless = static fn (): S3HttpResponse => new S3HttpResponse(200, [], '<?xml version="1.0"?><ListBucketResult>'
            . '<IsTruncated>true</IsTruncated><NextContinuationToken>loop-forever</NextContinuationToken>'
            . '</ListBucketResult>');
        $transport = new FakeS3Transport(null, $endless);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('S3 listing exceeded the pagination safety cap of 1000 pages.');
        $this->s3($transport)->list('logs/');
    }

    // ------------------------------------------------------------------
    // P-10 — TaggableCache: bounded per-tag index
    // ------------------------------------------------------------------

    /**
     * Regresi P-10 (issue #169): index per-tag kini ber-lease TTL yang
     * digeser maju pada SETIAP setWithTags — tag yang berhenti ditulis menua
     * dan ter-evaporasi sendiri, bukan hidup selamanya di store.
     */
    public function testTagIndexTtlIsRefreshedOnEveryWrite(): void
    {
        $clock = new ZefDeep15Clock(1_700_000_000 * self::SEC);
        $inner = new InMemoryCache(new InMemoryCacheStore(50, $clock), clock: $clock);
        $cache = new TaggableCache($inner);

        $cache->setWithTags('k1', 'v1', 60, ['t']);
        self::assertSame(86400, $inner->getRemainingTtlSeconds("\0zef-tag:t"));

        $clock->now += 7200 * self::SEC; // 2 jam tanpa tulis → sisa lease mengecil
        self::assertSame(86400 - 7200, $inner->getRemainingTtlSeconds("\0zef-tag:t"));

        $cache->setWithTags('k2', 'v2', 60, ['t']); // anggota baru → lease digeser penuh
        self::assertSame(86400, $inner->getRemainingTtlSeconds("\0zef-tag:t"));

        $clock->now += 3600 * self::SEC;
        $cache->setWithTags('k1', 'v1', 60, ['t']); // anggota LAMA ditulis ulang → tetap digeser
        self::assertSame(86400, $inner->getRemainingTtlSeconds("\0zef-tag:t"));
    }

    /**
     * Regresi P-10 (issue #169): keanggotaan per-tag di-cap 1000 anggota —
     * anggota tertua ter-evict ketika batas terlampaui sehingga invalidasi
     * menjadi approximatif bagi mereka (nilai tetap menua via TTL-nya
     * sendiri), dan index tidak tumbuh tanpa batas.
     */
    public function testTagMembershipIsCappedAndEvictsOldestMembers(): void
    {
        $inner = new InMemoryCache(new InMemoryCacheStore(5000));
        $cache = new TaggableCache($inner);

        for ($i = 0; $i <= 1000; ++$i) {
            $cache->setWithTags('member:' . $i, $i, null, ['big']);
        }

        $raw = $inner->get("\0zef-tag:big");
        if (!is_string($raw)) {
            self::fail('The tag index must be stored as a JSON string.');
        }
        $members = json_decode($raw, true);
        if (!is_array($members)) {
            self::fail('The tag index must decode into a member list.');
        }
        self::assertCount(1000, $members);
        self::assertSame('member:1', $members[0], 'anggota tertua (member:0) ter-evict');
        self::assertSame('member:1000', $members[999]);

        // Anggota ter-evict tidak ter-invalidate lagi (approximate) — nilai
        // k0 bertahan sampai TTL-nya sendiri atau delete() eksplisit.
        self::assertSame(1000, $cache->invalidateTag('big'));
        self::assertFalse($cache->has('member:1'));
        self::assertTrue($cache->has('member:0'), 'anggota ter-evict luput dari invalidasi approximatif (P-10)');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function loadShadow(): void
    {
        if (!self::$shadowLoaded) {
            require_once __DIR__ . '/ShadowCurl.php';
            self::$shadowLoaded = true;
        }
        ShadowCurlState::$enabled = true;
        ShadowCurlState::$initReturnsFalse = false;
        ShadowCurlState::$execResult = 'shadow-body';
        ShadowCurlState::$responseCode = 200;
        ShadowCurlState::$curlError = 'boom-shadow';
    }

    private function s3(FakeS3Transport $transport): S3CompatibleStorage
    {
        return new S3CompatibleStorage(
            'zef-bucket',
            'us-east-1',
            'zef-deep-15-key-id',
            'zef-deep-15-secret-key',
            $transport,
        );
    }
}

/**
 * @internal
 */
final class ZefDeep15Clock implements CacheClockInterface
{
    public function __construct(public int $now) {}

    #[\Override]
    public function nowUnixNano(): int
    {
        return $this->now;
    }
}
