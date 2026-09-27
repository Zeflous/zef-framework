<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-02 (issue #156):
 * client-controlled identity headers must not bypass per-IP rate limits,
 * and bounded-store capacity exhaustion must not become a global 503.
 *
 * The pre-fix PoC (limit 3/min, one IP): rotating a unique X-API-Key per
 * request kept 10/10 requests at 200 — every header value minted a fresh
 * bucket, and once maxKeys was reached the fail-closed path returned 503 to
 * every NEW identity, legitimate clients included.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\HrTimeClock;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterCapacityException;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;
use Zef\Middleware\ConfigProvider;

/**
 * @internal
 */
final class RateLimitIdentityHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS');
        putenv('ZEF_SECURITY_RATE_LIMIT_ALGORITHM');
        putenv('ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN');
        putenv('ZEF_SECURITY_RATE_LIMIT_TRUST_IDENTITY_HEADER');
    }

    // ------------------------------------------------------------------
    // The bypass PoC (ZEF-DEEP-02, part 1)
    // ------------------------------------------------------------------

    /** Rotating spoofed X-API-Key values must NOT mint fresh buckets. */
    public function testRotatingSpoofedApiKeysCannotBypassPerIpQuota(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 3, 60)]);
        $handler = $this->handler();

        $codes = [];
        for ($i = 1; $i <= 10; ++$i) {
            $request = $this->request('/api', 'GET', ['X-API-Key' => 'spoofed-unique-' . $i]);
            $codes[] = $middleware->process($request, $handler)->getStatusCode();
        }

        self::assertSame(
            [200, 200, 200, 429, 429, 429, 429, 429, 429, 429],
            $codes,
            'pre-fix PoC produced 10x200 via header rotation; the quota must hold on the ip bucket',
        );
    }

    /** Default mode resolves the identity from REMOTE_ADDR even with a header present. */
    public function testDefaultModeKeysOnResolvedClientIpWithHeaderPresent(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 60)]);
        $handler = $this->handler();

        self::assertSame(200, $middleware->process($this->request('/api', 'GET', ['X-API-Key' => 'a'], ['REMOTE_ADDR' => '10.0.0.9']), $handler)->getStatusCode());
        self::assertSame(429, $middleware->process($this->request('/api', 'GET', ['X-API-Key' => 'b'], ['REMOTE_ADDR' => '10.0.0.9']), $handler)->getStatusCode());
        self::assertSame(429, $middleware->process($this->request('/api', 'GET', ['X-API-Key' => 'c'], ['REMOTE_ADDR' => '10.0.0.9']), $handler)->getStatusCode());
        self::assertSame(200, $middleware->process($this->request('/api', 'GET', ['X-API-Key' => 'a'], ['REMOTE_ADDR' => '10.0.0.10']), $handler)->getStatusCode(), 'a different client IP is a different bucket');
    }

    /** Authenticated principals keep bucketing per identity even in opt-in mode. */
    public function testAttributeIdentityStillWinsOverTrustedHeader(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 60)], trustIdentityHeader: true);
        $handler = $this->handler();

        $attributed = fn (string $identity): ServerRequestInterface => $this->request('/api')->withAttribute('zef.auth.identity', $identity);
        self::assertSame(200, $middleware->process($attributed('user-1'), $handler)->getStatusCode());
        $withSpoofedHeader = $attributed('user-1')->withHeader('X-API-Key', 'anything-else');
        if (!$withSpoofedHeader instanceof ServerRequestInterface) {
            throw new \LogicException('withHeader must preserve the request type.');
        }
        self::assertSame(
            429,
            $middleware->process($withSpoofedHeader, $handler)->getStatusCode(),
            'the authenticated attribute owns the bucket; the header cannot move a principal to a fresh one',
        );
    }

    // ------------------------------------------------------------------
    // Capacity exhaustion (ZEF-DEEP-02, part 2)
    // ------------------------------------------------------------------

    /** A full store must serve the request untracked — not 503 the world. */
    public function testCapacityExhaustionServesUntrackedInsteadOfGlobal503(): void
    {
        $limiter = new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new RateLimiterCapacityException('Rate limiter capacity exhausted.');
            }
        };
        $middleware = new RateLimitMiddleware(new TieredRateLimiter($limiter), [new RateLimitRule('api', 1, 60)]);

        $response = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(200, $response->getStatusCode(), 'capacity exhaustion is not a storage failure — the request is served');
        self::assertFalse($response->hasHeader('RateLimit-Limit'), 'served untracked: no verdict, no headers');
        self::assertFalse($response->hasHeader('Retry-After'), 'no 429/503 envelope either');
    }

    /** Contrast: a real storage failure keeps the configured fail-closed policy. */
    public function testStorageFailureStillFailsClosedByDefault(): void
    {
        $limiter = new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new \RuntimeException('limiter store unreachable');
            }
        };
        $middleware = new RateLimitMiddleware(new TieredRateLimiter($limiter), [new RateLimitRule('api', 1, 60)]);

        $response = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    /** The exception type swap is pinned for ALL bounded stores (message preserved). */
    public function testBoundedStoresThrowTheDedicatedCapacityException(): void
    {
        $clock = new HrTimeClock();

        $stores = [
            'in-memory' => static fn (): RateLimiterInterface => new InMemoryRateLimiter(maxKeys: 1),
            'sliding-window' => static fn (): RateLimiterInterface => new SlidingWindowRateLimiter($clock, maxKeys: 1),
            'token-bucket' => static fn (): RateLimiterInterface => new TokenBucketRateLimiter($clock, maxKeys: 1),
        ];

        foreach ($stores as $label => $factory) {
            $store = $factory();
            $store->check('occupied', 5, 60);

            try {
                $store->check('newcomer', 5, 60);
                self::fail("{$label}: a new key at maxKeys must throw.");
            } catch (RateLimiterCapacityException $e) {
                self::assertSame('Rate limiter capacity exhausted.', $e->getMessage(), "{$label}: message is a stable contract");
                self::assertInstanceOf(\RuntimeException::class, $e, "{$label}: still a RuntimeException for existing catch blocks");
            }
            // The occupied key keeps working — the guard only fires for keys
            // WITHOUT a bucket.
            self::assertTrue($store->check('occupied', 5, 60)->allowed, "{$label}: tracked keys are unaffected");
        }
    }

    /** The global per-IP runtime middleware gets the same capacity semantics. */
    public function testSecurityRuntimeMiddlewareServesUntrackedOnCapacityExhaustion(): void
    {
        $capacity = new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new RateLimiterCapacityException('Rate limiter capacity exhausted.');
            }
        };
        $policy = new SecurityPolicy(rateLimitEnabled: true, csrfEnabled: false);
        $middleware = new SecurityRuntimeMiddleware($policy, $capacity);

        $response = $middleware->process($this->request('/'), $this->handler());
        self::assertSame(200, $response->getStatusCode(), 'a full key store must not 503 every request');

        $storage = new class implements RateLimiterInterface {
            #[\Override]
            public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
            {
                throw new \RuntimeException('limiter store unreachable');
            }
        };
        $broken = new SecurityRuntimeMiddleware($policy, $storage);
        self::assertSame(503, $broken->process($this->request('/'), $this->handler())->getStatusCode(), 'contrast: real storage failures still fail closed');
    }

    // ------------------------------------------------------------------
    // ConfigProvider wiring (env knob)
    // ------------------------------------------------------------------

    public function testConfigProviderEnvKnobEnablesTrustedHeaderMode(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode([
            ['name' => 'api', 'limit' => 1, 'windowSeconds' => 60, 'pathPrefix' => '/api'],
        ]));
        putenv('ZEF_SECURITY_RATE_LIMIT_TRUST_IDENTITY_HEADER=1');
        $trusted = $this->tierFactory()();
        self::assertInstanceOf(RateLimitMiddleware::class, $trusted);
        $handler = $this->handler();

        self::assertSame(200, $trusted->process($this->request('/api', 'GET', ['X-API-Key' => 'k1']), $handler)->getStatusCode());
        self::assertSame(429, $trusted->process($this->request('/api', 'GET', ['X-API-Key' => 'k1']), $handler)->getStatusCode());
        self::assertSame(200, $trusted->process($this->request('/api', 'GET', ['X-API-Key' => 'k2']), $handler)->getStatusCode(), 'opt-in via env: per-key buckets');

        putenv('ZEF_SECURITY_RATE_LIMIT_TRUST_IDENTITY_HEADER');
        $default = $this->tierFactory()();
        self::assertInstanceOf(RateLimitMiddleware::class, $default);
        self::assertSame(200, $default->process($this->request('/api', 'GET', ['X-API-Key' => 'k1']), $handler)->getStatusCode());
        self::assertSame(
            429,
            $default->process($this->request('/api', 'GET', ['X-API-Key' => 'k2']), $handler)->getStatusCode(),
            'default wiring ignores the header — one ip bucket',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param list<RateLimitRule> $rules */
    private function middleware(array $rules, bool $trustIdentityHeader = false): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            new TieredRateLimiter(new InMemoryRateLimiter()),
            $rules,
            trustIdentityHeader: $trustIdentityHeader,
        );
    }

    /** @return callable(): mixed */
    private function tierFactory(): callable
    {
        $config = new ConfigProvider()->getConfig();
        $services = $config['services'];
        assert(is_array($services));
        $definition = $services['middleware.security.rate_limit'] ?? null;
        assert(is_array($definition));
        $factory = $definition['factory'] ?? null;
        assert(is_callable($factory));

        return $factory;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = [], array $server = []): ServerRequestInterface
    {
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
}
