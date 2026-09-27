<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 6 — Domain inti zona core (ronde 3).
 *
 * Kurikulum chunk dom-core-b (233 escape baseline): batas ±1 SEMUA konstanta
 * (tracestate 512, token 128, atribut 16/64/256/4096, header 32/64, retry
 * 0/10/10000/60000), grammar W3C (traceid/spanid/flags/tracestate/token),
 * cron lima-field penuh (wildcard/range/list/step, or-semantika dom-dow,
 * boundary nanodetik strictly-greater), eksponen backoff + rounding half,
 * guard konjungtif envelope/context, redaksi atribut sensitif mixed-case,
 * eksepsi bawaan (pesan, kode 0, previous), radix-scope policy normalize.
 *
 * Setiap test membunuh mutan spesifik dari build/fase6/escapes-dom-core-b.txt.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheItem;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatchException;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Exception\CircularAliasException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Job\FixedIntervalSchedule;
use Zef\Framework\Job\JobCancelledException;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\JobTimeoutException;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageResult;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\CorrelationHeaders;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\RetryBackoffPolicy;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Policy\ArchitecturePolicy;

/**
 * @internal
 */
final class EdgeMatrixF6CoreTest extends TestCase
{
    private const string TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    // ---------------------------------------- CorrelationContext (48 escape)

    public function testCorrelationValidMinimalWireIsExactly55Bytes(): void
    {
        $ctx = $this->validContext();
        self::assertSame('00-' . $this->tid() . '-' . $this->sid() . '-01', $ctx->traceParent());
        self::assertSame(55, $ctx->propagationBytes());
    }

    public function testCorrelationTraceStateAddsLenPlusOneByte(): void
    {
        $ctx = $this->validContext(traceState: 'a=b');
        self::assertSame(59, $ctx->propagationBytes());
    }

    public function testCorrelationTraceIdGrammarIsAnchoredAndLowercase(): void
    {
        foreach ([str_repeat('a', 31), str_repeat('a', 33), strtoupper($this->tid()), 'z' . substr($this->tid(), 1), substr($this->tid(), 0, 32) . 'beef', str_repeat('0', 32)] as $bad) {
            try {
                new CorrelationContext($bad, $this->sid(), '01', null, 'op-1');
                self::fail("Trace ID '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Anchor removal: prefix/suffix garbage pada panjang persis 32+payload.
        try {
            new CorrelationContext('ffff' . $this->tid(), $this->sid(), '01', null, 'op-1');
            self::fail('Trace ID 36 hex harus ditolak (anchor $).');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new CorrelationContext('zzzz' . $this->tid(), $this->sid(), '01', null, 'op-1');
            self::fail('Prefix non-hex harus ditolak (anchor ^).');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCorrelationSpanIdGrammarIsAnchored(): void
    {
        foreach ([str_repeat('b', 15), str_repeat('b', 17), str_repeat('0', 16), $this->sid() . 'ff', 'yy' . $this->sid()] as $bad) {
            try {
                new CorrelationContext($this->tid(), $bad, '01', null, 'op-1');
                self::fail("Span ID '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCorrelationTraceFlagsOnlyZeroAndSampledBits(): void
    {
        new CorrelationContext($this->tid(), $this->sid(), '00', null, 'op-1');
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1');
        foreach (['02', 'fe', 'ff', '1f', 'g0', '0', '000'] as $bad) {
            try {
                new CorrelationContext($this->tid(), $this->sid(), $bad, null, 'op-1');
                self::fail("Trace flags '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCorrelationTraceStateBoundary512AndGrammar(): void
    {
        $ok512 = 'a=' . str_repeat('x', 510);
        new CorrelationContext($this->tid(), $this->sid(), '01', $ok512, 'op-1');
        new CorrelationContext($this->tid(), $this->sid(), '01', 'a=b, c=d', 'op-1');
        new CorrelationContext($this->tid(), $this->sid(), '01', 'a=b,c=d', 'op-1');
        foreach (['', 'a=' . str_repeat('x', 511), 'A=b', 'a b=c', '=b'] as $bad) {
            try {
                new CorrelationContext($this->tid(), $this->sid(), '01', $bad, 'op-1');
                self::fail("Tracestate '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCorrelationTokenBounds128AndPrintableGrammar(): void
    {
        new CorrelationContext($this->tid(), $this->sid(), '01', null, str_repeat('a', 128));
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', str_repeat('b', 128));
        foreach (['', str_repeat('a', 129), 'a b', "a\x7Fb", 'ré'] as $bad) {
            try {
                new CorrelationContext($this->tid(), $this->sid(), '01', null, $bad);
                self::fail("Operation ID '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', 'id em');
            self::fail('Idempotency key dengan spasi harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCorrelationAttributeCountAndKeyGrammar(): void
    {
        $sixteen = [];
        for ($i = 0; $i < 16; ++$i) {
            $sixteen['k' . $i] = 'v';
        }
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $sixteen);
        $seventeen = $sixteen;
        $seventeen['k17'] = 'v';

        try {
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $seventeen);
            self::fail('Atribut ke-17 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        foreach (['' => 'x', str_repeat('a', 65) => 'x', 'bad!' => 'x', 'bad space' => 'x'] as $badKey => $val) {
            try {
                new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, [$badKey => $val]);
                self::fail("Key atribut '{$badKey}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Key numerik (bukan string) tetap ditolak meski PHP meng-cast.
        try {
            // @phpstan-ignore-next-line (key int disengaja)
            $this->validContext(['safe']); // list => key int 0
            self::fail('Key int harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, [str_repeat('a', 64) => 'v']);
    }

    public function testCorrelationAttributeValueTypesAndBounds(): void
    {
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, ['s' => str_repeat('x', 256), 'i' => 42, 'f' => 1.5, 'b' => true, 'n' => null]);

        try {
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, ['s' => str_repeat('x', 257)]);
            self::fail('Nilai string 257 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            // @phpstan-ignore-next-line (nilai array disengaja)
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, ['a' => ['nested']]);
            self::fail('Nilai array harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCorrelationAggregateBytesExact4096Boundary(): void
    {
        // 15 x (1+256) = 3855; terakhir 41+200 = 241 -> total tepat 4096 (OK).
        $attrs = [];
        for ($i = 0; $i < 15; ++$i) {
            $attrs[chr(97 + $i)] = str_repeat('x', 256);
        }
        $attrs[str_repeat('a', 41)] = str_repeat('y', 200);
        self::assertCount(16, $attrs);
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $attrs);

        // Satu byte lagi: 42+200 = 242 -> 4097 -> throw. Membunuh Assignment/
        // PlusEqual yang merusak akumulasi lintas-entri.
        $attrs2 = [];
        for ($i = 0; $i < 15; ++$i) {
            $attrs2[chr(97 + $i)] = str_repeat('x', 256);
        }
        $attrs2[str_repeat('b', 42)] = str_repeat('y', 200);

        try {
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $attrs2);
            self::fail('Agregat 4097 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCorrelationNullAttributeCostsExactly4Bytes(): void
    {
        // 12 x (64+256) = 3840; +13 (64+187) = 4091; +null(1+4) = 4096 -> OK.
        $attrs = [];
        for ($i = 0; $i < 12; ++$i) {
            $attrs[str_repeat(chr(97 + $i), 64)] = str_repeat('x', 256);
        }
        $attrs[str_repeat('m', 64)] = str_repeat('y', 187);
        $attrs['n'] = null;
        self::assertCount(14, $attrs);
        new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $attrs);

        // Prior 4092 + null(5) = 4097 -> throw (membunuh IntegerNegation null=4 -> -4).
        $attrs2 = [];
        for ($i = 0; $i < 12; ++$i) {
            $attrs2[str_repeat(chr(97 + $i), 64)] = str_repeat('x', 256);
        }
        $attrs2[str_repeat('m', 64)] = str_repeat('y', 188); // 252 -> 4092
        $attrs2['n'] = null; // +5 -> 4097 -> throw.

        try {
            new CorrelationContext($this->tid(), $this->sid(), '01', null, 'op-1', null, $attrs2);
            self::fail('Null harus diperhitungkan 4 byte: total 4097 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCorrelationRedactionIsCaseInsensitiveAndTyped(): void
    {
        $sha = 'sha256:' . hash('sha256', 'abc');
        $ctx = $this->validContext([
            'Authorization' => 'abc',      // mixed-case -> redaksi string
            'user_token' => 'abc',         // mengandung 'token'
            'x-secret-y' => 'abc',
            'password' => 12345,           // non-string -> [REDACTED]
            'theme' => 'dark',             // passthrough
            'count' => 42,
            'flag' => true,
            'note' => null,
        ]);
        $red = $ctx->redactedAttributes();
        self::assertSame($sha, $red['Authorization']);
        self::assertSame($sha, $red['user_token']);
        self::assertSame($sha, $red['x-secret-y']);
        self::assertSame('[REDACTED]', $red['password']);
        self::assertSame('dark', $red['theme']);
        self::assertSame(42, $red['count']);
        self::assertTrue($red['flag']);
        self::assertNull($red['note']);
    }

    // ------------------------------------------------- SpanContext (13 escape)

    public function testSpanContextGrammarAnchoredBothIds(): void
    {
        new SpanContext($this->tid(), $this->sid());
        foreach ([[str_repeat('a', 31), $this->sid()], [$this->tid() . 'aa', $this->sid()], [strtoupper($this->tid()), $this->sid()], ['zz' . $this->tid(), $this->sid()], [$this->tid(), str_repeat('b', 15)], [$this->tid(), $this->sid() . 'bb'], [$this->tid(), 'zz' . $this->sid()]] as [$t, $s]) {
            try {
                new SpanContext($t, $s);
                self::fail("Pasangan '{$t}/{$s}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSpanContextInvalidIsAllZerosAndUnsampled(): void
    {
        $inv = SpanContext::invalid();
        self::assertSame('00-' . str_repeat('0', 32) . '-' . str_repeat('0', 16) . '-00', $inv->traceParent());
        self::assertFalse($inv->isValid());
        self::assertTrue(new SpanContext($this->tid(), $this->sid())->isValid());
        self::assertFalse(new SpanContext($this->tid(), str_repeat('0', 16))->isValid());
        self::assertFalse(new SpanContext(str_repeat('0', 32), $this->sid())->isValid());
    }

    public function testSpanContextSampledFlagReflectsInTraceParent(): void
    {
        self::assertSame('00-' . $this->tid() . '-' . $this->sid() . '-01', new SpanContext($this->tid(), $this->sid())->traceParent());
        self::assertSame('00-' . $this->tid() . '-' . $this->sid() . '-00', new SpanContext($this->tid(), $this->sid(), false)->traceParent());
    }

    // ---------------------------------------- RetryBackoffPolicy (24 escape)

    public function testTelemetryBackoffCtorGuardsAreInclusive(): void
    {
        new RetryBackoffPolicy(0, 0, 0);
        new RetryBackoffPolicy(1, 100, 100);
        foreach ([[-1, 0, 100], [0, -1, 100], [0, 200, 100]] as [$r, $i, $m]) {
            try {
                new RetryBackoffPolicy($r, $i, $m);
                self::fail('Kombinasi invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTelemetryBackoffShouldRetryBoundaryExact(): void
    {
        $p = new RetryBackoffPolicy(3, 100, 1000);
        self::assertTrue($p->shouldRetry(0));
        self::assertTrue($p->shouldRetry(2));
        self::assertFalse($p->shouldRetry(3));
        self::assertFalse($p->shouldRetry(99));

        try {
            $p->shouldRetry(-1);
            self::fail('Index negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTelemetryBackoffDelayExponentialAndCapped(): void
    {
        $p = new RetryBackoffPolicy(10, 100, 1000);
        self::assertSame(100, $p->delayMs(0));
        self::assertSame(200, $p->delayMs(1));
        self::assertSame(400, $p->delayMs(2));
        self::assertSame(800, $p->delayMs(3));
        self::assertSame(1000, $p->delayMs(4));
        self::assertSame(1000, $p->delayMs(30));

        try {
            $p->delayMs(-1);
            self::fail('Delay index negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTelemetryBackoffFromEnvironmentClampsAndDefaults(): void
    {
        $this->withEnv(['ZEF_OTEL_RETRY_ATTEMPTS' => null, 'ZEF_OTEL_RETRY_DELAY_MS' => null, 'ZEF_OTEL_RETRY_DELAY_CAP_MS' => null], function (): void {
            $p = RetryBackoffPolicy::fromEnvironment();
            self::assertSame(2, $p->maxRetries);
            self::assertSame(100, $p->initialDelayMs);
            self::assertSame(1000, $p->maxDelayMs);
        });
        $this->withEnv(['ZEF_OTEL_RETRY_ATTEMPTS' => '11', 'ZEF_OTEL_RETRY_DELAY_MS' => '10001', 'ZEF_OTEL_RETRY_DELAY_CAP_MS' => '60001'], function (): void {
            $p = RetryBackoffPolicy::fromEnvironment();
            self::assertSame(10, $p->maxRetries);
            self::assertSame(10000, $p->initialDelayMs);
            self::assertSame(60000, $p->maxDelayMs);
        });
        $this->withEnv(['ZEF_OTEL_RETRY_ATTEMPTS' => '-3', 'ZEF_OTEL_RETRY_DELAY_MS' => '-1', 'ZEF_OTEL_RETRY_DELAY_CAP_MS' => '50'], function (): void {
            $p = RetryBackoffPolicy::fromEnvironment();
            self::assertSame(0, $p->maxRetries);
            self::assertSame(0, $p->initialDelayMs);
            self::assertSame(50, $p->maxDelayMs);
        });
        $this->withEnv(['ZEF_OTEL_RETRY_ATTEMPTS' => 'abc', 'ZEF_OTEL_RETRY_DELAY_MS' => '', 'ZEF_OTEL_RETRY_DELAY_CAP_MS' => 'x'], function (): void {
            $p = RetryBackoffPolicy::fromEnvironment();
            self::assertSame(2, $p->maxRetries);
            self::assertSame(100, $p->initialDelayMs);
            self::assertSame(1000, $p->maxDelayMs);
        });
    }

    // --------------------------------------------- Job RetryPolicy (23 escape)

    public function testJobRetryCtorGuardsInclusiveBounds(): void
    {
        new RetryPolicy(1, 0, 0, 1.0, 0);
        foreach ([[0, 100, 30000, 2.0, 0], [1, -1, 30000, 2.0, 0], [1, 200, 100, 2.0, 0], [1, 100, 30000, 0.9, 0], [1, 100, 30000, 2.0, -1]] as $args) {
            try {
                new RetryPolicy(...$args);
                self::fail('Kombinasi invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $p = new RetryPolicy();
        self::assertSame(3, $p->maxAttempts);
        self::assertSame(100, $p->initialDelayMs);
        self::assertSame(30000, $p->maxDelayMs);
        self::assertSame(2.0, $p->multiplier);
        self::assertSame(0, $p->jitterMs);
    }

    public function testJobRetryShouldRetryExactBoundary(): void
    {
        $p = new RetryPolicy(3);
        self::assertTrue($p->shouldRetry(1));
        self::assertTrue($p->shouldRetry(2));
        self::assertFalse($p->shouldRetry(3));
    }

    public function testJobRetryDelayRoundingAndCap(): void
    {
        $p = new RetryPolicy(5, 101, 30000, 1.5);
        self::assertSame(101, $p->delayMs(1));
        self::assertSame(152, $p->delayMs(2)); // 151.5 -> round half up
        self::assertSame(227, $p->delayMs(3)); // 227.25 -> round -> 227
        $q = new RetryPolicy(5, 101, 30000, 1.25);
        self::assertSame(126, $q->delayMs(2)); // 126.25 -> round -> 126 (bukan ceil 127)
        $capped = new RetryPolicy(9, 1000, 2500, 2.0);
        self::assertSame(2000, $capped->delayMs(2));
        self::assertSame(2500, $capped->delayMs(3));

        try {
            $p->delayMs(0);
            self::fail('Attempt 0 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testJobRetryJitterStaysInRangeAndCapped(): void
    {
        $p = new RetryPolicy(5, 100, 1000, 2.0, 100);
        for ($i = 0; $i < 25; ++$i) {
            $d = $p->delayMs(1);
            self::assertTrue($d >= 100 && $d <= 200, "Jitter keluar rentang: {$d}");
        }
        $capped = new RetryPolicy(5, 1000, 1050, 2.0, 100);
        for ($i = 0; $i < 25; ++$i) {
            self::assertTrue($capped->delayMs(2) <= 1050);
        }
    }

    // -------------------------------------------- CronExpression (36 escape)

    public function testCronParseTrimAndWhitespaceSplit(): void
    {
        $c = CronExpression::parse("  */15\t*  * * *  ");
        self::assertSame([0, 15, 30, 45], $c->minutes);
        self::assertSame("*/15\t*  * * *", $c->expression);
    }

    public function testCronParseFieldArms(): void
    {
        self::assertSame([0, 59], CronExpression::parse('59,0 * * * *')->minutes);
        self::assertSame([1, 3, 5], CronExpression::parse('1-5/2 * * * *')->minutes);
        self::assertSame([20, 30, 40, 50], CronExpression::parse('20/10 * * * *')->minutes);
        self::assertSame([5, 10, 12, 14, 20], CronExpression::parse('5,10-15/2,20 * * * *')->minutes);
        self::assertSame(range(0, 59, 7), CronExpression::parse('*/7 * * * *')->minutes);
        self::assertSame(range(1, 12), CronExpression::parse('0 12 1 */1 *')->months);
    }

    public function testCronParseMonthAndDomFields(): void
    {
        $c = CronExpression::parse('0 0 1 12 *');
        self::assertSame([1], $c->daysOfMonth);
        self::assertSame([12], $c->months);
        self::assertTrue($c->domRestricted);
        self::assertFalse($c->dowRestricted);
        $w = CronExpression::parse('* * * * *');
        self::assertFalse($w->domRestricted);
        self::assertFalse($w->dowRestricted);
        self::assertSame(range(1, 31), $w->daysOfMonth);
    }

    public function testCronParseRejectsInvalidFields(): void
    {
        // NOTE: '* * * * 7' used to be rejected here; v2.31.0 accepts dow 7
        // and normalizes it to 0 (the documented contract) — see
        // JobCronEngineV2Test::testDowSevenIsAcceptedAndMeansSunday().
        foreach (['* * * *', '* * * * * *', '60 * * * *', '100 * * * *', '* 24 * * *', '* * 32 * *', '* * * 13 *', '* * * * 8', '*/0 * * * *', '*/x * * * *', '1//2 * * * *', '1,,2 * * * *', '*-* * * * *', '30-10 * * * *', '60-59 * * * *', 'x * * * *', '5x * * * *', 'x5 * * * *'] as $bad) {
            try {
                CronExpression::parse($bad);
                self::fail("Ekspresi '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCronDowSevenIsNormalizedToSunday(): void
    {
        self::assertSame([0], CronExpression::parse('* * * * 7')->daysOfWeek);
    }

    public function testCronParseErrorMessageCarriesFieldAndExpression(): void
    {
        try {
            CronExpression::parse('61 * * * *');
            self::fail('Harus gagal.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("value '61' out of bounds", $e->getMessage());
        }

        try {
            CronExpression::parse('bogus here');
            self::fail('Harus gagal.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('bogus here', $e->getMessage());
            self::assertStringContainsString('exactly 5 fields', $e->getMessage());
        }
    }

    public function testCronMatchesUtcDomDowOrSemantics(): void
    {
        $both = CronExpression::parse('* * 13 * 1'); // tanggal 13 ATAU Senin
        // 2024-08-13 = Selasa (dom match saja), 2024-08-19 = Senin (dow saja),
        // 2024-08-20 = Selasa biasa (tidak keduanya).
        self::assertTrue($both->matchesUtc($this->ts(0, 0, 0, 8, 13, 2024)));
        self::assertTrue($both->matchesUtc($this->ts(12, 30, 0, 8, 19, 2024)));
        self::assertFalse($both->matchesUtc($this->ts(12, 30, 0, 8, 20, 2024)));
        $and = CronExpression::parse('* * * * 1'); // dom wildcard -> AND
        self::assertTrue($and->matchesUtc($this->ts(9, 0, 0, 8, 19, 2024)));
        self::assertFalse($and->matchesUtc($this->ts(9, 0, 0, 8, 20, 2024)));
        $month = CronExpression::parse('30 12 * 2 *');
        self::assertTrue($month->matchesUtc($this->ts(12, 30, 0, 2, 10, 2024)));
        self::assertFalse($month->matchesUtc($this->ts(12, 30, 0, 3, 10, 2024)));
    }

    public function testCronNextRunStrictlyAfterNowAtMinuteBoundary(): void
    {
        $every = CronExpression::parse('* * * * *');
        $t = $this->ts(12, 0, 0, 1, 15, 2024);
        self::assertSame(($t + 60) * 1_000_000_000, $every->nextRunAfter($t * 1_000_000_000));
        self::assertSame(($t + 60) * 1_000_000_000, $every->nextRunAfter($t * 1_000_000_000 + 1));
        self::assertSame($t * 1_000_000_000, $every->nextRunAfter($t * 1_000_000_000 - 1));

        try {
            $every->nextRunAfter(-1);
            self::fail('Waktu negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCronNextRunSkipsToNextMatch(): void
    {
        $c = CronExpression::parse('30 12 * * *');
        $t = $this->ts(12, 0, 0, 1, 15, 2024);
        self::assertSame(($t + 1800) * 1_000_000_000, $c->nextRunAfter($t * 1_000_000_000));
        $at = $this->ts(12, 30, 0, 1, 15, 2024);
        self::assertSame(($at + 86400) * 1_000_000_000, $c->nextRunAfter($at * 1_000_000_000));
    }

    public function testCronDescribeAppendsUtc(): void
    {
        self::assertSame('*/5 * * * * (UTC)', CronExpression::parse('*/5 * * * *')->describe());
    }

    // ------------------------------------------------- FixedIntervalSchedule

    public function testFixedIntervalBoundsAndNextRun(): void
    {
        new FixedIntervalSchedule(1);
        new FixedIntervalSchedule(31536000);
        foreach ([0, -1, 31536001] as $bad) {
            try {
                new FixedIntervalSchedule($bad);
                self::fail("Interval {$bad} harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $s = new FixedIntervalSchedule(4);
        self::assertSame(12_000_000_000, $s->nextRunAfter(10_000_000_000));
        self::assertSame(4_000_000_000, $s->nextRunAfter(3_999_999_999));
        self::assertSame(8_000_000_000, $s->nextRunAfter(4_000_000_000));
        self::assertSame('every 4s', $s->describe());
    }

    // --------------------------------------------------- JobContext (12 escape)

    public function testJobContextGuardsAndBounds(): void
    {
        new JobContext('job-12345678', 1);
        new JobContext('job-12345678', 1, 'corr-12345678', self::TRACEPARENT, ['k' => 1]);
        foreach ([
            ['', 1], ['short', 1], ['job-12345678', 0],
        ] as [$id, $attempt]) {
            try {
                new JobContext($id, $attempt);
                self::fail('Job context invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            new JobContext('job-12345678', 1, 'bad id!');
            self::fail('Correlation invalid harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new JobContext('job-12345678', 1, null, 'not-traceparent');
            self::fail('Traceparent invalid harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new JobContext('job-12345678', 1, null, null, ['' => 1]);
            self::fail('Key atribut kosong harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new JobContext('job-12345678', 1, null, null, [str_repeat('k', 129) => 1]);
            self::fail('Key atribut 129 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testJobContextDeadlineSemanticsInclusive(): void
    {
        $c = new JobContext('job-12345678', 2);
        self::assertFalse($c->isTimedOut());
        self::assertNull($c->deadlineUnixNano());
        $d = new JobContext('job-12345678', 2, deadlineUnixNano: 5000);
        self::assertFalse($d->isTimedOut(4999));
        self::assertTrue($d->isTimedOut(5000));
        self::assertTrue($d->isTimedOut(5001));
        self::assertFalse($d->isCancelled());
        $future = new JobContext('job-12345678', 2, deadlineUnixNano: PHP_INT_MAX);
        $future->throwIfCancelled();
        self::assertSame(5000, $d->deadlineUnixNano());
    }

    public function testJobContextCancelWinsOverDeadline(): void
    {
        $c = new JobContext('job-12345678', 1, deadlineUnixNano: 1)->cancel();
        self::assertTrue($c->isCancelled());

        try {
            $c->throwIfCancelled();
            self::fail('Cancelled harus melempar JobCancelledException.');
        } catch (JobCancelledException) {
            self::addToAssertionCount(1);
        }
        $t = (new JobContext('job-12345678', 1, deadlineUnixNano: 1));

        try {
            $t->throwIfCancelled();
            self::fail('Deadline terlewati harus melempar JobTimeoutException.');
        } catch (JobTimeoutException) {
            self::addToAssertionCount(1);
        }
    }

    public function testJobContextWithDeadlineMs(): void
    {
        $base = new JobContext('job-12345678', 3, 'corr-12345678', null, ['a' => 1]);

        try {
            $base->withDeadlineMs(0);
            self::fail('Timeout 0 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $t0 = (int) (microtime(true) * 1_000_000_000);
        $d = $base->withDeadlineMs(1_000_000);
        $t1 = (int) (microtime(true) * 1_000_000_000);
        self::assertNotNull($d->deadlineUnixNano());
        self::assertTrue($d->deadlineUnixNano() >= $t0 + 1_000_000 * 1_000_000 - 200_000);
        self::assertTrue($d->deadlineUnixNano() <= $t1 + 1_000_000 * 1_000_000 + 200_000);
        self::assertSame(3, $d->attempt);
        self::assertSame('corr-12345678', $d->correlationId);
        self::assertSame(['a' => 1], $d->attributes);
        self::assertFalse($d->isCancelled());
    }

    public function testJobContextCancelPreservesEverything(): void
    {
        $c = new JobContext('job-12345678', 5, 'corr-12345678', null, [], 42);
        $x = $c->cancel();
        self::assertTrue($x->isCancelled());
        self::assertSame(42, $x->deadlineUnixNano());
        self::assertSame(5, $x->attempt);
        self::assertSame('corr-12345678', $x->correlationId);
    }

    // ------------------------------------------------ JobEnvelope (11 escape)

    public function testJobEnvelopeGuards(): void
    {
        new JobEnvelope('job-12345678', 'mail.send', null, 0);
        new JobEnvelope('job-12345678', 'mail.send', null, 0, 5, 3, 'corr-12345678', self::TRACEPARENT, ['X-Token-A' => 'v']);
        $bad = [
            ['bad id', 'mail.send', null, 0],
            ['job-12345678', '', null, 0],
            ['job-12345678', str_repeat('t', 256), null, 0],
            ['job-12345678', 'mail.send', null, 0, 0, 0],
            ['job-12345678', 'mail.send', null, 0, 0, 1, 'bad id'],
            ['job-12345678', 'mail.send', null, 0, 0, 1, null, 'nope'],
        ];
        foreach ($bad as $args) {
            try {
                new JobEnvelope(...$args);
                self::fail('Envelope invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testJobEnvelopeHeaderCountAndGrammar(): void
    {
        $h32 = [];
        for ($i = 0; $i < 32; ++$i) {
            $h32['h.' . $i . '-ok'] = 'v';
        }
        new JobEnvelope('job-12345678', 'mail.send', null, 0, 0, 1, null, null, $h32);
        $h33 = $h32;
        $h33['extra'] = 'v';

        try {
            new JobEnvelope('job-12345678', 'mail.send', null, 0, 0, 1, null, null, $h33);
            self::fail('33 header harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        new JobEnvelope('job-12345678', 'mail.send', null, 0, 0, 1, null, null, [str_repeat('n', 128) => str_repeat('v', 2048)]);
        foreach ([['' => 'v'], [str_repeat('n', 129) => 'v'], ['bad name!' => 'v'], ['ok!' => 'v'], ['!ok' => 'v'], ['ok' => 42], ['ok' => str_repeat('v', 2049)]] as $badHeaders) {
            try {
                // @phpstan-ignore-next-line (header int disengaja)
                new JobEnvelope('job-12345678', 'mail.send', null, 0, 0, 1, null, null, $badHeaders);
                self::fail('Header invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testJobEnvelopeNextAttemptAddsAndPreserves(): void
    {
        $e = new JobEnvelope('job-12345678', 'mail.send', ['p' => 1], 7, 9, 4, 'corr-12345678', null, ['a' => 'b']);

        try {
            $e->nextAttempt(-1);
            self::fail('Delay negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $t0 = (int) (microtime(true) * 1_000_000_000);
        $n = $e->nextAttempt(1_000_000);
        $t1 = (int) (microtime(true) * 1_000_000_000);
        self::assertSame(5, $n->attempt);
        self::assertSame(9, $n->priority);
        self::assertSame(['p' => 1], $n->payload);
        self::assertSame('corr-12345678', $n->correlationId);
        self::assertSame(['a' => 'b'], $n->headers);
        self::assertSame('job-12345678', $n->jobId);
        self::assertTrue($n->availableAtUnixNano >= $t0 + 1_000_000_000_000 - 200_000);
        self::assertTrue($n->availableAtUnixNano <= $t1 + 1_000_000_000_000 + 200_000);
        self::assertSame(7, $e->availableAtUnixNano);
    }

    // ------------------------------------------------- CqrsContext (10 escape)

    public function testCqrsContextGuards(): void
    {
        new CqrsContext('corr-12345678');
        new CqrsContext('corr-12345678', self::TRACEPARENT, 'idem-123456', ['k' => 'v']);
        $bad = [
            ['short'],
            ['corr-12345678', 'nope'],
            ['corr-12345678', null, 'bad id!'],
            ['corr-12345678', null, null, ['' => 1]],
            ['corr-12345678', null, null, [str_repeat('k', 129) => 1]],
        ];
        foreach ($bad as $args) {
            try {
                new CqrsContext(...$args);
                self::fail('CQRS context invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCqrsContextToEventContextCarriesAndTimestamps(): void
    {
        $tp = self::TRACEPARENT;
        $c = new CqrsContext('corr-12345678', $tp, null, ['k' => 'v']);
        $t0 = (int) (microtime(true) * 1_000_000_000);
        $e = $c->toEventContext();
        $t1 = (int) (microtime(true) * 1_000_000_000);
        self::assertSame('corr-12345678', $e->correlationId);
        self::assertSame($tp, $e->traceParent);
        self::assertSame(['k' => 'v'], $e->attributes);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $e->eventId);
        self::assertTrue($e->occurredAtUnixNano >= $t0 && $e->occurredAtUnixNano <= $t1, 'OccurredAt harus realtime (membunuh pengali 1e9 +-1).');
    }

    public function testCqrsContextCreateGeneratesOpaqueId(): void
    {
        $a = CqrsContext::create();
        $b = CqrsContext::create();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,128}$/', $a->correlationId);
        self::assertNotSame($a->correlationId, $b->correlationId);
    }

    public function testCqrsEventResultOnlyObjects(): void
    {
        $r = new CqrsEventResult('payload', [new \stdClass(), new \ArrayObject()]);
        self::assertSame('payload', $r->result);
        self::assertCount(2, $r->events);

        try {
            // @phpstan-ignore-next-line (nilai invalid disengaja)
            new CqrsEventResult(null, [new \stdClass(), 42]);
            self::fail('Event non-object harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        new CqrsEventResult(null);
    }

    // --------------------------------------------- EventRegistration (10 escape)

    public function testEventRegistrationAcceptsClassInterfaceAndRejectsGhost(): void
    {
        new EventRegistration(\DateTimeImmutable::class, static fn (): null => null);
        new EventRegistration(\Countable::class, static fn (): null => null);
        foreach (['', 'No\Such\Class\Here', \DateTimeImmutable::class . 'X'] as $bad) {
            try {
                new EventRegistration($bad, static fn (): null => null);
                self::fail("Event class '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventRegistrationRequiresCallable(): void
    {
        foreach ([42, 'no_such_function_xyz', [new \stdClass(), 'nope']] as $bad) {
            try {
                new EventRegistration(\DateTimeImmutable::class, $bad);
                self::fail('Listener non-callable harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventRegistrationAcceptsContextByArity(): void
    {
        $zero = new EventRegistration(\DateTimeImmutable::class, static fn (): null => null);
        self::assertFalse($zero->acceptsContext);
        $one = new EventRegistration(\DateTimeImmutable::class, static fn ($e): null => null);
        self::assertFalse($one->acceptsContext);
        $two = new EventRegistration(\DateTimeImmutable::class, static fn ($e, $c): null => null);
        self::assertTrue($two->acceptsContext);
        $objTwo = new EventRegistration(\DateTimeImmutable::class, new class {
            public function __invoke(mixed $a, mixed $b): void {}
        });
        self::assertTrue($objTwo->acceptsContext);
        $magic = new EventRegistration(\DateTimeImmutable::class, [new class {
            /** @param array<int|string, mixed> $args */
            public function __call(string $name, array $args): mixed
            {
                return null;
            }
        }, 'anything']);
        self::assertFalse($magic->acceptsContext);
        $str = new EventRegistration(\DateTimeImmutable::class, 'strlen');
        self::assertFalse($str->acceptsContext);
        self::assertSame(0, $zero->priority);
        self::assertSame(0, $zero->sequence);
        $prio = new EventRegistration(\DateTimeImmutable::class, static fn (): null => null, 7, 3);
        self::assertSame(7, $prio->priority);
        self::assertSame(3, $prio->sequence);
    }

    public function testEventRegistrationArrayListenerReflection(): void
    {
        $obj = new class {
            public function handle(mixed $event, mixed $ctx): void {}
        };
        $two = new EventRegistration(\DateTimeImmutable::class, $obj->handle(...));
        self::assertTrue($two->acceptsContext);
        $single = new class {
            public function handle(mixed $event): void {}
        };
        $one = new EventRegistration(\DateTimeImmutable::class, $single->handle(...));
        self::assertFalse($one->acceptsContext);
    }

    // ------------------------------------------ ArchitecturePolicy (9 escape)

    public function testArchitecturePolicyDefaultsAndGuards(): void
    {
        $p = new ArchitecturePolicy();
        self::assertSame(0, $p->maxCrossModuleRefs);
        self::assertSame(10000, $p->maxServiceRegistrations);
        self::assertSame(10000, $p->maxRouteRegistrations);
        self::assertSame(256, $p->maxResolutionDepth);
        new ArchitecturePolicy(0, 1, 1, 1);
        foreach ([[[-1, 1, 1, 1], 'maxCrossModuleRefs'], [[0, 0, 1, 1], 'maxServiceRegistrations'], [[0, 1, 0, 1], 'maxRouteRegistrations'], [[0, 1, 1, 0], 'maxResolutionDepth']] as [$args, $field]) {
            try {
                new ArchitecturePolicy(...$args);
                self::fail("{$field} = 0/-1 harus ditolak.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    // --------------------------------------------- MessageEnvelope (6 escape)

    public function testMessageEnvelopeGuards(): void
    {
        new MessageEnvelope('msg-12345678', 'order.created', null);
        new MessageEnvelope('msg-12345678', 'a:b/c-d.e', null, [str_repeat('h', 128) => str_repeat('v', 4096)]);
        $bad = [
            ['short', 't', null],
            ['msg-12345678', '', null],
            ['msg-12345678', str_repeat('t', 256), null],
            ['msg-12345678', 't', null, array_fill_keys(range(0, 63), 'v') + ['x64' => 'v']],
            ['msg-12345678', 't', null, ['' => 'v']],
            ['msg-12345678', 't', null, [str_repeat('h', 129) => 'v']],
            ['msg-12345678', 't', null, ['bad!' => 'v']],
            ['msg-12345678', 't', null, ['ok!' => 'v']],
            ['msg-12345678', 't', null, ['!ok' => 'v']],
            ['msg-12345678', 't', null, ['ok' => 42]],
            ['msg-12345678', 't', null, ['ok' => str_repeat('v', 4097)]],
        ];
        foreach ($bad as $i => $args) {
            try {
                new MessageEnvelope(...$args);
                self::fail("Envelope invalid #{$i} harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $h64 = [];
        for ($i = 0; $i < 64; ++$i) {
            $h64['h' . $i] = 'v';
        }
        new MessageEnvelope('msg-12345678', 't', null, $h64);
    }

    public function testMessageResultTransportBound(): void
    {
        new MessageResult('msg-12345678', true);
        new MessageResult('msg-12345678', false, str_repeat('t', 255));
        self::assertTrue(new MessageResult('msg-12345678', true)->accepted);
        foreach ([
            ['short', true, null],
            ['msg-12345678', true, str_repeat('t', 256)],
        ] as $args) {
            try {
                new MessageResult(...$args);
                self::fail('MessageResult invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMessageContextGuards(): void
    {
        new MessageContext();
        new MessageContext('corr-12345678', self::TRACEPARENT, ['k' => 1]);
        $bad = [
            ['bad id'],
            ['corr-12345678', 'nope'],
            ['corr-12345678', null, ['' => 1]],
            ['corr-12345678', null, [str_repeat('k', 129) => 1]],
        ];
        foreach ($bad as $args) {
            try {
                new MessageContext(...$args);
                self::fail('MessageContext invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------ EventContext + DispatchException

    public function testEventContextGuards(): void
    {
        new EventContext('event-12345678', 1);
        new EventContext('event-12345678', 1, 'corr-12345678', self::TRACEPARENT);
        $bad = [
            ['short', 1],
            ['event-12345678', 1, 'bad id!'],
            ['event-12345678', 1, null, 'nope'],
        ];
        foreach ($bad as $args) {
            try {
                new EventContext(...$args);
                self::fail('EventContext invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventDispatchExceptionCarriesFirstErrorAsPrevious(): void
    {
        $e1 = new \RuntimeException('pertama');
        $e2 = new \LogicException('kedua');
        $event = new \stdClass();
        $x = new EventDispatchException('gagal dispatch', [$e1, $e2], $event);
        self::assertSame('gagal dispatch', $x->getMessage());
        self::assertSame(0, $x->getCode());
        self::assertSame([$e1, $e2], $x->errors);
        self::assertSame($event, $x->event);
        self::assertSame($e1, $x->getPrevious());
        $empty = new EventDispatchException('tanpa error', [], $event);
        self::assertNull($empty->getPrevious());
    }

    // --------------------------------------------------- JobResult (4 escape)

    public function testJobResultDefaultsAndGuards(): void
    {
        $r = new JobResult('job-12345678', true, 'out', 2, true, true);
        self::assertTrue($r->completed);
        self::assertSame('out', $r->result);
        self::assertSame(2, $r->attempt);
        self::assertTrue($r->deadLettered);
        self::assertTrue($r->willRetry);
        $d = new JobResult('job-12345678', false);
        self::assertNull($d->result);
        self::assertSame(1, $d->attempt);
        self::assertFalse($d->deadLettered);
        self::assertFalse($d->willRetry);
        foreach ([['short', true], ['job-12345678', true, null, 0]] as $args) {
            try {
                new JobResult(...$args);
                self::fail('JobResult invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // --------------------------------------------------- CacheItem (3 escape)

    public function testCacheItemExpiryInclusiveAndWithoutExpiryNeverExpires(): void
    {
        $noExpiry = new CacheItem('v');
        self::assertFalse($noExpiry->isExpired(0));
        self::assertFalse($noExpiry->isExpired(PHP_INT_MAX));
        $item = new CacheItem('v', 1000);
        self::assertFalse($item->isExpired(999));
        self::assertTrue($item->isExpired(1000));
        self::assertTrue($item->isExpired(1001));
        self::assertFalse($item->isExpired(5));
        // hrtime path: expiry sangat kecil -> pasti lewat; PHP_INT_MAX -> belum.
        self::assertTrue(new CacheItem('v', 1)->isExpired());
        self::assertFalse(new CacheItem('v', PHP_INT_MAX)->isExpired());
    }

    // ------------------------------------------- CorrelationHeaders (3 escape)

    public function testCorrelationHeadersExact55AndStateBound(): void
    {
        $tp = '00-' . $this->tid() . '-' . $this->sid() . '-01';
        self::assertSame(55, strlen($tp));
        $h = new CorrelationHeaders($tp, null);
        self::assertSame(55, $h->encodedBytes());
        $h2 = new CorrelationHeaders($tp, str_repeat('x', 512));
        self::assertSame(567, $h2->encodedBytes());
        foreach ([[substr($tp, 0, 54), null], [$tp . '0', null], [$tp, str_repeat('x', 513)]] as $bad) {
            try {
                new CorrelationHeaders(...$bad);
                self::fail('CorrelationHeaders invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------- HealthCheckResult etc.

    public function testHealthCheckMessageBoundAndStatus(): void
    {
        self::assertSame('up', HealthCheckResult::up()->status());
        self::assertSame('down', HealthCheckResult::down()->status());
        self::assertSame('down', HealthCheckResult::down('x')->status());
        self::assertTrue(HealthCheckResult::up()->healthy);
        self::assertFalse(HealthCheckResult::down()->healthy);
        new HealthCheckResult(true, str_repeat('m', 512));

        try {
            new HealthCheckResult(true, str_repeat('m', 513));
            self::fail('Pesan 513 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testDomainExceptionsCarryStructuredMessages(): void
    {
        $prev = new \RuntimeException('root');
        $sre = new ServiceResolutionException('db.conn', 'factory gagal', $prev);
        self::assertSame("Cannot resolve service 'db.conn': factory gagal", $sre->getMessage());
        self::assertSame(0, $sre->getCode());
        self::assertSame($prev, $sre->getPrevious());

        $cir = new CircularAliasException(['a', 'b', 'c']);
        self::assertSame('Circular alias detected: a -> b -> c', $cir->getMessage());
        self::assertSame(['a', 'b', 'c'], $cir->chain);

        $mna = new MethodNotAllowedException('PUT', '/users/1', ['GET', 'POST']);
        self::assertSame("Method 'PUT' is not allowed for /users/1.", $mna->getMessage());
        self::assertSame(['GET', 'POST'], $mna->allowedMethods);

        $rnf = new RouteNotFoundException('DELETE', '/x/y');
        self::assertSame('No route matched [DELETE] /x/y.', $rnf->getMessage());
        self::assertSame('DELETE', $rnf->method);
    }

    // ------------------------------------------------------------ helpers

    /** Trace ID heksa lowercase 32 char valid (tidak semua nol). */
    private function tid(): string
    {
        return '4bf92f3577b34da6a3ce929d0e0e4736';
    }

    /** Span ID heksa lowercase 16 char valid. */
    private function sid(): string
    {
        return '00f067aa0ba902b7';
    }

    /** Waktu UTC deterministik: gmmktime bertipe int|false -> int. */
    private function ts(int ...$args): int
    {
        return (int) gmmktime(...$args);
    }

    /** @param array<string, null|bool|float|int|string> $attributes */
    private function validContext(array $attributes = [], ?string $traceState = null, ?string $idem = null): CorrelationContext
    {
        return new CorrelationContext($this->tid(), $this->sid(), '01', $traceState, 'op-1', $idem, $attributes);
    }

    /** @param array<string, null|string> $pairs @param callable(): mixed $fn */
    private function withEnv(array $pairs, callable $fn): void
    {
        $previous = [];
        foreach ($pairs as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($value === null ? $name : $name . '=' . $value);
        }

        try {
            $fn();
        } finally {
            foreach ($previous as $name => $old) {
                putenv($old === false ? $name : $name . '=' . $old);
            }
        }
    }
}
