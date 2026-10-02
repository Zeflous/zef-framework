<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Cache;

final class DefaultCacheKeyNormalizer implements CacheKeyNormalizerInterface
{
    #[\Override]
    public function normalize(string $key): string
    {
        // Audit #327: reject instead of trim(). Trimming silently aliased
        // 'user:42' and ' user:42 ' (tabs/newlines included) onto ONE store
        // entry, and it also stripped the NUL marker off TaggableCache's
        // internal index keys — letting a plain user key like 'zef-tag:x'
        // collide with the tag index. Framework-reserved routing keys keep
        // their NUL marker verbatim instead (user keys can never produce
        // them: the grammar below rejects a NUL anywhere).
        if (str_starts_with($key, "\0zef-tag:") || str_starts_with($key, "\0zef-keytags:")) {
            return $key;
        }
        if ($key === '' || strlen($key) > 250 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid cache key.');
        }

        return $key;
    }
}
