<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): real Redis-backed rate
 * limiting — connectRedis DSN matrix (auth, db select, failure modes),
 * RedisSharedRateLimitStore Lua bucket and RedisRateLimiter decisions.
 *
 * Requires a local Redis-compatible server started with:
 *   --port 6399 --requirepass zef-test-secret
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\ConfigProvider as MiddlewareProvider;

/**
 * @internal
 */
final class RedisStoreTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 6399;
    private const string PASS = 'zef-test-secret';

    private \Redis $redis;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension not available.');
        }
        $this->redis = new \Redis();
        // phpredis >= 6 throws RedisException from pconnect() instead of
        // returning false when the server is down; without the catch the
        // documented self-skip (INSTALLATION.md §8) never triggers and every
        // test in this class reports ERROR (issue #213).
        $reachable = false;

        try {
            $reachable = $this->redis->pconnect(self::HOST, self::PORT, 2.0) && $this->redis->auth(self::PASS);
        } catch (\RedisException) {
            $reachable = false;
        }
        if (!$reachable) {
            self::markTestSkipped('Redis test server not reachable.');
        }
        $this->redis->select(0);
        $this->redis->flushDB();
        putenv('ZEF_SECURITY_CSRF_SECRET=' . str_repeat('s', 32));
    }

    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT');
        putenv('ZEF_SECURITY_CSRF_SECRET');
        putenv('ZEF_RATE_LIMIT_STORE');
        putenv('ZEF_REDIS_URL');
        putenv('ZEF_REDIS_TIMEOUT_MS');
        if (isset($this->redis) && $this->redis->isConnected()) {
            $this->redis->flushDB();
        }
    }

    public function testConnectRedisHappyPathWithAuthAndDb(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=' . $this->dsn('/0'));
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();
        $middleware = $factory($container);
        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
        self::assertSame([], $logger->warnings);
    }

    public function testConnectRedisRejectsEmptyAndInvalidDsn(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();

        putenv('ZEF_REDIS_URL=');
        $factory($container);
        self::assertStringContainsString('ZEF_REDIS_URL is required', $logger->lastWarning() ?? '');

        $logger->warnings = [];
        // A DSN without a host part fails closed in parseAuthority.
        putenv('ZEF_REDIS_URL=redis://:onlypass@');
        $factory($container);
        self::assertStringContainsString('Invalid ZEF_REDIS_URL DSN', $logger->lastWarning() ?? '');
    }

    public function testConnectRedisRejectsNonIntegerDb(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=' . $this->dsn('/abc'));
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();
        $factory($container);
        self::assertStringContainsString('non-negative integer', $logger->lastWarning() ?? '');
    }

    public function testConnectRedisRejectsFailedSelect(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=' . $this->dsn('/99'));
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();
        $factory($container);
        self::assertStringContainsString('Redis SELECT failed', $logger->lastWarning() ?? '');
    }

    public function testConnectRedisRejectsBadPassword(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=redis://:wrong-password@' . self::HOST . ':' . self::PORT . '/0');
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();
        $factory($container);
        self::assertStringContainsString('unavailable', $logger->lastWarning() ?? '');
        self::assertStringContainsString('WRONGPASS', $logger->lastWarning() ?? '');
    }

    public function testConnectRedisRejectsUnreachableServer(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=redis://:' . self::PASS . '@' . self::HOST . ':6398/0');
        putenv('ZEF_REDIS_TIMEOUT_MS=200');
        [$factory, $logger, $container] = $this->securityRuntimeFactoryWithLogger();
        $factory($container);
        self::assertStringContainsString('unavailable', $logger->lastWarning() ?? '');
    }

    public function testRedisSharedStoreIncrementsAndPeeksBuckets(): void
    {
        $store = new RedisSharedRateLimitStore($this->redis);
        $now = time();
        $first = $store->increment('unit-key-a', 60, $now);
        self::assertSame(1, $first['count']);
        self::assertSame($now + 60, $first['reset']);

        $second = $store->increment('unit-key-a', 60, $now);
        self::assertSame(2, $second['count']);

        $peeked = $store->peek('unit-key-a', 60, $now);
        self::assertIsArray($peeked);
        self::assertSame(2, $peeked['count']);

        self::assertNull($store->peek('unit-key-never-touched', 60, $now));
    }

    public function testRedisSharedStoreExpiresOldWindows(): void
    {
        $store = new RedisSharedRateLimitStore($this->redis);
        $now = time();
        $stale = $store->increment('unit-key-b', 60, $now - 3600);
        self::assertSame(1, $stale['count']);
        $fresh = $store->increment('unit-key-b', 60, $now);
        self::assertSame(1, $fresh['count']);
        self::assertSame($now + 60, $fresh['reset']);
    }

    public function testRedisRateLimiterDecidesWithRealStore(): void
    {
        $limiter = new RedisRateLimiter(new RedisSharedRateLimitStore($this->redis), 100);
        $decision = $limiter->check('unit-limiter-c', 2, 60);
        self::assertInstanceOf(RateLimitDecision::class, $decision);
        self::assertTrue($decision->allowed);
        self::assertSame(1, $decision->remaining);

        $limiter->check('unit-limiter-c', 2, 60);
        $blocked = $limiter->check('unit-limiter-c', 2, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertGreaterThanOrEqual(1, $blocked->retryAfter);
    }

    /**
     * @return array{0:callable,1:CollectingLogger,2:Container}
     */
    private function securityRuntimeFactoryWithLogger(): array
    {
        $logger = new CollectingLogger();
        $container = new Container();
        $container->register(
            LoggerInterface::class,
            static fn (): LoggerInterface => $logger,
            [],
            'test',
        );
        $config = new MiddlewareProvider()->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        $spec = $services['middleware.security.runtime'];
        self::assertIsArray($spec);
        $factory = $spec['factory'];
        assert(is_callable($factory));

        return [$factory, $logger, $container];
    }

    private function dsn(string $suffix = ''): string
    {
        return 'redis://:' . self::PASS . '@' . self::HOST . ':' . self::PORT . $suffix;
    }
}
