<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * Opaque, tamper-evident pagination cursor.
 *
 * Wire format: base64url("v1.<offset>.<checksum>") where checksum is the
 * lower 32 bits of crc32(offset . salt). The salt must be stable per
 * deployment so cursors stay valid across restarts; rotate it to invalidate
 * outstanding cursors. Clients cannot forge offsets without the salt, and
 * malformed/tampered cursors decode to a rejected cursor — never a crash.
 */
final readonly class Cursor
{
    private const string VERSION = 'v1';
    private const int MAX_OFFSET = PHP_INT_MAX >> 2;

    public function __construct(
        public int $offset,
    ) {
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Cursor offset must be >= 0.');
        }
    }

    public function encode(string $salt = ''): string
    {
        if (strlen($salt) > 256) {
            throw new \InvalidArgumentException('Cursor salt must be <= 256 bytes.');
        }
        $payload = self::VERSION . '.' . $this->offset . '.' . self::checksum($this->offset, $salt);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * @return self the decoded cursor
     *
     * @throws \InvalidArgumentException when the cursor is malformed,
     *                                   tampered, or salted differently
     */
    public static function decode(string $cursor, string $salt = ''): self
    {
        $cursor = trim($cursor);
        if ($cursor === '' || strlen($cursor) > 128) {
            throw new \InvalidArgumentException('Invalid cursor format.');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) > 96) {
            throw new \InvalidArgumentException('Invalid cursor encoding.');
        }
        $parts = explode('.', $decoded);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported cursor version.');
        }
        $offset = filter_var(
            $parts[1],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => self::MAX_OFFSET]],
        );
        if ($offset === false) {
            throw new \InvalidArgumentException('Invalid cursor offset.');
        }
        if (!ctype_digit($parts[2]) || $parts[2] !== self::checksum($offset, $salt)) {
            throw new \InvalidArgumentException('Cursor checksum mismatch (tampered or stale salt).');
        }

        return new self($offset);
    }

    private static function checksum(int $offset, string $salt): string
    {
        return (string) (crc32($offset . ':' . $salt) & 0x7FFFFFFF);
    }
}
