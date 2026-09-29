<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 * v2.31.0 — CSRF token TTL (audit I-4): tokens can now carry an HMAC-bound
 * issuance timestamp and expire after a configurable window (0 = no expiry,
 * the legacy unbounded behaviour). A leaked token is then only replayable
 * within the TTL window instead of forever.
 */

namespace Zef\Framework\Security;

final readonly class CsrfTokenManager
{
    /** @var (\Closure(): int) */
    private \Closure $clock;

    /**
     * @param null|(\Closure(): int) $clock
     */
    public function __construct(
        private string $secret,
        private int $tokenBytes = 32,
        private int $ttlSeconds = 0,
        ?\Closure $clock = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes.');
        }
        if ($tokenBytes < 16) {
            throw new \InvalidArgumentException('tokenBytes must be >= 16.');
        }
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException('ttlSeconds must be >= 0 (0 = no expiry).');
        }
        $this->clock = $clock ?? time(...);
    }

    /**
     * Issue a CSRF token. With TTL enabled the wire format gains an
     * HMAC-bound issuance timestamp: `<issuedAt>.<token>.<mac>`; without TTL
     * the legacy `<token>.<mac>` format is preserved.
     */
    public function issue(): string
    {
        // Constructor already validates tokenBytes >= 16; no re-clamp needed.
        $token = rtrim(strtr(base64_encode(random_bytes($this->tokenBytes)), '+/', '-_'), '=');
        $issuedAt = '';
        if ($this->ttlSeconds > 0) {
            $issuedAt = (string) ($this->clock)();
        }
        // BC: the legacy (no-TTL) MAC input stays exactly `$token`, so tokens
        // issued before v2.31.0 keep validating until re-issued.
        $mac = hash_hmac('sha256', $issuedAt === '' ? $token : $issuedAt . '|' . $token, $this->secret);

        return ($issuedAt !== '' ? $issuedAt . '.' : '') . $token . '.' . $mac;
    }

    /**
     * Validate a token. TTL-enabled verification requires the 3-part format
     * (and rejects expired or future-dated stamps); the legacy 2-part format
     * is accepted exactly as before when no TTL is configured.
     */
    public function isValid(string $token): bool
    {
        return $this->ttlSeconds > 0
            ? $this->isValidWithinTtlWindow($token)
            : $this->isValidLegacy($token);
    }

    private function isValidWithinTtlWindow(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$issuedAtRaw, $value, $signature] = $parts;
        $shapeOk = $this->hasWellFormedBody($value, $signature)
            && preg_match('/^\d{1,12}$/', $issuedAtRaw) === 1;
        if (!$shapeOk) {
            return false;
        }
        $now = ($this->clock)();
        $issuedAt = (int) $issuedAtRaw;
        $inWindow = $issuedAt <= $now && $now - $issuedAt <= $this->ttlSeconds;
        $expected = hash_hmac('sha256', $issuedAtRaw . '|' . $value, $this->secret);

        return $inWindow && hash_equals($expected, $signature);
    }

    private function isValidLegacy(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$value, $signature] = $parts;
        if (!$this->hasWellFormedBody($value, $signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $value, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Shared shape check: non-empty base64url token body + 64-char lowercase
     * hex MAC. Both are produced by ASCII-only encoders (base64_encode /
     * hash_hmac), so the explicit ASCII classes are the contract.
     */
    private function hasWellFormedBody(string $value, string $signature): bool
    {
        if ($value === '' || $signature === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1
            && preg_match('/^[a-f0-9]{64}$/', $signature) === 1;
    }
}
