<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-01 (issue #155):
 * AesGcmEncryptor must use the DECODED key material, so every representation
 * of the same 32-byte key (raw / hex / base64) interoperates.
 *
 * Before the fix, the constructor validated the decoded length but kept the
 * ORIGINAL string as key material: openssl_encrypt()/openssl_decrypt() used
 * the ASCII prefix of hex/base64 keys (128-bit effective entropy for hex),
 * and representations of the same key produced divergent ciphertexts.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\AesGcmEncryptor;

/**
 * @internal
 */
final class AesGcmKeyInteropTest extends TestCase
{
    public function testHexRawAndBase64RepresentationsInteropBothWays(): void
    {
        $raw = random_bytes(32);
        $hex = bin2hex($raw);
        $b64 = base64_encode($raw);

        $byRaw = new AesGcmEncryptor($raw);
        $byHex = new AesGcmEncryptor($hex);
        $byB64 = new AesGcmEncryptor($b64);

        // Every representation can decrypt what every other produced.
        foreach ([$byRaw, $byHex, $byB64] as $producer) {
            $payload = $producer->encrypt('same-key-material');
            foreach ([$byRaw, $byHex, $byB64] as $consumer) {
                self::assertSame(
                    'same-key-material',
                    $consumer->decrypt($payload),
                    'A key representation must decrypt payloads made by any other representation of the same 32 bytes.',
                );
            }
        }
    }

    /**
     * Pins the exact defect: a hex key must NOT behave as its ASCII prefix.
     *
     * Old behaviour fed the 64-char hex string to openssl, which uses only
     * the first 32 ASCII bytes — so `substr($hex, 0, 32)` as a RAW key was
     * effectively the same key. After the fix the decoded bytes are used and
     * the prefix raw key must NOT decrypt hex-key payloads.
     */
    public function testHexKeyDoesNotActAsItsAsciiPrefix(): void
    {
        $hex = bin2hex(random_bytes(32));
        $asciiPrefixRaw = substr($hex, 0, 32); // exactly 32 raw bytes -> valid raw key

        $byHex = new AesGcmEncryptor($hex);
        $byPrefix = new AesGcmEncryptor($asciiPrefixRaw);

        // Sanity: the two input strings really are different material.
        self::assertNotSame($hex, $asciiPrefixRaw);

        $payload = $byHex->encrypt('prefix-must-not-match');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $byPrefix->decrypt($payload);
    }

    /** Same pin for the base64 representation (ASCII prefix of the b64 text). */
    public function testBase64KeyDoesNotActAsItsAsciiPrefix(): void
    {
        $raw = random_bytes(32);
        $b64 = base64_encode($raw);
        $asciiPrefixRaw = substr($b64, 0, 32);

        // Guard the fixture itself: prefix must be a *different* valid raw key.
        self::assertNotSame($raw, $asciiPrefixRaw);

        $byB64 = new AesGcmEncryptor($b64);
        $byPrefix = new AesGcmEncryptor($asciiPrefixRaw);

        $payload = $byB64->encrypt('prefix-must-not-match');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $byPrefix->decrypt($payload);
    }

    /** Raw 32-byte keys keep full round-trip + distinct-key rejection semantics. */
    public function testRawKeyRoundTripAndWrongKeyStillRejected(): void
    {
        $key = random_bytes(32);
        $encryptor = new AesGcmEncryptor($key);

        $payload = $encryptor->encrypt('round-trip');
        self::assertSame('round-trip', $encryptor->decrypt($payload));

        $wrong = new AesGcmEncryptor(random_bytes(32));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $wrong->decrypt($payload);
    }

    /**
     * Cross-representation key rotation: re-encrypting the same plaintext
     * under a different representation of the same key yields a payload the
     * original representation can still read (migration hex -> base64 -> raw).
     */
    public function testKeyRepresentationMigrationIsLossless(): void
    {
        $material = random_bytes(32);
        $legacyHex = new AesGcmEncryptor(bin2hex($material));
        $migrated = new AesGcmEncryptor($material);

        $legacyPayload = $legacyHex->encrypt('migrating-representation');
        $reEncrypted = $migrated->encrypt($migrated->decrypt($legacyPayload));

        self::assertSame('migrating-representation', $legacyHex->decrypt($reEncrypted));
    }
}
