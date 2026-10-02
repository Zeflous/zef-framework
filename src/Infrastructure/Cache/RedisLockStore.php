<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 — Infrastructure layer (outbound adapters)
 * Implements the v2.8.0 LockStoreInterface port over Redis (issue #68):
 * SET NX PX acquisition, owner-token compare-and-delete release, and
 * compare-and-PEXPIRE refresh, all executed atomically on the server.
 */

namespace Zef\Framework\Cache;

/**
 * Redis-backed leased lock store implementing LockStoreInterface.
 *
 * Correctness rules (single Redis instance):
 *
 * - Acquisition is `SET key owner PX ttl NX` executed inside a Lua script,
 *   so the existence check and the write are one atomic server-side step.
 * - Re-acquiring while still holding the lock EXTENDS the lease and
 *   returns true — deliberate lease-renewal semantics shared by both lock
 *   stores: the sticky cluster scheduler depends on a holder's repeated
 *   acquire() calls keeping the lease alive. Use refresh() when the
 *   extension should be explicit rather than a side effect of acquiring.
 * - Release and refresh are compare-and-act Lua scripts: the lock is only
 *   deleted/extended when its current value equals the caller's owner
 *   token. A node may therefore never release or extend a lease that has
 *   already expired and been re-acquired by somebody else.
 * - TTL is enforced by the Redis server itself — no local clock takes part
 *   in the critical path, so multi-node deployments stay drift-free.
 * - Acquiring while another owner holds the lock returns false.
 *
 * Keys are namespaced under `zef:lock:` and hashed (sha256) the same way
 * RedisSharedRateLimitStore namespaces its buckets, so raw user-supplied
 * key bytes never reach the Redis keyspace.
 */
final readonly class RedisLockStore implements LockStoreInterface
{
    private const string PREFIX = 'zef:lock:';

    /** Atomic acquire: re-acquire by the same owner refreshes; SET NX PX otherwise. */
    private const string LUA_ACQUIRE = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current == ARGV[1] then
            return redis.call('PEXPIRE', KEYS[1], ARGV[2])
        end
        if current ~= false then
            return 0
        end
        local ok = redis.call('SET', KEYS[1], ARGV[1], 'PX', ARGV[2], 'NX')
        if ok then
            return 1
        end
        return 0
        LUA;

    /** Atomic compare-and-delete: only the current owner removes the lock. */
    private const string LUA_RELEASE = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    /** Atomic compare-and-PEXPIRE: only the current owner extends the lease. */
    private const string LUA_REFRESH = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('PEXPIRE', KEYS[1], ARGV[2])
        end
        return 0
        LUA;

    public function __construct(private \Redis $redis) {}

    #[\Override]
    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $this->assertTtl($ttlSeconds);
        $result = $this->redis->eval(
            self::LUA_ACQUIRE,
            [$this->redisKey($key), $owner, (string) ($ttlSeconds * 1000)],
            1,
        );
        if (!is_int($result) || $result < 0) {
            throw new LockStoreException('Redis lock store returned an unexpected acquire result.');
        }

        return $result === 1;
    }

    #[\Override]
    public function release(string $key, string $owner): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $result = $this->redis->eval(
            self::LUA_RELEASE,
            [$this->redisKey($key), $owner],
            1,
        );
        if (!is_int($result) || $result < 0) {
            throw new LockStoreException('Redis lock store returned an unexpected release result.');
        }

        return $result === 1;
    }

    #[\Override]
    public function refresh(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->assertKey($key);
        $this->assertOwner($owner);
        $this->assertTtl($ttlSeconds);
        $result = $this->redis->eval(
            self::LUA_REFRESH,
            [$this->redisKey($key), $owner, (string) ($ttlSeconds * 1000)],
            1,
        );
        if (!is_int($result) || $result < 0) {
            throw new LockStoreException('Redis lock store returned an unexpected refresh result.');
        }

        return $result === 1;
    }

    #[\Override]
    public function holder(string $key): ?string
    {
        $this->assertKey($key);
        $value = $this->redis->get($this->redisKey($key));

        return is_string($value) ? $value : null;
    }

    /** @param string $key raw lock identity (1..256 bytes) */
    private function redisKey(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 256) {
            throw new \InvalidArgumentException('Lock key must be 1..256 bytes.');
        }
    }

    private function assertOwner(string $owner): void
    {
        if ($owner === '' || strlen($owner) > 256) {
            throw new \InvalidArgumentException('Lock owner must be 1..256 bytes.');
        }
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Lock TTL must be 1..86400 seconds.');
        }
    }
}
