<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * v2.22.1 — issue #65 item 2: UoW retry policy value object.
 *
 * Covers validation, backoff shape, and the transient-failure taxonomy
 * (retryable class names + SQLSTATE codes).
 *
 * @internal
 */
final class DatabaseUnitOfWorkRetryPolicyTest extends TestCase
{
    public function testDefaultConstructsSanely(): void
    {
        $p = new UnitOfWorkRetryPolicy();

        self::assertSame(3, $p->maxAttempts);
        self::assertSame(100, $p->initialDelayMs);
        self::assertSame(30_000, $p->maxDelayMs);
        self::assertSame(2.0, $p->multiplier);
        self::assertSame(0, $p->jitterMs);
        self::assertContains('PDOException', $p->retryableClassNames);
        self::assertContains('40001', $p->retryableSqlStates);
        self::assertContains('40P01', $p->retryableSqlStates);
    }

    public function testInvalidMaxAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnitOfWorkRetryPolicy(maxAttempts: 0);
    }

    public function testInvalidInitialDelayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnitOfWorkRetryPolicy(initialDelayMs: -1);
    }

    public function testInvalidMaxDelayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnitOfWorkRetryPolicy(initialDelayMs: 100, maxDelayMs: 50);
    }

    public function testInvalidMultiplierThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnitOfWorkRetryPolicy(multiplier: 0.5);
    }

    public function testInvalidJitterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnitOfWorkRetryPolicy(jitterMs: -10);
    }

    public function testShouldRetryUntilExhausted(): void
    {
        $p = new UnitOfWorkRetryPolicy(maxAttempts: 3);
        self::assertTrue($p->shouldRetry(1), '1st attempt → retry possible');
        self::assertTrue($p->shouldRetry(2), '2nd attempt → retry possible');
        self::assertFalse($p->shouldRetry(3), '3rd attempt = maxAttempts → no retry');
        self::assertFalse($p->shouldRetry(99));
    }

    public function testDelayMsGrowsExponentiallyAndCapsAtMax(): void
    {
        $p = new UnitOfWorkRetryPolicy(
            maxAttempts: 5,
            initialDelayMs: 100,
            maxDelayMs: 1_000,
            multiplier: 2.0,
            jitterMs: 0,
        );
        self::assertSame(100, $p->delayMs(1));
        self::assertSame(200, $p->delayMs(2));
        self::assertSame(400, $p->delayMs(3));
        self::assertSame(800, $p->delayMs(4));
        self::assertSame(1_000, $p->delayMs(5), 'capped at maxDelayMs');
    }

    public function testDelayMsRejectsNonPositiveAttempt(): void
    {
        $p = new UnitOfWorkRetryPolicy();
        $this->expectException(\InvalidArgumentException::class);
        $p->delayMs(0);
    }

    /**
     * Regresi P-18 (issue #172): attempt besar membuat multiplier**n
     * overflow ke INF — (int) round(INF) === 0 — sehingga backoff kolaps
     * ke 0ms dan caller meng-hammer transaksi gagal tanpa jeda (kebalikan
     * dari tujuan policy). Delay wajib saturasi di maxDelayMs.
     */
    public function testDelayMsSaturatesAtMaxWhenExponentOverflows(): void
    {
        $p = new UnitOfWorkRetryPolicy(maxAttempts: 3000, initialDelayMs: 100, maxDelayMs: 30_000, multiplier: 2.0);

        self::assertSame(30_000, $p->delayMs(2000), 'attempt 2000: 2**1999 = INF — wajib saturasi di cap, bukan 0ms');
        self::assertSame(30_000, $p->delayMs(PHP_INT_MAX), 'attempt ekstrem tetap saturasi di cap (tidak 0ms / negatif)');
        // Juga guard jalur finite-tapi-raksasa: 100 * (2.0 ** 60) jauh di atas
        // cap namun masih finite — hasil tetap cap, bukan int-cast negatif.
        self::assertSame(30_000, $p->delayMs(61), 'produk finite raksasa juga wajib saturasi di cap');
    }

    public function testIsRetryableMatchesClassAndSqlState(): void
    {
        $p = new UnitOfWorkRetryPolicy();
        $retryable = $this->makePdoException('deadlock', '40P01');
        self::assertTrue($p->isRetryable($retryable));
    }

    public function testIsRetryableRejectsNonRetryableSqlState(): void
    {
        $p = new UnitOfWorkRetryPolicy();
        $syntaxError = $this->makePdoException('syntax error', '42601');
        self::assertFalse($p->isRetryable($syntaxError));
    }

    /**
     * Regression: MySQL surfaces transient failures as INT driver codes
     * (getCode() = 1213, errorInfo[1] = 1213) while the policy stores the
     * taxonomy as SQLSTATE strings — the strict comparison only matches
     * after the candidates are normalised to strings. Before that
     * normalisation, MySQL deadlocks silently never retried.
     */
    public function testIsRetryableMatchesMysqlIntegerDriverCode(): void
    {
        $p = new UnitOfWorkRetryPolicy();
        $deadlock = new class('Deadlock found when trying to get lock', 1213) extends \PDOException {
            public function __construct(string $message, int $driverCode)
            {
                parent::__construct($message, $driverCode);
                $this->errorInfo = ['HY000', $driverCode, $message];
            }
        };
        self::assertTrue($p->isRetryable($deadlock));
    }

    public function testIsRetryableRejectsUnknownClass(): void
    {
        $p = new UnitOfWorkRetryPolicy();
        $logic = new \LogicException('boom');
        self::assertFalse($p->isRetryable($logic));
    }

    public function testIsRetryableWhenSqlStatesListEmptyMatchesClassOnly(): void
    {
        $p = new UnitOfWorkRetryPolicy(retryableSqlStates: []);
        $any = $this->makePdoException('whatever', 'XX999');
        self::assertTrue($p->isRetryable($any));
    }

    public function testCustomRetryableClassNames(): void
    {
        $p = new UnitOfWorkRetryPolicy(
            retryableClassNames: [\RuntimeException::class],
            retryableSqlStates: [],
        );
        self::assertTrue($p->isRetryable(new \RuntimeException('retry me')));
        self::assertFalse($p->isRetryable(new \LogicException('not me')));
    }

    /**
     * Build a testable PDOException with a SQLSTATE string. Real
     * PDOExceptions are populated by PHP internals at runtime; the public
     * constructor only accepts an int $code, so we extend the class and
     * populate errorInfo directly.
     */
    private function makePdoException(string $message, string $sqlState): \PDOException
    {
        return new class($message, $sqlState) extends \PDOException {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message, 0);
                $this->errorInfo = [$sqlState, 0, $message];
            }
        };
    }
}
