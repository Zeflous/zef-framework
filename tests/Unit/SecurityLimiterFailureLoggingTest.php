<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regresi I-5 (issue #173): swallowed limiter failures
 * must be observable.
 *
 * Both security middlewares swallow limiter exceptions (fail-closed 503,
 * fail-open pass-through, capacity fail-open). Before v2.31.0 the swallow
 * was silent, so a mass 503 was indistinguishable from an attack versus a
 * storage bug in production. These tests pin the contract: exactly ONE
 * error-level record per failure path, carrying only safe context (tier
 * names, fingerprinted identity / client IP, request id, exception class —
 * never raw credentials), and a null logger keeps the silent behaviour.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\CostAwareRateLimiterInterface;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterCapacityException;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Middleware\ConfigProvider;
use Zef\Test\Unit\CollectingLogger;

/**
 * @internal
 */
final class SecurityLimiterFailureLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS');
        putenv('ZEF_SECURITY_RATE_LIMIT_ALGORITHM');
        putenv('ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN');
    }

    // ------------------------------------------------------------------
    // RateLimitMiddleware — one error record per swallow path
    // ------------------------------------------------------------------

    /**
     * Regresi I-5 (issue #173): the fail-closed 503 path logs exactly one
     * error record with the tier names, the fingerprinted identity and the
     * exception class — the safe context operators need to tell an attack
     * (429s, distinct identities) from a storage bug (503s, one record per
     * request).
     */
    public function testTieredStorageFailureFailClosedLogsExactlyOneErrorRecord(): void
    {
        $logger = new CollectingLogger();
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->brokenLimiter()),
            [new RateLimitRule('api', 5, 10)],
            logger: $logger,
        );

        $response = $middleware->process($this->request('/api'), $this->handler());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['[ZEF][security] Rate limiter storage failure (fail-closed 503) for identity ip:10.0.0.1'
                . ' on tiers [api]: RuntimeException: storage down'],
            $logger->errors,
            'exactly one error record per swallowed failure',
        );
    }

    /**
     * Regresi I-5 (issue #173): the fail-open path is logged too — a
     * silently degraded quota is as diagnosable as a 503 flood.
     */
    public function testTieredStorageFailureFailOpenLogsExactlyOneErrorRecord(): void
    {
        $logger = new CollectingLogger();
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->brokenLimiter()),
            [new RateLimitRule('api', 5, 10)],
            [],
            true,
            logger: $logger,
        );

        $response = $middleware->process($this->request('/api'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('Rate limiter storage failure (fail-open)', $logger->errors[0]);
        self::assertStringContainsString('on tiers [api]', $logger->errors[0]);
    }

    /**
     * Regresi I-5 (issue #173): capacity exhaustion (ZEF-DEEP-02's
     * controlled fail-open) is swallowed as well — one record names the
     * mode so a full store is visible without being confused with a 503.
     */
    public function testTieredCapacityExhaustionLogsExactlyOneErrorRecord(): void
    {
        $logger = new CollectingLogger();
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->fullLimiter()),
            [new RateLimitRule('api', 5, 10)],
            logger: $logger,
        );

        $response = $middleware->process($this->request('/api'), $this->handler());

        self::assertSame(200, $response->getStatusCode(), 'capacity exhaustion serves the request untracked');
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('Rate limiter capacity exhausted (untracked fail-open)', $logger->errors[0]);
        self::assertStringContainsString('RateLimiterCapacityException: store full', $logger->errors[0]);
    }

    /**
     * Regresi I-5 (issue #173): identities are fingerprinted before they
     * reach the log — an attributed identity appears as its sha256 hash,
     * never as the raw credential.
     */
    public function testTieredFailureRecordCarriesFingerprintedIdentityOnly(): void
    {
        $logger = new CollectingLogger();
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->brokenLimiter()),
            [new RateLimitRule('api', 5, 10)],
            logger: $logger,
        );
        $attributed = $this->request('/api')->withAttribute('zef.auth.identity', 'secret-bearer-token');

        $middleware->process($attributed, $this->handler());

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('for identity identity:' . hash('sha256', 'secret-bearer-token'), $logger->errors[0]);
        self::assertStringNotContainsString('secret-bearer-token', $logger->errors[0], 'raw credentials must never reach the log');
    }

    // ------------------------------------------------------------------
    // SecurityRuntimeMiddleware — one error record per swallow path
    // ------------------------------------------------------------------

    /**
     * Regresi I-5 (issue #173): the global limiter's fail-closed 503 logs
     * one record with the client IP and the request id it already echoes
     * in the 503 envelope — no headers, no credentials.
     */
    public function testRuntimeStorageFailureLogsExactlyOneErrorRecord(): void
    {
        $logger = new CollectingLogger();
        $middleware = new SecurityRuntimeMiddleware(
            new SecurityPolicy(rateLimitEnabled: true),
            $this->brokenGlobalLimiter(),
            logger: $logger,
        );

        $response = $middleware->process(
            $this->request('/', 'GET', ['X-Request-ID' => 'req-log-1']),
            $this->handler(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['[ZEF][security] Rate limiter storage failure (fail-closed 503) for client 10.0.0.1'
                . ' on request req-log-1: RuntimeException: limiter down'],
            $logger->errors,
        );
    }

    /**
     * Regresi I-5 (issue #173): capacity exhaustion on the global limiter
     * serves untracked and logs one record naming the mode.
     */
    public function testRuntimeCapacityExhaustionLogsExactlyOneErrorRecord(): void
    {
        $logger = new CollectingLogger();
        $middleware = new SecurityRuntimeMiddleware(
            new SecurityPolicy(rateLimitEnabled: true),
            $this->fullGlobalLimiter(),
            logger: $logger,
        );

        $response = $middleware->process($this->request('/'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('Rate limiter capacity exhausted (untracked fail-open)', $logger->errors[0]);
        self::assertStringContainsString('RateLimiterCapacityException: store full', $logger->errors[0]);
    }

    /**
     * Regresi I-5 (issue #173): a request without a usable X-Request-ID
     * still logs — the middleware generates a random id for the envelope,
     * and the record rides along with it.
     */
    public function testRuntimeFailureWithoutRequestIdStillLogs(): void
    {
        $logger = new CollectingLogger();
        $middleware = new SecurityRuntimeMiddleware(
            new SecurityPolicy(rateLimitEnabled: true),
            $this->brokenGlobalLimiter(),
            logger: $logger,
        );

        $response = $middleware->process($this->request('/'), $this->handler());

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $logger->errors);
        self::assertMatchesRegularExpression('/on request [0-9a-f]{32}: RuntimeException: limiter down$/', $logger->errors[0]);
    }

    // ------------------------------------------------------------------
    // ConfigProvider wiring + BC contract
    // ------------------------------------------------------------------

    /**
     * Regresi I-5 (issue #173): the tiered-middleware factory resolves the
     * PSR-3 sink from the container when one is passed, and runs silently
     * (null logger) on zero-argument invocation — the BC contract every
     * existing zero-arg wiring and test relies on.
     */
    public function testConfigProviderWiresOptionalLoggerIntoTieredFactory(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode([
            ['name' => 'api', 'limit' => 10, 'windowSeconds' => 60, 'pathPrefix' => '/api'],
        ]));
        $config = new ConfigProvider()->getConfig();
        $services = $config['services'];
        assert(is_array($services));
        $definition = $services['middleware.security.rate_limit'] ?? null;
        assert(is_array($definition));
        $factory = $definition['factory'] ?? null;
        assert(is_callable($factory));

        $logger = new CollectingLogger();
        $container = new readonly class($logger) implements ContainerInterface {
            public function __construct(private LoggerInterface $logger) {}

            #[\Override]
            public function get(string $id): mixed
            {
                return $this->logger;
            }

            #[\Override]
            public function has(string $id): bool
            {
                return $id === LoggerInterface::class;
            }
        };
        $wired = $factory($container);
        assert($wired instanceof RateLimitMiddleware);
        self::assertSame(
            $logger,
            new \ReflectionProperty(RateLimitMiddleware::class, 'logger')->getValue($wired),
            'the factory must pass the container-resolved logger through',
        );

        $bare = $factory();
        assert($bare instanceof RateLimitMiddleware);
        self::assertNull(
            new \ReflectionProperty(RateLimitMiddleware::class, 'logger')->getValue($bare),
            'zero-argument invocation keeps the silent (null logger) behaviour',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = [], array $server = []): ServerRequestInterface
    {
        $server += ['REMOTE_ADDR' => '10.0.0.1'];

        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']), $server, [], [], [], null, $headers);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }

    private function brokenLimiter(): CostAwareRateLimiterInterface
    {
        return new class implements CostAwareRateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new \RuntimeException('storage down');
            }

            #[\Override]
            public function consume(string $key, int $limit, int $windowSeconds, int $cost = 1): RateLimitDecision
            {
                throw new \RuntimeException('storage down');
            }
        };
    }

    private function fullLimiter(): CostAwareRateLimiterInterface
    {
        return new class implements CostAwareRateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new RateLimiterCapacityException('store full');
            }

            #[\Override]
            public function consume(string $key, int $limit, int $windowSeconds, int $cost = 1): RateLimitDecision
            {
                throw new RateLimiterCapacityException('store full');
            }
        };
    }

    private function brokenGlobalLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new \RuntimeException('limiter down');
            }
        };
    }

    private function fullGlobalLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new RateLimiterCapacityException('store full');
            }
        };
    }
}
