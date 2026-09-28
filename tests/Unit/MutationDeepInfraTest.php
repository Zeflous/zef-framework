<?php

declare(strict_types=1);

/*
 * ZEF Framework — Mutation deep-dive #2 (v2.14.0): negative assertions and
 * boundary tests for the escaping clusters in Infrastructure/{Security,
 * Foundation,Observability}. Targets the measured escaping mutants: key
 * decoding boundaries, ring rotation probes, env parsing edges, Prometheus
 * exposition format invariants and APCu limiter decision math. APCu tests
 * skip gracefully when the extension is unavailable (CI without apcu).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Security\AesGcmEncryptor;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\RotatingKeyRing;

/**
 * @internal
 */
final class MutationDeepInfraTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    protected function tearDown(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        foreach (['ZEF_MUTTEST_INT', 'ZEF_MUTTEST_BOOL', 'ZEF_MUTTEST_STR', 'ZEF_MUTTEST_CSV'] as $name) {
            putenv($name);
        }
    }

    // -----------------------------------------------------------------
    // ApcuRateLimiter
    // -----------------------------------------------------------------

    public function testApcuLimiterValidatesInputsAndBoundaries(): void
    {
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu extension is not available.');
        }

        new ApcuRateLimiter(1);

        try {
            new ApcuRateLimiter(0);
            self::fail('maxKeys 0 must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $limiter = new ApcuRateLimiter();
        self::assertInstanceOf(ApcuRateLimiter::class, $limiter, 'boundary maxKeys=1 constructs');
        foreach (
            [
                ['', 1, 60],
                ['k', 0, 60],
                ['k', 1, 0],
            ] as [$key, $limit, $window]
        ) {
            try {
                $limiter->check($key, $limit, $window);
                self::fail("check('$key', $limit, $window) must be rejected.");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testApcuLimiterDecisionMathAndExhaustion(): void
    {
        if (!function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu extension is not available.');
        }

        $limiter = new ApcuRateLimiter();
        $first = $limiter->check('deep-apcu', 3, 60);
        self::assertTrue($first->allowed);
        self::assertSame(3, $first->limit);
        self::assertSame(2, $first->remaining);
        // Regresi P-1 (issue #169): fixed-window bucket — retryAfter adalah sisa
        // detik menuju batas bucket ((bucket+1)*window - now), selalu 1..windowSeconds,
        // bukan windowSeconds penuh dari anchor now+window.
        self::assertGreaterThanOrEqual(1, $first->retryAfter);
        self::assertLessThanOrEqual(60, $first->retryAfter);

        $second = $limiter->check('deep-apcu', 3, 60);
        self::assertTrue($second->allowed);
        self::assertSame(1, $second->remaining);

        $third = $limiter->check('deep-apcu', 3, 60);
        self::assertTrue($third->allowed);
        self::assertSame(0, $third->remaining);

        $fourth = $limiter->check('deep-apcu', 3, 60);
        self::assertFalse($fourth->allowed, 'fourth hit inside the window must be blocked');
        self::assertSame(0, $fourth->remaining);

        $other = $limiter->check('deep-apcu-other', 3, 60);
        self::assertTrue($other->allowed, 'independent keys have independent buckets');
    }

    // -----------------------------------------------------------------
    // AesGcmEncryptor
    // -----------------------------------------------------------------

    public function testEncryptorAcceptsKeyFormatsAndRejectsWrongSizes(): void
    {
        $raw = random_bytes(32);
        $hex = bin2hex($raw);
        $b64 = base64_encode($raw);

        foreach ([$raw, $hex, $b64] as $material) {
            $encryptor = new AesGcmEncryptor($material);
            $payload = $encryptor->encrypt('round-trip');
            self::assertStringStartsWith('zefenc1.', $payload);
            self::assertSame('round-trip', $encryptor->decrypt($payload));
        }

        foreach (['', random_bytes(31), random_bytes(33), 'abcd', 'zzzz'] as $badKey) {
            try {
                new AesGcmEncryptor($badKey);
                self::fail('Key of wrong size/format must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testEncryptorRejectsTamperedAndForeignPayloads(): void
    {
        $encryptor = new AesGcmEncryptor(random_bytes(32));
        $payload = $encryptor->encrypt('classified');
        $parts = explode('.', $payload);
        self::assertCount(4, $parts);

        foreach (['x.y', 'zefenc2.a.b.c', $parts[0] . '.' . $parts[1] . '.' . $parts[2]] as $malformed) {
            try {
                $encryptor->decrypt($malformed);
                self::fail("Payload '{$malformed}' must be rejected as malformed.");
            } catch (\RuntimeException) {
            }
        }

        $tampered = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.' . $parts[3];
        $tampered[strlen($tampered) - 2] = $tampered[strlen($tampered) - 2] === 'A' ? 'B' : 'A';

        try {
            $encryptor->decrypt($tampered);
            self::fail('Tampered ciphertext must fail authentication.');
        } catch (\RuntimeException) {
        }

        try {
            new AesGcmEncryptor(random_bytes(32))->decrypt($payload);
            self::fail('Wrong key must fail authentication.');
        } catch (\RuntimeException) {
        }
    }

    // -----------------------------------------------------------------
    // RotatingKeyRing
    // -----------------------------------------------------------------

    public function testKeyRingValidatesCountAndActiveIndexBoundaries(): void
    {
        $keys = [];
        for ($i = 0; $i < 16; ++$i) {
            $keys[] = bin2hex(random_bytes(32));
        }

        try {
            new RotatingKeyRing([]);
            self::fail('Empty ring must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $tooMany = $keys;
        $tooMany[] = bin2hex(random_bytes(32));

        try {
            new RotatingKeyRing($tooMany);
            self::fail('17 keys must exceed MAX_KEYS.');
        } catch (\InvalidArgumentException) {
        }

        $ring = new RotatingKeyRing($keys);
        self::assertSame(16, $ring->keyCount());
        self::assertSame(0, $ring->activeIndex());

        try {
            new RotatingKeyRing($keys, -1);
            self::fail('Negative active index must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            new RotatingKeyRing($keys, 16);
            self::fail('Active index 16 is out of range for 16 keys.');
        } catch (\InvalidArgumentException) {
        }

        $rotated = new RotatingKeyRing($keys, 15);
        self::assertSame(15, $rotated->activeIndex());

        try {
            $rotated->encrypt('probe');
            self::fail('Invalid key material must surface at construction, not encrypt.');
        } catch (\InvalidArgumentException|\RuntimeException) {
        }
    }

    public function testKeyRingEncryptsWithActiveKeyAndProbesAllKeysOnDecrypt(): void
    {
        $newest = bin2hex(random_bytes(32));
        $oldest = bin2hex(random_bytes(32));
        $ring = new RotatingKeyRing([$newest, $oldest]);

        $withNew = $ring->encrypt('fresh-data');
        self::assertSame('fresh-data', new AesGcmEncryptor($newest)->decrypt($withNew), 'active key 0 encrypts');

        $withOld = $ring->withActiveIndex(1)->encrypt('legacy-data');
        self::assertSame(0, $ring->activeIndex(), 'withActiveIndex is immutable');
        self::assertSame('legacy-data', new AesGcmEncryptor($oldest)->decrypt($withOld));

        self::assertSame('legacy-data', $ring->decrypt($withOld), 'ring probes older keys after the active one');

        try {
            $ring->decrypt('zefenc1.AAAA.AAAA.AAAA');
            self::fail('Alien payload must fail the whole ring.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('2 ring key(s)', $e->getMessage());
            self::assertStringContainsString('Last error:', $e->getMessage());
        }

        try {
            new RotatingKeyRing([$newest, 'too-short']);
            self::fail('Invalid key material must be rejected eagerly.');
        } catch (\InvalidArgumentException) {
        }
    }

    // -----------------------------------------------------------------
    // Env
    // -----------------------------------------------------------------

    public function testEnvIntParsingClampsAndStrictModes(): void
    {
        putenv('ZEF_MUTTEST_INT=');
        self::assertSame(5, Env::int('ZEF_MUTTEST_INT', 5, 1, 10));
        putenv('ZEF_MUTTEST_INT=   ');
        self::assertSame(5, Env::int('ZEF_MUTTEST_INT', 5, 1, 10));
        putenv('ZEF_MUTTEST_INT=abc');
        self::assertSame(5, Env::int('ZEF_MUTTEST_INT', 5, 1, 10));

        try {
            Env::int('ZEF_MUTTEST_INT', 5, 1, 10, true);
            self::fail('Strict mode must reject non-integer values.');
        } catch (\InvalidArgumentException) {
        }

        putenv('ZEF_MUTTEST_INT=7');
        self::assertSame(7, Env::int('ZEF_MUTTEST_INT', 5, 1, 10));
        putenv('ZEF_MUTTEST_INT=0');
        self::assertSame(1, Env::int('ZEF_MUTTEST_INT', 5, 1, 10), 'below min clamps up');
        putenv('ZEF_MUTTEST_INT=99');
        self::assertSame(10, Env::int('ZEF_MUTTEST_INT', 5, 1, 10), 'above max clamps down');
        putenv('ZEF_MUTTEST_INT=0');

        try {
            Env::int('ZEF_MUTTEST_INT', 5, 1, 10, true);
            self::fail('Strict mode must reject out-of-range values.');
        } catch (\InvalidArgumentException) {
        }

        putenv('ZEF_MUTTEST_INT=1');
        self::assertSame(1, Env::int('ZEF_MUTTEST_INT', 5, 1, 10, true), 'min boundary passes strict mode');
        putenv('ZEF_MUTTEST_INT=10');
        self::assertSame(10, Env::int('ZEF_MUTTEST_INT', 5, 1, 10, true), 'max boundary passes strict mode');
    }

    public function testEnvBoolStringAndCsvParsing(): void
    {
        self::assertFalse(Env::bool('ZEF_MUTTEST_BOOL'));
        self::assertTrue(Env::bool('ZEF_MUTTEST_BOOL', true));
        putenv('ZEF_MUTTEST_BOOL=true');
        self::assertTrue(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=false');
        self::assertFalse(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=1');
        self::assertTrue(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=0');
        self::assertFalse(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=on');
        self::assertTrue(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=no');
        self::assertFalse(Env::bool('ZEF_MUTTEST_BOOL'));
        putenv('ZEF_MUTTEST_BOOL=');
        self::assertTrue(Env::bool('ZEF_MUTTEST_BOOL', true), 'empty value falls back to default');

        self::assertSame('', Env::string('ZEF_MUTTEST_STR'));
        self::assertSame('dflt', Env::string('ZEF_MUTTEST_STR', 'dflt'));
        putenv('ZEF_MUTTEST_STR=');
        self::assertSame('dflt', Env::string('ZEF_MUTTEST_STR', 'dflt'), 'empty string falls back');
        putenv('ZEF_MUTTEST_STR=value');
        self::assertSame('value', Env::string('ZEF_MUTTEST_STR', 'dflt'));

        self::assertSame([], Env::csv('ZEF_MUTTEST_CSV'));
        putenv('ZEF_MUTTEST_CSV=');
        self::assertSame([], Env::csv('ZEF_MUTTEST_CSV'));
        putenv('ZEF_MUTTEST_CSV=   ');
        self::assertSame([], Env::csv('ZEF_MUTTEST_CSV'));
        putenv('ZEF_MUTTEST_CSV=a, b , c');
        self::assertSame(['a', 'b', 'c'], Env::csv('ZEF_MUTTEST_CSV'));
        putenv('ZEF_MUTTEST_CSV=,,x,,');
        self::assertSame(['x'], Env::csv('ZEF_MUTTEST_CSV'), 'empty items are filtered');
    }

    // -----------------------------------------------------------------
    // PrometheusRenderer
    // -----------------------------------------------------------------

    public function testPrometheusRendersCountersLabelsAndHistogramMembers(): void
    {
        $renderer = new PrometheusRenderer();
        self::assertSame('', $renderer->render(new MutationDeepInfraMeter([])), 'empty snapshot renders nothing');

        $counterOnly = $renderer->render(new MutationDeepInfraMeter([
            'http_requests|{"route":"/x"}' => ['count' => 5, 'sum' => 5.0, 'attributes' => []],
        ]));
        self::assertSame("# TYPE http_requests counter\nhttp_requests{route=\"/x\"} 5.0\n", $counterOnly);

        $observations = $renderer->render(new MutationDeepInfraMeter([
            'latency|{}' => ['count' => 3, 'sum' => 7.5, 'attributes' => []],
        ]));
        self::assertStringContainsString("# TYPE latency_sum counter\n", $observations);
        self::assertStringContainsString("# TYPE latency_count counter\n", $observations);
        self::assertStringContainsString("latency_sum 7.5\n", $observations);
        self::assertStringContainsString("latency_count 3.0\n", $observations);
    }

    public function testPrometheusSanitizesNamesLabelsAndEscapesValues(): void
    {
        $renderer = new PrometheusRenderer();

        $sanitized = $renderer->render(new MutationDeepInfraMeter([
            '1bad name|{"0dim":1,"ok":2,"bad":{"drop":true}}' => ['count' => 1, 'sum' => 1.0, 'attributes' => []],
        ]));
        self::assertStringContainsString('# TYPE zef_1bad_name counter', $sanitized);
        self::assertStringContainsString('lbl_0dim="1"', $sanitized, 'label names starting with digits get prefixed');
        self::assertStringContainsString('ok="2"', $sanitized);
        self::assertStringNotContainsString('drop', $sanitized, 'non-scalar attributes are dropped');

        $rawValue = 'a"b\c' . "\n" . 'd';
        $escaped = $renderer->render(new MutationDeepInfraMeter([
            'metric|' . json_encode(['label' => $rawValue]) => ['count' => 1, 'sum' => 1.0, 'attributes' => []],
        ]));
        self::assertStringContainsString('label="a\"b\\\c\nd"', $escaped);

        $unnamed = $renderer->render(new MutationDeepInfraMeter([
            '|{"x":1}' => ['count' => 1, 'sum' => 1.0, 'attributes' => []],
        ]));
        self::assertStringContainsString('zef_unnamed_metric', $unnamed);

        $statics = $renderer->render(new MutationDeepInfraMeter([
            'm|{"b":2}' => ['count' => 1, 'sum' => 1.0, 'attributes' => []],
        ]), ['z' => '26', 'a' => '1']);
        self::assertStringContainsString('{a="1",b="2",z="26"}', $statics, 'static + dynamic labels merge and sort');

        $specials = $renderer->render(new MutationDeepInfraMeter([
            'm|{"n":"needs bracing"}' => ['count' => INF, 'sum' => INF, 'attributes' => []],
            'm2|{}' => ['count' => NAN, 'sum' => 1.0, 'attributes' => []],
        ]));
        self::assertStringContainsString('+Inf', $specials);
        self::assertStringContainsString('NaN', $specials, 'NaN count renders as NaN');

        $negInf = $renderer->render(new MutationDeepInfraMeter([
            'm|{}' => ['count' => -INF, 'sum' => -INF, 'attributes' => []],
        ]));
        self::assertStringContainsString('-Inf', $negInf);
    }

    public function testPrometheusCapsSeriesAtFourThousandNinetySix(): void
    {
        $snapshot = [];
        for ($i = 0; $i < 4097; ++$i) {
            $snapshot['metric|{"i":' . $i . '}'] = ['count' => 1, 'sum' => 1.0, 'attributes' => []];
        }
        $rendered = new PrometheusRenderer()->render(new MutationDeepInfraMeter($snapshot));
        $lines = explode("\n", trim($rendered));
        self::assertCount(4097, $lines, 'one shared TYPE line + 4096 value lines');
        self::assertSame('# TYPE metric counter', $lines[0]);
        self::assertSame('metric{i="4095"} 1.0', $lines[4096], 'last emitted series is 4095');
        self::assertStringNotContainsString('i="4096"', $rendered, 'series beyond the cap are dropped');
    }
}

/**
 * @internal
 */
final class MutationDeepInfraMeter implements MeterInterface
{
    /**
     * @param array<string, array{count: float|int, sum: float, attributes: array<string, mixed>}> $snapshot
     */
    public function __construct(private readonly array $snapshot) {}

    #[\Override]
    public function increment(string $name, float|int $value = 1, array $attributes = []): void {}

    #[\Override]
    public function observe(string $name, float $value, array $attributes = []): void {}

    #[\Override]
    public function snapshot(): array
    {
        return $this->snapshot;
    }
}
