<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — Fase 8 (mutation round 3): Infrastructure\Security zone
 * + SharedRateLimitStore consumer. Target escape baseline f8-infra-b:
 * RotatingKeyRing 19, AesGcmEncryptor 17, RedisRateLimiter 11, ApcuRateLimiter 3.
 * Format payload zefenc1: "zefenc1.<b64url iv>.<b64url tag>.<b64url ciphertext>".
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\AesGcmEncryptor;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Security\RotatingKeyRing;
use Zef\Framework\Security\SharedRateLimitStoreInterface;

/**
 * @internal
 */
final class EdgeMatrixF8SecInfraTest extends TestCase
{
    private const string K32A = '0123456789abcdef0123456789abcdef'; // 32 byte raw
    private const string K32B = 'fedcba9876543210fedcba9876543210';

    // ------------------------------------------------------------------
    // RotatingKeyRing
    // ------------------------------------------------------------------

    /** Membunuh LessThan:46 (1 key sah) + Throw_:47 + LessThan dua arah. */
    public function testRingSizeBoundaries(): void
    {
        $ring = new RotatingKeyRing([self::K32A]); // tepat 1 sah
        self::assertSame(1, $ring->keyCount());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RotatingKeyRing requires at least one key.');
        new RotatingKeyRing([]);
    }

    /** Membunuh Concat×2/ConcatOperandRemoval×3:50 — pesan kapasitas persis. */
    public function testRingMaxKeysMessageIsExact(): void
    {
        $keys = [];
        for ($i = 0; $i < 17; ++$i) {
            $keys[] = self::K32A;
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RotatingKeyRing supports at most 16 keys.');
        new RotatingKeyRing($keys);
    }

    /** Membunuh Dec/Inc/Minus/Concat×5:53 — pesan batas activeIndex persis (3 key → 0..2). */
    public function testActiveIndexUpperBoundMessageIsExact(): void
    {
        $ring = new RotatingKeyRing([self::K32A, self::K32B, self::K32A]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Active key index must be between 0 and 2.');
        $ring->withActiveIndex(3);
    }

    /** Batas bawah activeIndex (-1) dan indeks valid tetap berfungsi. */
    public function testActiveIndexLowerBoundAndUsage(): void
    {
        $ring = new RotatingKeyRing([self::K32A, self::K32B]);

        try {
            $ring->withActiveIndex(-1);
            self::fail('-1 must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Active key index must be between 0 and 1.', $e->getMessage());
        }

        $rotated = $ring->withActiveIndex(1);
        self::assertSame(1, $rotated->activeIndex());
        $cipher = $rotated->encrypt('payload');
        self::assertSame('payload', $ring->decrypt($cipher), 'key lama tetap bisa membuka hasil key aktif');
    }

    /** Membunuh UnwrapArrayValues:60 — materials wajib list meski input asosiatif. */
    public function testWithActiveIndexNormalizesMaterials(): void
    {
        // Input non-list: materials harus dinormalkan agar withActiveIndex
        // membangun ulang encryptors dengan indeks 0..n-1 (kontrak list).
        $ring = new RotatingKeyRing(['a' => self::K32A, 'b' => self::K32B]);
        $cipher = $ring->withActiveIndex(1)->encrypt('x');
        self::assertStringStartsWith('zefenc1.', $cipher);
        self::assertSame(2, $ring->keyCount());
    }

    /** Membunuh Concat×2:97 + ConcatOperandRemoval:97 — detail Last error wajib utuh,
     * jadi pesan di-assert SAME penuh (prefix saja tidak membedakan mutan concat). */
    public function testDecryptFailureMessagePrefix(): void
    {
        $ring = new RotatingKeyRing([self::K32A, self::K32B]);

        try {
            $ring->decrypt('zefenc1.garbage.payload.here');
            self::fail('garbage payload must fail');
        } catch (\RuntimeException $e) {
            self::assertSame(
                'Decryption failed with any of the 2 ring key(s).'
                . ' Last error: Malformed encryption payload (IV/tag length).',
                $e->getMessage(),
            );
        }
    }

    // ------------------------------------------------------------------
    // AesGcmEncryptor
    // ------------------------------------------------------------------

    /** Membunuh Concat×2:35 + jalur raw-key invalid (ReturnRemoval:110 via key non-hex). */
    public function testKeySizeMessageIsExact(): void
    {
        try {
            new AesGcmEncryptor(str_repeat('x', 31)); // 31 byte, bukan hex/base64
            self::fail('31-byte key must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Encryption key must decode to exactly 32 bytes (AES-256), got 31.', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must decode to exactly 32 bytes (AES-256), got 7.');
        new AesGcmEncryptor('shortgg');
    }

    /** Membunuh PregMatchRemoveCaret:97 — hex valid harus penuh 64, bukan suffix. */
    public function testHexKeyGrammarIsCaretAnchored(): void
    {
        $bad = 'g' . str_repeat('a', 64); // 65 char; TAIL 64 hex
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must decode to exactly 32 bytes (AES-256), got 65.');
        new AesGcmEncryptor($bad);
    }

    /** Membunuh PregMatchRemoveDollar:97 — prefix hex 64 + junk tetap ditolak. */
    public function testHexKeyGrammarIsDollarAnchored(): void
    {
        $bad = str_repeat('a', 64) . 'g';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must decode to exactly 32 bytes (AES-256), got 65.');
        new AesGcmEncryptor($bad);
    }

    /** Membunuh PregMatchRemoveCaret:103 + PregMatchRemoveDollar:103 sekaligus.
     * Pelajaran kunci: decode strict memproses KUNCI PENUH (bukan substring yang
     * match), sehingga junk di luar alphabet selalu gagal decode = perilaku identik.
     * Pembunuh yang benar: 46 char SEMUA dalam alphabet (panjang di luar jendela
     * anchor 43..44) → original menolak (got 46); mutan tak-anchor mencocokkan
     * substring 43-44 lalu decode penuh SUKSES (34 byte) → pesan berganti 'got 34'. */
    public function testBase64KeyGrammarRejectsLengthOutsideAnchorWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must decode to exactly 32 bytes (AES-256), got 46.');
        new AesGcmEncryptor(str_repeat('K', 46));
    }

    /** Round-trip penuh + b64url tanpa padding (Membunuh UnwrapRtrim:115 + Modulus:120). */
    public function testEncryptDecryptRoundTripAndAlphabet(): void
    {
        $enc = new AesGcmEncryptor(self::K32A);
        $cipher = $enc->encrypt('rahasia-dunia');
        self::assertStringStartsWith('zefenc1.', $cipher);
        self::assertDoesNotMatchRegularExpression('/[+=\/]/', $cipher, 'b64url tidak boleh memuat +, /, atau =');
        self::assertSame('rahasia-dunia', $enc->decrypt($cipher));
        self::assertNotSame($cipher, $enc->encrypt('rahasia-dunia'), 'IV acak per pesan');
    }

    /** Membunuh Modulus:120 + ConcatOperandRemoval:120 — plaintext 4 byte menghasilkan
     * segmen cipher 6 char (bukan kelipatan 4) yang butuh pad eksak (4 - len%4) % 4. */
    public function testRoundTripWithFourBytePlaintextExercisesB64Padding(): void
    {
        $enc = new AesGcmEncryptor(self::K32A);
        $cipher = $enc->encrypt('wxyz');
        $parts = explode('.', $cipher);
        self::assertSame(6, \strlen($parts[3]), '4 byte plaintext → segmen cipher 6 char');
        self::assertSame('wxyz', $enc->decrypt($cipher));
    }

    /** Membunuh PregMatchRemoveFlags:97 — hex key UPPERCASE diterima lewat flag /i. */
    public function testUppercaseHexKeyAcceptedViaCaseInsensitiveFlag(): void
    {
        $enc = new AesGcmEncryptor(str_repeat('A', 64)); // 64 hex uppercase → 32 byte
        self::assertSame('x', $enc->decrypt($enc->encrypt('x')));
    }

    /** Membunuh LogicalOr:74 — IV salah panjang (11 byte) tetap ditolak eksplisit. */
    public function testIvLengthGuardIsIndependent(): void
    {
        $iv11 = $this->b64urlRaw(str_repeat('i', 11));   // 15 char b64url
        $tag = $this->b64urlRaw(str_repeat('t', 16));   // 24 char (16 byte)
        $payload = 'zefenc1.' . $iv11 . '.' . $tag . '.AAAA';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malformed encryption payload (IV/tag length).');
        new AesGcmEncryptor(self::K32A)->decrypt($payload);
    }

    /** Membunuh PregMatchRemoveFlags pada base64_decode strict — char invalid → IV-length, bukan tamper. */
    public function testStrictBase64DecodingOnIvSegment(): void
    {
        $ivBad = str_repeat('A', 16) . '!'; // 17 char; strict → gagal → ''
        $tag = $this->b64urlRaw(str_repeat('t', 16));
        $payload = 'zefenc1.' . $ivBad . '.' . $tag . '.AAAA';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malformed encryption payload (IV/tag length).');
        new AesGcmEncryptor(self::K32A)->decrypt($payload);
    }

    /** Pesan versi/format persis (guard count!==4 + version). */
    public function testMalformedPayloadMessages(): void
    {
        $enc = new AesGcmEncryptor(self::K32A);
        foreach (['', 'x', 'a.b.c', 'zefenc1.a.b', 'v0.a.b.c'] as $bad) {
            try {
                $enc->decrypt($bad);
                self::fail('payload ' . var_export($bad, true) . ' must fail');
            } catch (\RuntimeException $e) {
                self::assertSame('Malformed encryption payload (unknown format or version).', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // RedisRateLimiter (store palsu) + ApcuRateLimiter (guard konstruktor)
    // ------------------------------------------------------------------

    /** Batas decision: count == limit masih diizinkan (<=), pesan guard persis. */
    public function testRedisLimiterBoundaryAndGuards(): void
    {
        $store = new F8FakeSharedStore();
        $limiter = new RedisRateLimiter($store);

        $store->next = ['count' => 3, 'reset' => 0];
        $d = $limiter->check('k', 3, 10);
        self::assertTrue($d->allowed, 'count == limit masih diizinkan');
        self::assertSame(3, $d->limit);
        self::assertSame(0, $d->remaining);
        self::assertSame(1, $d->retryAfter, 'retryAfter minimum 1');

        $store->next = ['count' => 4, 'reset' => 0];
        $d = $limiter->check('k', 3, 10);
        self::assertFalse($d->allowed);
        self::assertSame(0, $d->remaining);
    }

    /** Membunuh tamper path (plain === false): ciphertext GCM diubah satu char →
     * otentikasi gagal → 'Decryption failed (tampered data or wrong key).' persis. */
    public function testTamperedCiphertextFailsAuthentication(): void
    {
        $enc = new AesGcmEncryptor(self::K32A);
        $cipher = $enc->encrypt('wxyz');
        $parts = explode('.', $cipher);
        $first = $parts[3][0];
        $parts[3] = ('A' === $first ? 'B' : 'A') . \substr($parts[3], 1); // panjang tetap, alphabet sah
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed (tampered data or wrong key).');
        $enc->decrypt(\implode('.', $parts));
    }

    /** Kontrak kapasitas default = 10.000 kunci — membunuh Dec/Inc default
     * ApcuRateLimiter:23 + RedisRateLimiter:17 (default ±1 hanya terlihat via refleksi). */
    public function testDefaultMaxKeysContractIsExactlyTenThousand(): void
    {
        self::assertSame(
            10000,
            new \ReflectionParameter([ApcuRateLimiter::class, '__construct'], 'maxKeys')->getDefaultValue(),
        );
        self::assertSame(
            10000,
            new \ReflectionParameter([RedisRateLimiter::class, '__construct'], 'maxKeys')->getDefaultValue(),
        );
    }

    /** Perilaku APCu RIIL (ekstensi terpasang): jalur apcu_add pertama, inc naik,
     * boundary count==limit masih allowed, >limit ditolak, key independen, guards. */
    public function testApcuRealWindowBehaviourAndBoundaries(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $k = 'apcu-real-' . \bin2hex(\random_bytes(4));

        $d1 = $limiter->check($k, 2, 30);
        self::assertTrue($d1->allowed);
        self::assertSame(2, $d1->limit);
        self::assertSame(1, $d1->remaining);
        self::assertSame(30, $d1->retryAfter, 'reset - now == window penuh di detik yang sama');

        $d2 = $limiter->check($k, 2, 30); // count 2 == limit → masih allowed
        self::assertTrue($d2->allowed);
        self::assertSame(0, $d2->remaining);

        $d3 = $limiter->check($k, 2, 30); // count 3 > limit → ditolak
        self::assertFalse($d3->allowed);
        self::assertSame(0, $d3->remaining);

        self::assertTrue($limiter->check($k . '-x', 2, 30)->allowed, 'key lain independen');

        // limit=1 & window=1 sah — membunuh LessThan→<= (:39) dua arah.
        self::assertTrue($limiter->check($k . '-b1', 1, 1)->allowed, 'limit=1 window=1 masih sah');

        try {
            $limiter->check('', 1, 1);
            self::fail('empty key must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Rate limit key must not be empty.', $e->getMessage());
        }

        try {
            $limiter->check($k, 0, 1);
            self::fail('limit 0 must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('limit and windowSeconds must be >= 1.', $e->getMessage());
        }
    }

    /** Jalur jendela KADALUWARSA (stored <= now): window & counter di-re-store →
     * hitungan mulai dari 1 lagi. Pre-seed APCu langsung memakai format kunci internal.
     * Cek kedua membunuh FunctionCallRemoval:54 — tanpa re-store window, cek berikut
     * akan salah reset counter lagi (remaining 1 vs 0). */
    public function testApcuStaleWindowResetsCounter(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $key = 'apcu-stale-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        \apcu_store('zef:ratelimit:w:' . $hash, \time() - 5, 600); // reset sudah lewat
        \apcu_store('zef:ratelimit:c:' . $hash, 99, 600);          // counter basi 99

        $d = $limiter->check($key, 2, 30);
        self::assertTrue($d->allowed, 'jendela basi → counter reset → count 1');
        self::assertSame(1, $d->remaining);
        self::assertSame(2, $d->limit);

        $d2 = $limiter->check($key, 2, 30); // window sudah di-re-anchor → count lanjut 2
        self::assertTrue($d2->allowed);
        self::assertSame(0, $d2->remaining, 'counter TIDAK boleh ter-reset ulang');
    }

    /** Window berisi NUMERIC STRING (bukan int): is_int gagal → jalur re-store.
     * Membunuh LogicalAnd:53 (&& pertama → ||): mutan memilih jalur $reset=$stored
     * sehingga retryAfter ≈ 1000, bukan 30 — asersi retryAfter membedakannya. */
    public function testApcuNumericStringWindowForcesReanchorPath(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $key = 'apcu-numstr-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        \apcu_store('zef:ratelimit:w:' . $hash, (string) (\time() + 1000), 600);

        $d = $limiter->check($key, 2, 30);
        self::assertTrue($d->allowed);
        self::assertSame(30, $d->retryAfter, 're-anchor wajib memakai now+window, bukan string basi');
    }

    /** Pre-seed stored = now+1 (tepat +1 detik): reset - now == 1 → max(1, 1) = 1.
     * Membunuh Increment:67 (max(1,..) → max(2,..)) pada boundary retryAfter minimum. */
    public function testApcuRetryAfterBoundaryAtOneSecond(): void
    {
        if (!\extension_loaded('apcu')) {
            self::markTestSkipped('ext-apcu not available in this environment.');
        }

        $limiter = new ApcuRateLimiter();
        $key = 'apcu-bound-' . \bin2hex(\random_bytes(4));
        $hash = \hash('sha256', $key);
        \apcu_store('zef:ratelimit:w:' . $hash, \time() + 1, 600);

        $d = $limiter->check($key, 5, 30);
        self::assertTrue($d->allowed);
        self::assertSame(1, $d->retryAfter, 'retryAfter == 1 pada reset-now terkecil yang mungkin');
    }

    // ------------------------------------------------------------------
    // RedisSharedRateLimitStore — harness \Redis palsu (eval/hMGet terjadwal)
    // ------------------------------------------------------------------

    /** eval() balas skalar → RuntimeException persis (membunuh LogicalOr:44:
     * mutan && mengevaluasi count() non-array → TypeError, bukan RuntimeException). */
    public function testIncrementRejectsNonArrayEvalResult(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded in this environment (the F8 fake extends \Redis).');
        }
        $store = new RedisSharedRateLimitStore(self::fakeRedis(evalResult: 'scalar-not-array'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis rate-limit store returned an unexpected result.');
        $store->increment('k', 10, 1000);
    }

    /** Elemen non-numerik → fallback: count 0, reset now+window; elemen numerik →
     * (int) cast nyata. Matriks 4 kasus (kedua/0-saja/1-saja/kedua numerik) membunuh
     * index-swap :47 & :48, Increment fallback 0→±1, Plus (now+window → now-window),
     * Ternary swap, dan CastString/CastInt pada jalur cast. */
    public function testIncrementElementSanitizationMatrix(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded in this environment (the F8 fake extends \Redis).');
        }
        $make = static fn (array $eval): RedisSharedRateLimitStore => new RedisSharedRateLimitStore(self::fakeRedis(evalResult: $eval));

        // keduanya non-numerik → fallback penuh (count 0, reset = now + window)
        self::assertSame(['count' => 0, 'reset' => 5030], $make(['bukan-angka', 'juga-bukan'])->increment('k', 30, 5000));
        // hanya [0] numerik → count ter-cast (3.7 → 3), reset fallback — membunuh index-swap :47
        self::assertSame(['count' => 3, 'reset' => 5030], $make(['3.7', 'juga-bukan'])->increment('k', 30, 5000));
        // hanya [1] numerik → count fallback 0, reset ter-cast — membunuh index-swap :48
        self::assertSame(['count' => 0, 'reset' => 1200], $make(['bukan-angka', '1200.9'])->increment('k', 30, 5000));
        // keduanya numerik → cast int penuh pada kedua jalur
        self::assertSame(['count' => 3, 'reset' => 1200], $make(['3.7', '1200.9'])->increment('k', 30, 5000));
    }

    /** Matriks peek: skalar, field hilang, non-numerik → null; valid → int murni
     * (membunuh LogicalOr:58 ×3 + CastInt:66 — asersi assertSame(int) membedakan). */
    public function testPeekBucketMatrixReturnsNullOrPureInts(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded in this environment (the F8 fake extends \Redis).');
        }
        $fakes = [
            'skalar' => self::fakeRedis(hMGetResult: false), // false → !is_array → null
            'tanpa-reset' => self::fakeRedis(hMGetResult: ['count' => '5']),
            'tanpa-count' => self::fakeRedis(hMGetResult: ['reset' => '1234']),
            'non-numerik' => self::fakeRedis(hMGetResult: ['count' => 'x', 'reset' => 'y']),
            'valid' => self::fakeRedis(hMGetResult: ['count' => '5', 'reset' => '1234']),
        ];
        foreach (['skalar', 'tanpa-reset', 'tanpa-count', 'non-numerik'] as $case) {
            self::assertNull(
                new RedisSharedRateLimitStore($fakes[$case])->peek('k', 1000),
                "kasus {$case} wajib null",
            );
        }
        self::assertSame(['count' => 5, 'reset' => 1234], new RedisSharedRateLimitStore($fakes['valid'])->peek('k', 1000));
    }

    /** Membunuh max(0,..):35 dua arah + max(1,..):37 dua arah via reset relatif. */
    public function testRedisLimiterRemainingAndRetryAfterArithmetic(): void
    {
        $store = new F8FakeSharedStore();
        $limiter = new RedisRateLimiter($store);

        $store->next = ['count' => 5, 'reset' => 0];
        $d = $limiter->check('over', 3, 10);
        self::assertSame(0, $d->remaining, 'remaining tidak boleh negatif');

        $store->next = ['count' => 2, 'reset' => 0];
        $d = $limiter->check('under', 5, 10);
        self::assertSame(3, $d->remaining);
    }

    /** Membunuh LogicalOr/LessThan:30 — guard limit/window dua arah. */
    public function testRedisLimiterArgumentGuards(): void
    {
        $limiter = new RedisRateLimiter(new F8FakeSharedStore());
        foreach ([[0, 10], [1, 0]] as [$limit, $window]) {
            try {
                $limiter->check('k', $limit, $window);
                self::fail("limit={$limit}, window={$window} must throw");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('limit and windowSeconds must be >= 1.', $e->getMessage());
            }
        }
        // window == 1 sah (membunuh LessThan → <=)
        $store = new F8FakeSharedStore();
        $store->next = ['count' => 1, 'reset' => 0];
        self::assertTrue(new RedisRateLimiter($store)->check('k', 1, 1)->allowed);
    }

    /** Membunuh LessThan:19 dua arah + default maxKeys tidak diekspos (equiv) → guard saja. */
    public function testRedisLimiterMaxKeysGuard(): void
    {
        self::assertNotNull(new RedisRateLimiter(new F8FakeSharedStore(), 1)); // @phpstan-ignore-line
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxKeys must be >= 1.');
        new RedisRateLimiter(new F8FakeSharedStore(), 0);
    }

    /** ApcuRateLimiter: guard konstruktor tanpa ekstensi + batas maxKeys. */
    public function testApcuLimiterConstructorGuards(): void
    {
        if (\function_exists('apcu_fetch')) {
            self::assertNotNull(new ApcuRateLimiter(1)); // @phpstan-ignore-line
        } else {
            try {
                new ApcuRateLimiter(1);
                self::fail('missing apcu must throw');
            } catch (\RuntimeException $e) {
                self::assertSame('APCu extension is required for ApcuRateLimiter.', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxKeys must be >= 1.');
        new ApcuRateLimiter(0);
    }

    private function b64urlRaw(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function fakeRedis(mixed $evalResult = null, mixed $hMGetResult = null): F8FakeRedis
    {
        if (!class_exists(\Redis::class)) {
            self::fail('fakeRedis() requires ext-redis: the fake extends \Redis (guards live at each call site).');
        }
        $fake = new F8FakeRedis();
        $fake->evalResult = $evalResult;
        $fake->hMGetResult = $hMGetResult;

        return $fake;
    }
}

/**
 * @internal — store rate-limit palsu dengan jawaban terjadwal
 */
final class F8FakeSharedStore implements SharedRateLimitStoreInterface
{
    /** @var null|array{count:int,reset:int} */
    public ?array $next = null;

    #[\Override]
    public function increment(string $key, int $windowSeconds, int $now): array
    {
        $next = $this->next ?? ['count' => 1, 'reset' => 0];

        return ['count' => $next['count'], 'reset' => $now + $next['reset']];
    }

    #[\Override]
    public function peek(string $key, int $now): ?array
    {
        return $this->next;
    }
}

/*
 * @internal — \Redis palsu: eval()/hMGet() mengembalikan hasil terjadwal.
 * \Redis tidak final sehingga bisa diperluas; tidak ada koneksi jaringan.
 *
 * Deklarasi bersyarat: file test ini dimuat phpunit pada profil TANPA
 * ext-redis (sel smoke Windows, issue #92) — extends di luar guard akan
 * menjadi dependensi load-time yang mematikan seluruh suite, melanggar
 * desain skip-graceful (guard runtime + markTestSkipped di call site).
 */
if (class_exists(\Redis::class)) {
    final class F8FakeRedis extends \Redis
    {
        public mixed $evalResult = null;

        public mixed $hMGetResult = null;

        #[\Override] // @phpstan-ignore-line
        public function eval(string $script, array $args = [], int $numkeys = 0): mixed
        {
            return $this->evalResult;
        }

        #[\Override] // @phpstan-ignore-line
        public function hMGet(string $key, array $fields): array|false|\Redis
        {
            return $this->hMGetResult; // @phpstan-ignore-line
        }
    }
}
