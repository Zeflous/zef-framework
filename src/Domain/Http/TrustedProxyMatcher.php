<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Adapters -> Domain with the same FQN
 * and namespace (classmap + PSR-4 multi-directory both resolve it), so
 * every consumer — the Application client-address resolver and the
 * Adapters request factory — is untouched. The class is a pure static
 * CIDR-matching helper over string inputs: no state, no I/O, no
 * outbound coupling, so it satisfies the hexagonal rule the
 * TrustedProxy carve-out used to bypass.
 */

namespace Zef\Framework\Http;

/**
 * Shared CIDR-matching logic extracted from RequestFactory and
 * ClientAddressResolver to eliminate duplication.
 */
final class TrustedProxyMatcher
{
    /** @param list<string> $trusted */
    public static function matches(string $ip, array $trusted): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($trusted as $entry) {
            if (self::entryMatchesIp(trim((string) $entry), $ip)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $network, int $prefix): bool
    {
        $ipBin = self::binaryAddress($ip);
        $networkBin = self::binaryAddress($network);
        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }
        $maxPrefix = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        return self::maskedPrefixMatches($ipBin, $networkBin, $prefix);
    }

    /**
     * One trimmed trusted-list entry against a validated IP: exact string
     * match or CIDR containment.
     */
    private static function entryMatchesIp(string $entry, string $ip): bool
    {
        if ($entry === '') {
            return false;
        }
        if ($entry === $ip) {
            return true;
        }

        return self::cidrEntryMatches($entry, $ip);
    }

    private static function cidrEntryMatches(string $entry, string $ip): bool
    {
        if (!str_contains($entry, '/')) {
            return false;
        }
        [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        if ($prefix === null || !ctype_digit($prefix)) {
            return false;
        }

        return self::ipInCidr($ip, (string) $network, (int) $prefix);
    }

    /**
     * inet_pton() with the invalid-input warning guarded away: an address
     * that fails FILTER_VALIDATE_IP can only make inet_pton() warn and
     * return false, so the guard keeps soft-false semantics quiet.
     */
    private static function binaryAddress(string $address): false|string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return inet_pton($address);
    }

    private static function maskedPrefixMatches(string $ipBin, string $networkBin, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }
        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }
}
