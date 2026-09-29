<?php

declare(strict_types=1);

/*
 * ZEF Framework — Demo application (App layer)
 * Rate-limit wiring extracted from the middleware ConfigProvider so the
 * provider stays inside the class-coupling budget: tier parsing, the
 * limiter store selection and the Redis connection all live here.
 * Move-only extraction — behaviour, exception messages and comments are
 * carried over unchanged from the ConfigProvider (v2.31.0 semantics).
 */

namespace Zef\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\HrTimeClock;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimitAlgorithm;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;

/**
 * @internal
 */
final class SecurityRateLimitWiring
{
    /**
     * Tiers are flat JSON objects; the depth cap is generous headroom against
     * pathological nesting (a deeply nested payload fails the JSON parse
     * with a clear boot error instead of behaving unexpectedly).
     */
    private const int MAX_TIER_JSON_DEPTH = 16;

    /**
     * Builds the tiered rate-limit middleware. v2.31.0 (Regresi I-5 /
     * issue #173): the container argument is optional so zero-argument
     * invocation (tests, manual wiring) keeps working and simply runs
     * with the logger disabled — the limiter must stay wired either way.
     */
    public static function rateLimitMiddleware(EnvInterface $env, ?ContainerInterface $container): RateLimitMiddleware
    {
        $logger = self::optionalLogger($container);
        $rules = self::parseTiers($env->readString('ZEF_SECURITY_RATE_LIMIT_TIERS'));
        $algorithm = RateLimitAlgorithm::fromString(
            $env->readString('ZEF_SECURITY_RATE_LIMIT_ALGORITHM', 'sliding'),
        );
        $limiter = $algorithm === RateLimitAlgorithm::TokenBucket
            ? new TokenBucketRateLimiter(new HrTimeClock())
            : new SlidingWindowRateLimiter(new HrTimeClock());

        return new RateLimitMiddleware(
            new TieredRateLimiter($limiter),
            $rules,
            failOpen: $env->readBool('ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN'),
            trustIdentityHeader: $env->readBool('ZEF_SECURITY_RATE_LIMIT_TRUST_IDENTITY_HEADER'),
            logger: $logger,
        );
    }

    /**
     * Bug fix #17: logger passed to limiterFor() for fallback warning.
     *
     * v2.6.0: the 'redis' option now REQUIRES ZEF_REDIS_URL and actually
     * connects (pconnect + optional auth/db) inside the try block, so an
     * unreachable server fails fast at boot and falls back to in-memory —
     * instead of constructing a never-connected \Redis and returning 503
     * for every request at runtime.
     */
    public static function limiterFor(
        SecurityPolicy $policy,
        ?LoggerInterface $logger = null,
        ?EnvInterface $env = null,
    ): RateLimiterInterface {
        $env ??= new Env();
        $store = strtolower(trim($env->readString('ZEF_RATE_LIMIT_STORE', 'memory')));

        try {
            return match ($store) {
                'apcu' => new ApcuRateLimiter($policy->rateLimitMaxKeys),
                'redis' => new RedisRateLimiter(
                    new RedisSharedRateLimitStore(self::connectRedis($env)),
                    $policy->rateLimitMaxKeys,
                ),
                default => new InMemoryRateLimiter($policy->rateLimitMaxKeys),
            };
        } catch (\Throwable $e) {
            $msg = '[ZEF][security] Rate-limit store "' . $store . '" unavailable ('
                . $e->getMessage()
                . '); falling back to in-memory per-process limiter.';
            if ($logger instanceof LoggerInterface) {
                $logger->warning($msg);
            } else {
                error_log($msg);
            }

            return new InMemoryRateLimiter($policy->rateLimitMaxKeys);
        }
    }

    /**
     * Parses ZEF_SECURITY_RATE_LIMIT_TIERS (a JSON list of tier objects)
     * fail-fast: malformed JSON, a non-list root, a non-object entry or an
     * invalid tier are BOOT errors — silently dropping a configured quota
     * would be a security hole.
     *
     * @return list<RateLimitRule>
     */
    public static function parseTiers(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, self::MAX_TIER_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidConfigurationException(
                'ZEF_SECURITY_RATE_LIMIT_TIERS is not valid JSON: ' . $e->getMessage(),
                $e->getCode(),
                $e,
            );
        }
        if (!is_array($decoded) || array_values($decoded) !== $decoded) {
            throw new InvalidConfigurationException(
                'ZEF_SECURITY_RATE_LIMIT_TIERS must be a JSON list of tier objects.',
            );
        }
        $rules = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidConfigurationException(
                    sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u must be an object.', $index),
                );
            }

            /** @var array<string, mixed> $object */
            $object = $entry;

            try {
                $rules[] = RateLimitRule::fromArray($object);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigurationException(
                    sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u invalid: %s', $index, $e->getMessage()),
                    $e->getCode(),
                    $e,
                );
            }
        }

        return $rules;
    }

    /**
     * Resolves the optional PSR-3 sink: when the container cannot provide
     * one the middleware runs with logging disabled (v2.31.0, Regresi I-5).
     */
    private static function optionalLogger(?ContainerInterface $container): ?LoggerInterface
    {
        try {
            $resolved = $container?->get(LoggerInterface::class);

            return $resolved instanceof LoggerInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Builds a connected \Redis client from ZEF_REDIS_URL
     * (redis://[:password@]host[:port][/db]). Throws on failure so the
     * caller can fall back at boot instead of failing every request.
     */
    private static function connectRedis(?EnvInterface $env): \Redis
    {
        $env ??= new Env();
        if (!class_exists(\Redis::class)) {
            throw new InvalidConfigurationException('The phpredis extension is not installed.');
        }
        $dsn = trim($env->readString('ZEF_REDIS_URL', ''));
        if ($dsn === '') {
            throw new InvalidConfigurationException(
                'ZEF_REDIS_URL is required when ZEF_RATE_LIMIT_STORE=redis.',
            );
        }
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidConfigurationException('Invalid ZEF_REDIS_URL DSN.');
        }
        $redis = new \Redis();
        $timeout = (float) ($env->readInt('ZEF_REDIS_TIMEOUT_MS', 2000, 100, 10000) / 1000);
        if (!$redis->pconnect((string) $parts['host'], (int) ($parts['port'] ?? 6379), $timeout)) {
            throw new InvalidConfigurationException('Unable to connect to Redis.');
        }
        self::authenticateRedis($redis, $parts['user'] ?? null, $parts['pass'] ?? null);
        self::selectRedisDb($redis, isset($parts['path']) ? (string) $parts['path'] : '');

        return $redis;
    }

    private static function authenticateRedis(\Redis $redis, ?string $username, ?string $password): void
    {
        if ($password === null) {
            return;
        }
        if (!$redis->auth($username !== null && $username !== '' ? [$username, $password] : $password)) {
            throw new InvalidConfigurationException('Redis authentication failed.');
        }
    }

    private static function selectRedisDb(\Redis $redis, string $path): void
    {
        $db = trim($path, '/');
        if ($db === '') {
            return;
        }
        if (!ctype_digit($db)) {
            // ctype_digit also rejects signed forms like "-1"/"+1":
            // the fail-closed behaviour is correct for both, but the
            // message must say WHY (non-negative integer required).
            throw new InvalidConfigurationException(
                'Redis DB index in ZEF_REDIS_URL must be a non-negative integer.',
            );
        }
        if (!$redis->select((int) $db)) {
            throw new InvalidConfigurationException('Redis SELECT failed.');
        }
    }
}
