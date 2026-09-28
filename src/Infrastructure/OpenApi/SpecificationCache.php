<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

use Zef\Framework\Cache\CacheInterface;

/**
 * Caches assembled specification documents behind the v2.9.0 cache port.
 * A missing/mis-shaped cache entry is treated as a miss (never a failure),
 * so a polluted cache cannot break documentation endpoints.
 *
 * The cache key is not the version alone (P-22, issue #172): a deploy that
 * changes routes would keep serving the stale document until TTL expiry,
 * and several applications sharing one cache backend would collide on the
 * same version. Pass the constructor `$fingerprint` — derived by the wiring
 * site from whatever identifies the route table (e.g. a SHA-256 of the
 * route table, or an application id) — to scope the keys. The default ''
 * keeps the legacy `zef.openapi.spec.{version}` layout so existing
 * deployments are unaffected until they opt in.
 */
final readonly class SpecificationCache
{
    private const string KEY_PREFIX = 'zef.openapi.spec.';
    private const int DEFAULT_TTL = 3600;

    public function __construct(
        private CacheInterface $cache,
        private int $ttlSeconds = self::DEFAULT_TTL,
        private string $fingerprint = '',
    ) {}

    /**
     * @return null|array<string, mixed>
     */
    public function get(string $version): ?array
    {
        $entry = $this->cache->get($this->key($version));
        if (!is_array($entry)) {
            return null;
        }

        // @phpstan-ignore return.type (cache payload shape is the caller's contract)
        return $entry;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function set(string $version, array $spec, ?int $ttlSeconds = null): void
    {
        $this->cache->set($this->key($version), $spec, $ttlSeconds ?? $this->ttlSeconds);
    }

    public function invalidate(string $version): void
    {
        $this->cache->delete($this->key($version));
    }

    /**
     * Version-scoped key, folded with the constructor fingerprint when one
     * is configured (hashed so any fingerprint encoding — dotted app ids,
     * raw route-table digests — maps to one opaque, collision-free key
     * segment). Empty fingerprint keeps the legacy layout for BC.
     */
    private function key(string $version): string
    {
        if ($this->fingerprint === '') {
            return self::KEY_PREFIX . $version;
        }

        return self::KEY_PREFIX . $version . '.' . hash('sha256', $this->fingerprint);
    }
}
