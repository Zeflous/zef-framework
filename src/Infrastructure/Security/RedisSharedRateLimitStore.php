<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

final readonly class RedisSharedRateLimitStore implements SharedRateLimitStoreInterface
{
    private const string PREFIX = 'zef:ratelimit:';
    private const string LUA_INCREMENT = <<<'LUA'
        local bucketKey = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local count = redis.call('HGET', bucketKey, 'count')
        local reset = redis.call('HGET', bucketKey, 'reset')
        count = count and tonumber(count) or 0
        reset = reset and tonumber(reset) or (now + window)
        if reset <= now then
            count = 0
            reset = now + window
        end
        count = count + 1
        redis.call('HSET', bucketKey, 'count', count, 'reset', reset)
        redis.call('PEXPIRE', bucketKey, window * 1000 + 60000)
        return {count, reset}
        LUA;

    public function __construct(private \Redis $redis) {}

    #[\Override]
    public function increment(string $key, int $windowSeconds, int $now): array
    {
        $result = $this->redis->eval(
            self::LUA_INCREMENT,
            [self::bucketKey($key, $windowSeconds), (string) $now, (string) $windowSeconds],
            1,
        );
        if (!is_array($result) || count($result) !== 2) {
            throw RateLimitStoreException::unexpectedIncrementResult();
        }
        // Audit #321: fail closed when either member is non-numeric — the
        // class contract (RateLimitStoreException::unexpectedIncrementResult)
        // promises the caller fails loudly instead of guessing a counter
        // value; the old ternaries silently guessed count=0 / reset=now+window.
        if (!is_numeric($result[0]) || !is_numeric($result[1])) {
            throw RateLimitStoreException::unexpectedIncrementResult();
        }

        return ['count' => (int) $result[0], 'reset' => (int) $result[1]];
    }

    #[\Override]
    public function peek(string $key, int $windowSeconds, int $now): ?array
    {
        $bucket = $this->redis->hMGet(self::bucketKey($key, $windowSeconds), ['count', 'reset']);
        if (
            !is_array($bucket)
            || !isset($bucket['count'], $bucket['reset'])
            || !is_numeric($bucket['count'])
            || !is_numeric($bucket['reset'])
        ) {
            return null;
        }

        return ['count' => (int) $bucket['count'], 'reset' => (int) $bucket['reset']];
    }

    /**
     * Audit #322: the bucket identity binds the window size. The storage key
     * previously hashed only the logical key, so re-using one key with a
     * different windowSeconds silently mixed buckets — exactly the
     * corruption the in-process limiters throw for and TieredRateLimiter
     * keys away from.
     */
    private static function bucketKey(string $key, int $windowSeconds): string
    {
        return self::PREFIX . hash('sha256', $key . '>' . $windowSeconds);
    }
}
