<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound security adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * Key-rotating EncryptionInterface decorator over AesGcmEncryptor.
 *
 * - encrypt() always uses the ACTIVE key (index 0 by default).
 * - decrypt() tries every key in ring order and returns the first successful
 *   plaintext — the zefenc1 payload format does not carry a key id, so this
 *   sequential probe is what makes rotation transparent for data written by
 *   retired keys. Keep rings small (each alien payload costs one failed GCM
 *   verification per non-matching key).
 * - All keys are validated up front (identical rules as AesGcmEncryptor:
 *   raw / hex / base64 decoding to exactly 32 bytes).
 */
final class RotatingKeyRing implements EncryptionInterface
{
    public const int MAX_KEYS = 16;

    /**
     * @var list<string> original key material (for lazy encryptor creation)
     */
    private readonly array $materials;

    /**
     * @var array<int,AesGcmEncryptor>
     */
    private array $encryptors = [];
    private readonly int $keyCount;

    /**
     * @param array<int|string,string> $keys key material ordered from NEWEST
     *        to OLDEST. Keyed (non-list) arrays are legitimate PHP input —
     *        config maps, decoded JSON — and are normalised to list order
     *        up front, so the active-index lookup can never desync from the
     *        array's actual keys (ZEF-DEEP-09).
     */
    public function __construct(
        array $keys,
        private readonly int $activeIndex = 0,
    ) {
        $keys = array_values($keys);
        if (count($keys) < 1) { // not empty(): phpstan-strict-rules forbids it
            throw new \InvalidArgumentException('RotatingKeyRing requires at least one key.');
        }
        if (count($keys) > self::MAX_KEYS) {
            throw new \InvalidArgumentException('RotatingKeyRing supports at most ' . self::MAX_KEYS . ' keys.');
        }
        if ($this->activeIndex < 0 || $this->activeIndex >= count($keys)) {
            throw new \InvalidArgumentException('Active key index must be between 0 and ' . (count($keys) - 1) . '.');
        }
        // Validate every key up front by materialising each encryptor once.
        foreach ($keys as $index => $material) {
            $this->encryptors[$index] = new AesGcmEncryptor($material);
        }
        $this->keyCount = count($keys);
        $this->materials = $keys;
    }

    public function keyCount(): int
    {
        return $this->keyCount;
    }

    public function activeIndex(): int
    {
        return $this->activeIndex;
    }

    /** Immutable re-keying helper: returns a ring with another active index. */
    public function withActiveIndex(int $index): self
    {
        return new self($this->materials, $index);
    }

    #[\Override]
    public function encrypt(string $plaintext): string
    {
        return $this->encryptors[$this->activeIndex]->encrypt($plaintext);
    }

    #[\Override]
    public function decrypt(string $payload): string
    {
        $lastError = null;
        for ($index = 0; $index < $this->keyCount; ++$index) {
            try {
                return $this->encryptors[$index]->decrypt($payload);
            } catch (\RuntimeException $e) {
                $lastError = $e;
            }
        }

        throw new DecryptionException(
            'Decryption failed with any of the ' . $this->keyCount . ' ring key(s).'
            . ($lastError instanceof \RuntimeException ? ' Last error: ' . $lastError->getMessage() : ''),
            previous: $lastError,
        );
    }
}
