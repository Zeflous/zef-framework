<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Cache;

/**
 * Cache decorator adding tag-based invalidation.
 *
 * The tag index lives in the wrapped store under reserved keys that no
 * userland key may use. `setWithTags()` writes the value and appends the key
 * to each tag's member list; `invalidateTag()` deletes every member and the
 * index itself. Plain CacheInterface passthrough methods work unchanged.
 */
final readonly class TaggableCache implements CacheInterface, TtlAwareCacheInterface
{
    private const string RESERVED_PREFIX = "\0zef-tag:";
    private const string REVERSE_PREFIX = "\0zef-keytags:";
    private const int MAX_TAGS_PER_KEY = 16;

    public function __construct(
        private CacheInterface $inner,
    ) {}

    /**
     * Store a value indexed under one or more tags for later invalidation.
     *
     * @param list<string> $tags
     */
    public function setWithTags(string $key, mixed $value, ?int $ttlSeconds, array $tags): void
    {
        $key = $this->assertUserKey($key);
        $tags = array_values(array_unique($tags));
        if (count($tags) > self::MAX_TAGS_PER_KEY) {
            throw new \InvalidArgumentException('Too many tags for a single key (max ' . self::MAX_TAGS_PER_KEY . ').');
        }
        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $tag) !== 1) {
                throw new \InvalidArgumentException("Invalid cache tag '{$tag}'.");
            }
            $normalized[] = $tag;
        }
        $this->inner->set($key, $value, $ttlSeconds);
        foreach ($normalized as $tag) {
            $members = $this->readTagMembers($tag);
            if (!in_array($key, $members, true)) {
                $members[] = $key;
                $this->writeTagMembers($tag, $members);
            }
        }
        $this->writeKeyTags($key, $normalized);
    }

    /** Delete every key registered under the tag. @return int number of keys deleted */
    public function invalidateTag(string $tag): int
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $tag) !== 1) {
            throw new \InvalidArgumentException("Invalid cache tag '{$tag}'.");
        }
        $members = $this->readTagMembers($tag);
        $deleted = 0;
        foreach ($members as $key) {
            if ($this->inner->has($key)) {
                $this->inner->delete($key);
                ++$deleted;
            }
            $this->writeKeyTags($key, []);
        }
        $this->inner->delete(self::RESERVED_PREFIX . $tag);

        return $deleted;
    }

    /**
     * Tags currently indexing $key (based on the last setWithTags call for
     * that key). Values overwritten via plain set() keep their old tag
     * registration until the tag is invalidated or the key expires.
     *
     * @return list<string>
     */
    public function tagsFor(string $key): array
    {
        $key = $this->assertUserKey($key);
        $raw = $this->inner->get(self::REVERSE_PREFIX . $key);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, is_string(...)));
            }
        }

        return [];
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->assertUserKey($key), $default);
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->inner->set($this->assertUserKey($key), $value, $ttlSeconds);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->inner->delete($this->assertUserKey($key));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->inner->has($this->assertUserKey($key));
    }

    /**
     * Remaining-TTL introspection is forwarded to the wrapped cache when it
     * supports it (ZEF-DEEP-14); null otherwise — callers treat null as
     * "no deadline", which keeps an untaggable inner cache opaque rather
     * than silently under-promoting.
     */
    #[\Override]
    public function getRemainingTtlSeconds(string $key): ?int
    {
        if (!$this->inner instanceof TtlAwareCacheInterface) {
            return null;
        }

        return $this->inner->getRemainingTtlSeconds($this->assertUserKey($key));
    }

    #[\Override]
    public function clear(): void
    {
        $this->inner->clear();
    }

    /** @return list<string> */
    private function readTagMembers(string $tag): array
    {
        $raw = $this->inner->get(self::RESERVED_PREFIX . $tag);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, is_string(...)));
            }
        }

        return [];
    }

    private function writeTagMembers(string $tag, array $members): void
    {
        // Tag indexes live as long as the store; only the members expire.
        $this->inner->set(
            self::RESERVED_PREFIX . $tag,
            json_encode(array_values($members), JSON_THROW_ON_ERROR),
        );
    }

    private function writeKeyTags(string $key, array $tags): void
    {
        $reverseKey = self::REVERSE_PREFIX . $key;
        if ($tags === []) {
            $this->inner->delete($reverseKey);

            return;
        }
        $this->inner->set($reverseKey, json_encode(array_values($tags), JSON_THROW_ON_ERROR));
    }

    private function assertUserKey(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty.');
        }
        if (str_starts_with($key, self::RESERVED_PREFIX) || str_starts_with($key, self::REVERSE_PREFIX)) {
            throw new \InvalidArgumentException('Cache key uses a reserved internal prefix.');
        }

        return $key;
    }
}
