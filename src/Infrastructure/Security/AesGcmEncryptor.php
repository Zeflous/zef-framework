<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * AES-256-GCM encryptor (PHP openssl, bundled core extension).
 *
 * Payload format: "zefenc1.<b64url iv>.<b64url tag>.<b64url ciphertext>"
 * - 96-bit random IV per message (CSPRN: random_bytes)
 * - 128-bit authentication tag verified on decrypt
 * - version prefix "zefenc1" so future algorithms/keys rotate cleanly
 *
 * Keys: exactly 32 bytes. Accepts raw, hex (64 chars), or base64 strings;
 * anything else is rejected at construction — no silent hashing of weak keys.
 * The DECODED 32 bytes are the key material openssl actually uses, so every
 * representation of the same key (raw / hex / base64) encrypts and decrypts
 * identically (ZEF-DEEP-01: previously the raw string was used as-is, silently
 * truncating hex/base64 keys to their ASCII prefix — 128-bit effective entropy).
 */
final readonly class AesGcmEncryptor implements EncryptionInterface
{
    private const string CIPHER = 'aes-256-gcm';
    private const string VERSION = 'zefenc1';
    private const int IV_BYTES = 12;
    private const int TAG_BYTES = 16;

    private string $key;

    public function __construct(string $key)
    {
        $decoded = $this->decodeKey($key);
        if (strlen($decoded) !== 32) {
            throw new \InvalidArgumentException('Encryption key must decode to exactly 32 bytes (AES-256), got ' . strlen($decoded) . '.');
        }
        $this->key = $decoded;
    }

    #[\Override]
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );
        if ($cipherText === false || $tag === '') {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return self::VERSION
            . '.' . $this->b64url($iv)
            . '.' . $this->b64url($tag)
            . '.' . $this->b64url($cipherText);
    }

    #[\Override]
    public function decrypt(string $payload): string
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Malformed encryption payload (unknown format or version).');
        }
        $iv = $this->b64urlDecode($parts[1]);
        $tag = $this->b64urlDecode($parts[2]);
        $cipherText = $this->b64urlDecode($parts[3]);
        if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Malformed encryption payload (IV/tag length).');
        }
        $plain = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed (tampered data or wrong key).');
        }

        return $plain;
    }

    private function decodeKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        if (preg_match('/^[0-9a-f]{64}$/i', $key) === 1) {
            $raw = hex2bin($key);

            return $raw === false ? $key : $raw;
        }
        // base64 (standard or url-safe) attempt: only when it round-trips.
        if (preg_match('/^[A-Za-z0-9+\/=_-]{43,44}$/', $key) === 1) {
            $raw = base64_decode(strtr($key, '-_', '+/'), true);
            if ($raw !== false) {
                return $raw;
            }
        }

        return $key; // raw bytes
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string
    {
        $raw = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $raw === false ? '' : $raw;
    }
}
