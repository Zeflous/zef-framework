<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Config\ConfigProviderInterface;
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
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;

final readonly class ConfigProvider implements ConfigProviderInterface
{
    /**
     * Tiers are flat JSON objects; the depth cap is generous headroom against
     * pathological nesting (a deeply nested payload fails the JSON parse
     * with a clear boot error instead of behaving unexpectedly).
     */
    private const int MAX_TIER_JSON_DEPTH = 16;

    public function __construct(
        private bool $devMode = false,
        private EnvInterface $env = new Env(),
    ) {}

    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    #[\Override]
    public function getConfig(): array
    {
        $devMode = $this->devMode;
        $env = $this->env;

        return [
            'services' => [
                'middleware.error' => [
                    'factory' => static fn (ContainerInterface $c): GlobalErrorHandler => new GlobalErrorHandler(
                        $c->get(LoggerInterface::class),
                        new ErrorResponseFactory($devMode),
                    ),
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.timing' => [
                    'factory' => static fn (): TimingMiddleware => new TimingMiddleware(),
                    'deps' => [],
                ],
                'middleware.cors' => [
                    'factory' => $this->buildCors(...),
                    'deps' => [],
                ],
                'middleware.security' => [
                    'factory' => static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware([
                        'hsts' => $env->readBool('ZEF_SECURITY_HSTS'),
                        'csp' => $env->readBool('ZEF_SECURITY_CSP'),
                    ]),
                    'deps' => [],
                ],
                'middleware.security.runtime' => [
                    'factory' => static function (ContainerInterface $c) use ($env): SecurityRuntimeMiddleware {
                        $logger = null;

                        try {
                            $logger = $c->get(LoggerInterface::class);
                        } catch (\Throwable) {
                        }
                        $policy = SecurityPolicy::fromEnvironment($logger, $env);
                        $rateLimiter = self::buildRateLimiter($policy, $logger, $env);

                        // v2.31.0 (Regresi I-5 / issue #173): the swallowed
                        // limiter failures are logged through the same PSR-3
                        // sink the policy already uses.
                        return new SecurityRuntimeMiddleware(
                            $policy,
                            $rateLimiter,
                            logger: $logger instanceof LoggerInterface ? $logger : null,
                        );
                    },
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.security.rate_limit' => [
                    'factory' => static function (?ContainerInterface $c = null) use ($env): RateLimitMiddleware {
                        // v2.31.0 (Regresi I-5 / issue #173): same optional
                        // PSR-3 sink as the runtime security middleware. The
                        // container argument is optional so zero-argument
                        // invocation (tests, manual wiring) keeps working and
                        // simply runs with the logger disabled.
                        $logger = null;

                        try {
                            $resolved = $c?->get(LoggerInterface::class);
                            $logger = $resolved instanceof LoggerInterface ? $resolved : null;
                        } catch (\Throwable) {
                            // Optional dependency (v2.31.0, Regresi I-5): when
                            // the container cannot resolve a PSR-3 sink the
                            // middleware runs with logging disabled — the
                            // limiter must stay wired either way.
                        }
                        $rules = self::parseRateLimitTiers($env->readString('ZEF_SECURITY_RATE_LIMIT_TIERS'));
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
                    },
                    'deps' => [],
                ],
            ],
            'stack' => $this->buildStack(),
        ];
    }

    /**
     * The tiered rate-limit middleware joins the stack (right after the
     * global security runtime middleware) ONLY when tiers are configured —
     * an unconfigured tier middleware would be inert, and registering it
     * anyway would suggest protection that is not there.
     *
     * @return list<string>
     */
    private function buildStack(): array
    {
        $stack = [
            'middleware.error',
            'middleware.security.runtime',
            'middleware.security',
            'middleware.timing',
            'middleware.cors',
        ];
        if (trim($this->env->readString('ZEF_SECURITY_RATE_LIMIT_TIERS')) !== '') {
            array_splice($stack, 2, 0, ['middleware.security.rate_limit']);
        }

        return $stack;
    }

    /**
     * Parses ZEF_SECURITY_RATE_LIMIT_TIERS (a JSON list of tier objects)
     * fail-fast: malformed JSON, a non-list root, a non-object entry or an
     * invalid tier are BOOT errors — silently dropping a configured quota
     * would be a security hole.
     *
     * @return list<RateLimitRule>
     */
    private static function parseRateLimitTiers(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, self::MAX_TIER_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('ZEF_SECURITY_RATE_LIMIT_TIERS is not valid JSON: ' . $e->getMessage(), $e->getCode(), $e);
        }
        if (!is_array($decoded) || array_values($decoded) !== $decoded) {
            throw new \RuntimeException('ZEF_SECURITY_RATE_LIMIT_TIERS must be a JSON list of tier objects.');
        }
        $rules = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException(sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u must be an object.', $index));
            }

            /** @var array<string, mixed> $object */
            $object = $entry;

            try {
                $rules[] = RateLimitRule::fromArray($object);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException(sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u invalid: %s', $index, $e->getMessage()), $e->getCode(), $e);
            }
        }

        return $rules;
    }

    /**
     * Bug fix #17: logger passed to buildRateLimiter for fallback warning.
     *
     * v2.6.0: the 'redis' option now REQUIRES ZEF_REDIS_URL and actually
     * connects (pconnect + optional auth/db) inside the try block, so an
     * unreachable server fails fast at boot and falls back to in-memory —
     * instead of constructing a never-connected \Redis and returning 503
     * for every request at runtime.
     */
    private static function buildRateLimiter(
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
            $msg = '[ZEF][security] Rate-limit store "' . $store . '" unavailable (' . $e->getMessage() . '); falling back to in-memory per-process limiter.';
            if ($logger instanceof LoggerInterface) {
                $logger->warning($msg);
            } else {
                error_log($msg);
            }

            return new InMemoryRateLimiter($policy->rateLimitMaxKeys);
        }
    }

    /**
     * Builds a connected \Redis client from ZEF_REDIS_URL
     * (redis://[:password@]host[:port][/db]). Throws on failure so the
     * caller can fall back at boot instead of failing every request.
     */
    private static function connectRedis(?EnvInterface $env = null): \Redis
    {
        $env ??= new Env();
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('The phpredis extension is not installed.');
        }
        $dsn = trim($env->readString('ZEF_REDIS_URL', ''));
        if ($dsn === '') {
            throw new \RuntimeException('ZEF_REDIS_URL is required when ZEF_RATE_LIMIT_STORE=redis.');
        }
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new \RuntimeException('Invalid ZEF_REDIS_URL DSN.');
        }
        $redis = new \Redis();
        $timeout = (float) ($env->readInt('ZEF_REDIS_TIMEOUT_MS', 2000, 100, 10000) / 1000);
        if (!$redis->pconnect((string) $parts['host'], (int) ($parts['port'] ?? 6379), $timeout)) {
            throw new \RuntimeException('Unable to connect to Redis.');
        }
        $password = $parts['pass'] ?? null;
        if ($password !== null) {
            $username = $parts['user'] ?? null;
            if (!$redis->auth($username !== null && $username !== '' ? [$username, $password] : $password)) {
                throw new \RuntimeException('Redis authentication failed.');
            }
        }
        $db = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($db !== '') {
            // "redis://h/abc" previously (int)-cast to 0 and SILENTLY
            // selected db 0 — a wrong-database isolation bug.
            if (!ctype_digit($db)) {
                // ctype_digit also rejects signed forms like "-1"/"+1":
                // the fail-closed behaviour is correct for both, but the
                // message must say WHY (non-negative integer required).
                throw new \RuntimeException('Redis DB index in ZEF_REDIS_URL must be a non-negative integer.');
            }
            if (!$redis->select((int) $db)) {
                throw new \RuntimeException('Redis SELECT failed.');
            }
        }

        return $redis;
    }

    private function buildCors(): CorsMiddleware
    {
        if ($this->env->readBool('ZEF_CORS_ORIGIN_ANY')) {
            return new CorsMiddleware(['*']);
        }
        $origins = $this->env->readCsv('ZEF_CORS_ORIGIN');

        return new CorsMiddleware($origins);
    }
}
