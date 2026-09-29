<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Http\TrustedProxyMatcher;

final class ClientAddressResolver
{
    private const string UNKNOWN_ADDRESS = '0.0.0.0';

    /**
     * beta2 fix: rightmost-untrusted XFF algorithm.
     *
     * @param list<string> $trustedProxies
     */
    public static function resolve(ServerRequestInterface $request, array $trustedProxies = []): string
    {
        $remoteValue = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remote = is_string($remoteValue) ? trim($remoteValue) : '';
        if ($remote === '' || !TrustedProxyMatcher::matches($remote, $trustedProxies)) {
            return self::sanitize($remote !== '' ? $remote : self::UNKNOWN_ADDRESS);
        }
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        $candidate = self::rightmostUntrustedForwardedFor($forwarded, $trustedProxies);
        if ($candidate !== null) {
            return self::sanitize($candidate);
        }

        return self::sanitize($remote !== '' ? $remote : self::UNKNOWN_ADDRESS);
    }

    /**
     * Rightmost X-Forwarded-For entry that is a valid untrusted address;
     * null when the chain is unusable (malformed entry or all trusted).
     *
     * @param list<string> $trustedProxies
     */
    private static function rightmostUntrustedForwardedFor(string $forwarded, array $trustedProxies): ?string
    {
        if ($forwarded === '') {
            return null;
        }
        $candidates = array_map(trim(...), explode(',', $forwarded));
        $result = null;
        for ($i = count($candidates) - 1; $i >= 0; --$i) {
            $candidate = $candidates[$i];
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                break; // malformed entry: the chain is unusable
            }
            if (!TrustedProxyMatcher::matches($candidate, $trustedProxies)) {
                $result = $candidate;

                break;
            }
        }

        return $result;
    }

    private static function sanitize(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : self::UNKNOWN_ADDRESS;
    }
}
