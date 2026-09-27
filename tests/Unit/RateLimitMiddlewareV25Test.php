<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.25.0 Rate Limiting: PSR-15 middleware behaviour.
 *
 * Covers rule matching (path/method), identity resolution chain
 * (attribute > identity header (opt-in) > client IP), header emission
 * (IETF draft + legacy), 429/503 envelopes, fail-open vs fail-closed, verdict
 * exposure and the ConfigProvider wiring (tier parsing + conditional stack
 * insert).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\CostAwareRateLimiterInterface;
use Zef\Framework\Security\HrTimeClock;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\RateLimitVerdict;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Middleware\ConfigProvider;

/**
 * @internal
 */
final class RateLimitMiddlewareV25Test extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS');
        putenv('ZEF_SECURITY_RATE_LIMIT_ALGORITHM');
        putenv('ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN');
    }

    // ------------------------------------------------------------------
    // Rule matching
    // ------------------------------------------------------------------

    public function testPassesThroughWhenNoRuleMatches(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 5, 10, pathPrefix: '/api')]);
        $response = $middleware->process($this->request('/dashboard'), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('RateLimit-Limit'), 'no verdict, no headers');
    }

    public function testEmptyRuleSetIsInert(): void
    {
        $middleware = $this->middleware([]);
        $response = $middleware->process($this->request('/api/x'), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('RateLimit-Limit'));
    }

    public function testMethodFilteredRuleOnlyAppliesToItsMethods(): void
    {
        $middleware = $this->middleware([new RateLimitRule('write', 1, 10, 1, '/api', ['POST'])]);
        $get = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(200, $get->getStatusCode());
        self::assertFalse($get->hasHeader('RateLimit-Limit'), 'GET must bypass the POST-only tier');

        self::assertSame(200, $middleware->process($this->request('/api', 'POST'), $this->handler())->getStatusCode());
        $second = $middleware->process($this->request('/api', 'POST'), $this->handler());
        self::assertSame(429, $second->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Headers
    // ------------------------------------------------------------------

    public function testEmitsDraftAndLegacyHeadersOnAllowedRequest(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 5, 10, 1, '/api')]);
        $response = $middleware->process($this->request('/api/users'), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('RateLimit-Limit'));
        self::assertSame('4', $response->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('10', $response->getHeaderLine('RateLimit-Reset'), 'at t=0 the full window is ahead');
        self::assertSame('5', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('4', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testExposesVerdictToHandler(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public ?RateLimitVerdict $verdict = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $attribute = $request->getAttribute(RateLimitMiddleware::REQUEST_ATTRIBUTE);
                $this->verdict = $attribute instanceof RateLimitVerdict ? $attribute : null;

                return new Response(200, [], 'ok');
            }
        };
        $middleware = $this->middleware([new RateLimitRule('api', 5, 10)]);
        $middleware->process($this->request('/api'), $handler);
        self::assertInstanceOf(RateLimitVerdict::class, $handler->verdict);
        self::assertSame(5, $handler->verdict->limit);
        self::assertCount(1, $handler->verdict->outcomes);
        self::assertSame('api', $handler->verdict->outcomes[0]->ruleName);
    }

    // ------------------------------------------------------------------
    // 429 + 503 envelopes
    // ------------------------------------------------------------------

    public function testReturns429WithRetryAfterWhenExhausted(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10, 1, '/api')]);
        self::assertSame(200, $middleware->process($this->request('/api'), $this->handler())->getStatusCode());
        $denied = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(429, $denied->getStatusCode());
        self::assertSame('Too Many Requests', $this->errorField($denied, 'error'));
        self::assertSame('10', $denied->getHeaderLine('Retry-After'), 'denied at t=0: the whole window is ahead');
        self::assertSame('0', $denied->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('1', $denied->getHeaderLine('RateLimit-Limit'));
    }

    public function testStackedMatchingRulesShareTheVerdict(): void
    {
        $middleware = $this->middleware([
            new RateLimitRule('wide', 5, 10, 1, '/api'),
            new RateLimitRule('tight', 1, 10, 1, '/api'),
        ]);
        $first = $middleware->process($this->request('/api/users'), $this->handler());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('1', $first->getHeaderLine('RateLimit-Limit'), 'tightest quota wins');
        self::assertSame('0', $first->getHeaderLine('RateLimit-Remaining'));

        $second = $middleware->process($this->request('/api/users'), $this->handler());
        self::assertSame(429, $second->getStatusCode());
    }

    public function testFailsClosedByDefaultOnStorageErrors(): void
    {
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->brokenLimiter()),
            [new RateLimitRule('api', 5, 10)],
        );
        $response = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertSame('Service Unavailable', $this->errorField($response, 'error'));
    }

    public function testFailsOpenWhenConfigured(): void
    {
        $middleware = new RateLimitMiddleware(
            new TieredRateLimiter($this->brokenLimiter()),
            [new RateLimitRule('api', 5, 10)],
            [],
            true,
        );
        $response = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('RateLimit-Limit'));
    }

    // ------------------------------------------------------------------
    // Identity resolution chain
    // ------------------------------------------------------------------

    public function testIdentityAttributeWinsOverApiKeyAndIp(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        $attributed = $this->request('/api')->withAttribute('zef.auth.identity', 'user-9');
        self::assertSame(200, $middleware->process($attributed, $this->handler())->getStatusCode());
        self::assertSame(
            429,
            $middleware->process($this->apiKey($attributed, 'other-key'), $this->handler())->getStatusCode(),
            'attribute identity must share ONE bucket regardless of headers',
        );
        self::assertSame(
            200,
            $middleware->process($this->apiKey($this->request('/api'), 'other-key'), $this->handler())->getStatusCode(),
            'v2.31.0: the unverified header is ignored by default — the request lands in the fresh IP bucket',
        );
    }

    public function testIdentitySourcesNeverShareBucketsAcrossUsers(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        self::assertSame(200, $middleware->process($this->request('/api')->withAttribute('zef.auth.identity', 'user-A'), $this->handler())->getStatusCode());
        self::assertSame(
            200,
            $middleware->process($this->request('/api')->withAttribute('zef.auth.identity', 'user-B'), $this->handler())->getStatusCode(),
            'two attributed users must never share one bucket',
        );
    }

    public function testSameRawValueViaDifferentSourcesStaysIsolated(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        self::assertSame(200, $middleware->process($this->request('/api')->withAttribute('zef.auth.identity', 'secret-1'), $this->handler())->getStatusCode());
        self::assertSame(
            200,
            $middleware->process($this->apiKey($this->request('/api'), 'secret-1'), $this->handler())->getStatusCode(),
            'v2.31.0: the header is ignored by default — this request uses the IP bucket, never the attributed one',
        );
    }

    public function testApiKeyHeaderBucketsByValueWhenOptedIn(): void
    {
        // Default: the client-controlled header is IGNORED — every value
        // lands in the SAME ip bucket, so per-IP quota holds (ZEF-DEEP-02).
        $default = $this->middleware([new RateLimitRule('api', 1, 10)]);
        self::assertSame(200, $default->process($this->apiKey($this->request('/api'), 'k1'), $this->handler())->getStatusCode());
        self::assertSame(429, $default->process($this->apiKey($this->request('/api'), 'k1'), $this->handler())->getStatusCode());
        self::assertSame(
            429,
            $default->process($this->apiKey($this->request('/api'), 'k2'), $this->handler())->getStatusCode(),
            'a fresh header value must NOT mint a fresh bucket in default mode',
        );

        // Opt-in: identities bucket per header value (documented for
        // deployments behind an authenticating gateway).
        $trusted = $this->middleware([new RateLimitRule('api', 1, 10)], trustIdentityHeader: true);
        self::assertSame(200, $trusted->process($this->apiKey($this->request('/api'), 'k1'), $this->handler())->getStatusCode());
        self::assertSame(429, $trusted->process($this->apiKey($this->request('/api'), 'k1'), $this->handler())->getStatusCode());
        self::assertSame(200, $trusted->process($this->apiKey($this->request('/api'), 'k2'), $this->handler())->getStatusCode());
    }

    public function testApiKeyHeaderIsIgnoredByDefault(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        self::assertSame(200, $middleware->process($this->apiKey($this->request('/api'), 'k1'), $this->handler())->getStatusCode());
        self::assertSame(
            429,
            $middleware->process($this->apiKey($this->request('/api'), 'k2'), $this->handler())->getStatusCode(),
            'audit C-2: rotating unverified headers must NOT mint fresh buckets — same IP, same bucket',
        );
    }

    public function testFallsBackToClientIp(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        self::assertSame(200, $middleware->process($this->request('/api', 'GET', [], ['REMOTE_ADDR' => '10.0.0.1']), $this->handler())->getStatusCode());
        self::assertSame(429, $middleware->process($this->request('/api', 'GET', [], ['REMOTE_ADDR' => '10.0.0.1']), $this->handler())->getStatusCode());
        self::assertSame(200, $middleware->process($this->request('/api', 'GET', [], ['REMOTE_ADDR' => '10.0.0.2']), $this->handler())->getStatusCode());
    }

    public function testTrustedProxyAttributeOverridesForwardedClientIdentity(): void
    {
        $middleware = $this->middleware([new RateLimitRule('api', 1, 10)]);
        $proxied = fn (string $xff): ServerRequestInterface => $this
            ->request('/api', 'GET', ['X-Forwarded-For' => $xff], ['REMOTE_ADDR' => '10.0.0.1'])
            ->withAttribute('__zef_trusted_proxies', ['10.0.0.1'])
        ;
        self::assertSame(200, $middleware->process($proxied('203.0.113.7'), $this->handler())->getStatusCode());
        self::assertSame(
            200,
            $middleware->process($proxied('203.0.113.8'), $this->handler())->getStatusCode(),
            'different forwarded client = different bucket',
        );
        self::assertSame(
            429,
            $middleware->process($proxied('203.0.113.7'), $this->handler())->getStatusCode(),
            'same forwarded client shares the bucket',
        );
    }

    // ------------------------------------------------------------------
    // Constructor guard
    // ------------------------------------------------------------------

    public function testConstructorRejectsNonRuleEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RateLimitRule instances');
        new RateLimitMiddleware(
            new TieredRateLimiter(new SlidingWindowRateLimiter(new HrTimeClock())),
            // @phpstan-ignore argument.type (deliberately non-rule entry for the runtime guard)
            [new \stdClass()],
        );
    }

    // ------------------------------------------------------------------
    // ConfigProvider wiring
    // ------------------------------------------------------------------

    public function testConfigProviderOmitsTierMiddlewareWithoutTiers(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS');
        $config = new ConfigProvider()->getConfig();
        $services = $config['services'];
        assert(is_array($services));
        $stack = $config['stack'];
        assert(is_array($stack));
        self::assertArrayHasKey('middleware.security.rate_limit', $services);
        self::assertNotContains('middleware.security.rate_limit', $stack);
    }

    public function testConfigProviderInsertsTierMiddlewareWhenTiersConfigured(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode([
            ['name' => 'api', 'limit' => 10, 'windowSeconds' => 60, 'pathPrefix' => '/api'],
        ]));
        $config = new ConfigProvider()->getConfig();
        $stack = $config['stack'];
        assert(is_array($stack));
        $index = array_search('middleware.security.rate_limit', $stack, true);
        self::assertIsInt($index);
        self::assertSame('middleware.security.runtime', $stack[$index - 1], 'joins right after the global runtime security middleware');

        $middleware = $this->tierFactory($config)();
        assert($middleware instanceof RateLimitMiddleware);
        $response = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame('10', $response->getHeaderLine('RateLimit-Limit'));
    }

    public function testConfigProviderBuildsTokenBucketAlgorithm(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode([['name' => 'api', 'limit' => 5, 'windowSeconds' => 10]]));
        putenv('ZEF_SECURITY_RATE_LIMIT_ALGORITHM=token');
        $config = new ConfigProvider()->getConfig();
        $middleware = $this->tierFactory($config)();
        assert($middleware instanceof RateLimitMiddleware);
        $first = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame('5', $first->getHeaderLine('RateLimit-Limit'));
        // Token-bucket signature: a full bucket of 5 minus one hit leaves
        // floor(4) remaining — identical numeric shape to the sliding
        // counter, but the Reset header reports time-to-FULL (10s for a
        // 1-unit deficit at 0.5/s = 2s), unlike the sliding boundary (10s).
        self::assertSame('4', $first->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('2', $first->getHeaderLine('RateLimit-Reset'));
    }

    public function testConfigProviderRejectsMalformedTiers(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS={not-json');
        $config = new ConfigProvider()->getConfig();
        $factory = $this->tierFactory($config);
        $this->expectException(\RuntimeException::class);
        $factory();
    }

    public function testConfigProviderRejectsNonListTiers(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode(['name' => 'api', 'limit' => 5]));
        $config = new ConfigProvider()->getConfig();
        $factory = $this->tierFactory($config);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a JSON list');
        $factory();
    }

    public function testConfigProviderRejectsInvalidTierEntries(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode([['name' => 'api', 'limit' => -5, 'windowSeconds' => 60]]));
        $config = new ConfigProvider()->getConfig();
        $factory = $this->tierFactory($config);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('entry 0 invalid');
        $factory();
    }

    public function testConfigProviderRejectsNonObjectEntries(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT_TIERS=' . json_encode(['nope']));
        $config = new ConfigProvider()->getConfig();
        $factory = $this->tierFactory($config);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('entry 0 must be an object');
        $factory();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<RateLimitRule> $rules
     */
    private function middleware(array $rules, bool $trustIdentityHeader = false): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            new TieredRateLimiter(new SlidingWindowRateLimiter($this->fakeClock())),
            $rules,
            trustIdentityHeader: $trustIdentityHeader,
        );
    }

    /**
     * Deterministic nanosecond clock pinned at t=0 (window-boundary maths
     * become exact: reset/retry equal the full window).
     */
    private function fakeClock(): CacheClockInterface
    {
        return new class implements CacheClockInterface {
            public int $ns = 0;

            #[\Override]
            public function nowUnixNano(): int
            {
                return $this->ns;
            }
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = [], array $server = []): ServerRequestInterface
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']), $server, [], [], [], null, $headers);
    }

    private function apiKey(ServerRequestInterface $request, string $key): ServerRequestInterface
    {
        $withKey = $request->withHeader('X-API-Key', $key);
        if (!$withKey instanceof ServerRequestInterface) {
            throw new \LogicException('withHeader must preserve the request type.');
        }

        return $withKey;
    }

    /**
     * @param array<mixed, mixed> $config
     *
     * @return callable(): mixed
     */
    private function tierFactory(array $config): callable
    {
        $services = $config['services'];
        assert(is_array($services));
        $definition = $services['middleware.security.rate_limit'] ?? null;
        assert(is_array($definition));
        $factory = $definition['factory'] ?? null;
        assert(is_callable($factory));

        return $factory;
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

    private function errorField(ResponseInterface $response, string $field): string
    {
        $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        $value = $decoded[$field] ?? null;
        assert(is_string($value));

        return $value;
    }
}
