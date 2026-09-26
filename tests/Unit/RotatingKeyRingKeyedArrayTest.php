<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-09 (issue #163):
 * RotatingKeyRing must accept keyed (non-list) key arrays.
 *
 * Pre-fix, keys like [5 => $k1, 9 => $k2] passed the count-only validation,
 * the encryptor map was built under those literal keys, and encrypt() then
 * looked up encryptors[0] — a NULL — producing "Error: Call to a member
 * function encrypt() on null", an uncatchable-by-contract fatal that escaped
 * the RuntimeException-based failure envelope.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\RotatingKeyRing;

/**
 * @internal
 */
final class RotatingKeyRingKeyedArrayTest extends TestCase
{
    private const string K32A = 'a4e9f0c1d2b3a496f8e7d6c5b4a39281d0f1e2c3b4a5968778a9b0c1d2e3f405';
    private const string K32B = 'b1c2d3e4f5061728394a5b6c7d8e9f00a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** The audit PoC: a keyed array must work end to end — no fatal, real rotation semantics. */
    public function testKeyedKeyArrayEncryptsAndRotates(): void
    {
        $ring = new RotatingKeyRing([5 => self::K32A, 9 => self::K32B]);

        self::assertSame(2, $ring->keyCount());
        self::assertSame(0, $ring->activeIndex(), 'the active index refers to LIST position 0, not the original array key');

        $payload = $ring->encrypt('rotation-payload');
        self::assertSame('rotation-payload', $ring->decrypt($payload), 'round-trip through the normalised ring');
    }

    /** withActiveIndex() on a keyed-built ring addresses list positions. */
    public function testKeyedArrayWithActiveIndexTargetsListPosition(): void
    {
        $ring = new RotatingKeyRing([5 => self::K32A, 9 => self::K32B], 1);

        self::assertSame(1, $ring->activeIndex());
        $payload = $ring->encrypt('second-slot');
        // Decrypt via a ring whose active key is the OTHER one — proves the
        // payload really was produced by list-position 1's material.
        $flipped = new RotatingKeyRing([self::K32B, self::K32A]);
        self::assertSame('second-slot', $flipped->decrypt($payload), 'index 1 of the keyed array is list-position material K32B');
    }

    /** Sparse / descending integer keys are normalised by VALUE order too. */
    public function testSparseDescendingKeysNormaliseToValueOrder(): void
    {
        $ring = new RotatingKeyRing([9 => self::K32A, 5 => self::K32B]);

        $payload = $ring->encrypt('order-check');
        $byList = new RotatingKeyRing([self::K32A, self::K32B]);
        self::assertSame('order-check', $byList->decrypt($payload), 'value insertion order defines the ring, not the numeric keys');
    }

    /** Non-zero active index bounds still validate against the NORMALISED count. */
    public function testActiveIndexBoundsUseNormalisedPositions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Active key index must be between 0 and 1.');
        new RotatingKeyRing([5 => self::K32A, 9 => self::K32B], 2);
    }

    /** The pre-fix failure mode: encryption must never fatal on a keyed ring. */
    public function testKeyedRingNeverProducesNullEncryptorError(): void
    {
        $ring = new RotatingKeyRing([42 => self::K32A]);

        // Pre-fix this exact line threw "Error: Call to a member function
        // encrypt() on null" because encryptors[0] did not exist.
        $payload = $ring->encrypt('no-fatal');
        self::assertSame('no-fatal', $ring->decrypt($payload));
    }
}
