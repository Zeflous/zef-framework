<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): demo modules, health module,
 * toko plugin, middleware config factories and rate-limit store fallbacks.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\HealthIndicatorInterface;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\SharedRateLimitStoreInterface;
use Zef\Middleware\ConfigProvider as MiddlewareProvider;
use Zef\Middleware\CorsMiddleware;
use Zef\Module\Core\AboutHandler;
use Zef\Module\Core\ConfigProvider as CoreProvider;
use Zef\Module\Core\HomeHandler;
use Zef\Module\Health\AggregateHealthHandler;
use Zef\Module\Health\CanaryService;
use Zef\Module\Health\ConfigProvider as HealthProvider;
use Zef\Module\Health\ContainerHealthIndicator;
use Zef\Module\Health\LiveHandler;
use Zef\Module\Health\MetricsHandler;
use Zef\Module\Health\ReadyHandler;
use Zef\Plugin\Toko\ConfigProvider as TokoProvider;
use Zef\Plugin\Toko\ProdukDetailHandler;
use Zef\Plugin\Toko\ProdukService;
use Zef\Plugin\Toko\TokoHandler;

/**
 * @internal
 */
final class ModulesPluginsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT');
        putenv('ZEF_RATE_LIMIT_STORE');
        putenv('ZEF_CORS_ORIGIN_ANY');
        putenv('ZEF_CORS_ORIGIN');
    }

    // ------------------------------------------------------------------
    // Module: core
    // ------------------------------------------------------------------

    public function testCoreConfigProviderShape(): void
    {
        $provider = new CoreProvider();
        self::assertSame('core', $provider->getModuleName());
        $config = $provider->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        self::assertArrayHasKey('core.handler.home', $services);
        $routes = $config['routes'];
        self::assertIsArray($routes);
        self::assertIsArray($routes[0]);
        self::assertIsArray($routes[1]);
        self::assertSame('/', $routes[0]['path']);
        self::assertSame('/about', $routes[1]['path']);
    }

    public function testHomeHandlerServesVersionPayload(): void
    {
        $response = new HomeHandler()->handle($this->request());
        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertSame('core', $payload['module']);
        self::assertSame('ZEF Framework v' . ZefVersion::VERSION, $payload['framework']);
        $psr = $payload['psr'];
        self::assertIsArray($psr);
        self::assertTrue($psr['container']);
    }

    public function testAboutHandlerServesLicensePayload(): void
    {
        $response = new AboutHandler()->handle($this->request());
        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertSame('about', $payload['page']);
        self::assertSame('MIT', $payload['license']);
        self::assertSame(PHP_VERSION, $payload['php']);
    }

    // ------------------------------------------------------------------
    // Module: health
    // ------------------------------------------------------------------

    public function testHealthConfigProviderDeclaresServicesAndRoutes(): void
    {
        $provider = new HealthProvider();
        self::assertSame('health', $provider->getModuleName());
        $config = $provider->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        $routes = $config['routes'];
        self::assertIsArray($routes);
        self::assertSame(8, count($services));
        self::assertSame(4, count($routes));
        self::assertIsArray($routes[2]);
        self::assertSame('health.aggregate', $routes[2]['name']);
    }

    public function testHealthProbesRespondWithCacheControl(): void
    {
        $live = new LiveHandler()->handle($this->request('/health/live'));
        self::assertSame(200, $live->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $live->getBody());
        self::assertSame('no-store', $live->getHeaderLine('Cache-Control'));

        $ready = new ReadyHandler()->handle($this->request('/health/ready'));
        self::assertSame(200, $ready->getStatusCode());
        self::assertSame('{"status":"ready"}', (string) $ready->getBody());
    }

    public function testContainerHealthIndicatorReportsUpForResolvableCanary(): void
    {
        $container = new Container();
        $container->register('health.canary', static fn (): CanaryService => new CanaryService(), [], 'test');
        $indicator = new ContainerHealthIndicator($container);
        self::assertSame('container', $indicator->name());
        $result = $indicator->check();
        self::assertSame('up', $result->status());
    }

    public function testContainerHealthIndicatorReportsDownForWrongCanaryType(): void
    {
        $container = new Container();
        $container->register('health.canary', static fn (): \stdClass => new \stdClass(), [], 'test');
        $result = new ContainerHealthIndicator($container)->check();
        self::assertSame('down', $result->status());
        self::assertStringContainsString('unexpected type', $result->message);
    }

    public function testContainerHealthIndicatorReportsDownOnResolutionFailure(): void
    {
        $container = new Container();
        $container->register('health.canary', static fn (): never => throw new \RuntimeException('boom'), [], 'test');
        $result = new ContainerHealthIndicator($container)->check();
        self::assertSame('down', $result->status());
        self::assertStringContainsString('unresolvable', $result->message);
    }

    public function testAggregateHealthHandlerReturnsOkWhenAllIndicatorsUp(): void
    {
        $indicator = new class implements HealthIndicatorInterface {
            #[\Override]
            public function name(): string
            {
                return 'always-up';
            }

            #[\Override]
            public function check(): HealthCheckResult
            {
                return HealthCheckResult::up('fine');
            }
        };
        $response = new AggregateHealthHandler(new HealthAggregator([$indicator]))->handle($this->request('/health'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->decode($response)['status']);
    }

    public function testAggregateHealthHandlerReturns503WhenAnyIndicatorDown(): void
    {
        $indicator = new class implements HealthIndicatorInterface {
            #[\Override]
            public function name(): string
            {
                return 'always-down';
            }

            #[\Override]
            public function check(): HealthCheckResult
            {
                return HealthCheckResult::down('broken');
            }
        };
        $response = new AggregateHealthHandler(new HealthAggregator([$indicator]))->handle($this->request('/health'));
        self::assertSame(503, $response->getStatusCode());
        $summary = $this->decode($response);
        self::assertIsArray($summary['checks']);
        self::assertIsArray($summary['checks'][0]);
        self::assertSame('down', $summary['checks'][0]['status']);
    }

    public function testMetricsHandlerRendersPrometheusBody(): void
    {
        $telemetry = Telemetry::fromEnvironment(null, false);
        $telemetry->meter()->increment('zef.test.metrics.total', 1, ['env' => 'test']);
        $response = new MetricsHandler($telemetry, new PrometheusRenderer())->handle($this->request('/metrics'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('zef_test_metrics_total', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // Plugin: toko
    // ------------------------------------------------------------------

    public function testTokoConfigProviderShape(): void
    {
        $provider = new TokoProvider();
        self::assertSame('toko', $provider->getModuleName());
        $config = $provider->getConfig();
        $services = $config['services'];
        $routes = $config['routes'];
        self::assertIsArray($services);
        self::assertIsArray($routes);
        self::assertSame(3, count($services));
        self::assertIsArray($routes[0]);
        self::assertSame('GET', $routes[0]['method']);
    }

    public function testProdukServiceListsAndFindsProducts(): void
    {
        $service = new ProdukService();
        self::assertSame(3, count($service->all()));
        $item = $service->find(2);
        assert($item !== null);
        self::assertSame('Mouse Wireless', $item['nama']);
        self::assertNull($service->find(999));
    }

    public function testTokoHandlerServesProductIndex(): void
    {
        $response = new TokoHandler(new ProdukService())->handle($this->request('/toko'));
        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertSame('toko', $payload['module']);
        self::assertIsArray($payload['produk']);
        self::assertSame(3, count($payload['produk']));
    }

    public function testProdukDetailHandlerServesExistingProduct(): void
    {
        $handler = new ProdukDetailHandler(new ProdukService());
        $response = $handler->handle($this->request('/toko/1', ['id' => 1]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('detail', $this->decode($response)['page']);
    }

    public function testProdukDetailHandlerServes404ForUnknownProduct(): void
    {
        $handler = new ProdukDetailHandler(new ProdukService());
        $response = $handler->handle($this->request('/toko/99', ['id' => 99]));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Produk #99 tidak ditemukan.', $this->decode($response)['error']);
    }

    public function testMiddlewareConfigProviderShape(): void
    {
        $provider = new MiddlewareProvider();
        self::assertSame('middleware', $provider->getModuleName());
        $config = $provider->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        // 5 core middleware services + middleware.security.rate_limit (v2.25.0),
        // which is always REGISTERED but only joins the stack when tiers are set.
        self::assertSame(6, count($services));
        self::assertArrayHasKey('middleware.security.rate_limit', $services);
        $stack = $config['stack'];
        self::assertIsArray($stack);
        self::assertSame('middleware.error', $stack[0]);
    }

    public function testMiddlewareFactoriesResolveThroughContainer(): void
    {
        $logger = $this->collectingLogger();
        $container = new Container();
        $container->register(LoggerInterface::class, static fn (): LoggerInterface => $logger, [], 'test');

        $config = new MiddlewareProvider()->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);

        foreach (['middleware.error', 'middleware.timing', 'middleware.security'] as $id) {
            self::assertArrayHasKey($id, $services);
            $spec = $services[$id];
            assert(is_array($spec));
            self::assertArrayHasKey('factory', $spec);
            $factory = $spec['factory'];
            assert(is_callable($factory));
            $deps = $spec['deps'] ?? [];
            assert(is_array($deps));
            $container->register($id, static fn (ContainerInterface $c): mixed => $factory($c), $deps, 'test'); // @phpstan-ignore-line
            self::assertNotNull($container->get($id));
        }
    }

    public function testSecurityRuntimeMiddlewareFactoryBuildsInMemoryLimiter(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=memory');
        $middleware = ($this->securityRuntimeFactory())(new Container());
        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
    }

    public function testApcuStoreFallsBackToMemoryWithLoggerWarning(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu present; fallback path not reachable.');
        }
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=apcu');
        $logger = $this->collectingLogger();
        $container = new Container();
        $container->register(LoggerInterface::class, static fn (): LoggerInterface => $logger, [], 'test');
        ($this->securityRuntimeFactory())($container);
        $warnings = $logger->warnings;
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('Rate-limit store "apcu" unavailable', $warnings[count($warnings) - 1]);
    }

    public function testApcuStoreFallbackWritesToErrorLogWithoutLogger(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu present; fallback path not reachable.');
        }
        $logFile = tempnam(sys_get_temp_dir(), 'zeflog');
        assert($logFile !== false);
        $previous = ini_set('error_log', $logFile);
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=apcu');

        try {
            ($this->securityRuntimeFactory())(new Container());
            $contents = (string) file_get_contents($logFile);
            self::assertStringContainsString('[ZEF][security]', $contents);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testRedisStoreFallsBackWhenExtensionMissing(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis present; fail-fast path not reachable.');
        }
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        $middleware = ($this->securityRuntimeFactory())(new Container());
        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
    }

    public function testCorsFactoryVariants(): void
    {
        $config = new MiddlewareProvider()->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        self::assertArrayHasKey('middleware.cors', $services);
        $spec = $services['middleware.cors'];
        assert(is_array($spec));
        $factory = $spec['factory'];
        assert(is_callable($factory));

        putenv('ZEF_CORS_ORIGIN_ANY=1');
        $any = $factory();
        self::assertInstanceOf(CorsMiddleware::class, $any);

        putenv('ZEF_CORS_ORIGIN_ANY=');
        putenv('ZEF_CORS_ORIGIN=https://a.test,https://b.test');
        $csv = $factory();
        self::assertInstanceOf(CorsMiddleware::class, $csv);
    }

    public function testApcuRateLimiterRejectsNonPositiveMaxKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApcuRateLimiter(0);
    }

    public function testApcuRateLimiterRequiresExtension(): void
    {
        if (function_exists('apcu_fetch')) {
            self::markTestSkipped('APCu present; guard path not reachable.');
        }
        $this->expectException(\RuntimeException::class);
        new ApcuRateLimiter(10);
    }

    public function testRedisRateLimiterRejectsNonPositiveMaxKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisRateLimiter($this->fakeStore(0), 0);
    }

    public function testRedisRateLimiterGuardsKeyAndWindowArguments(): void
    {
        $limiter = new RedisRateLimiter($this->fakeStore(0), 5);
        $this->expectException(\InvalidArgumentException::class);
        $limiter->check('', 5, 60);
    }

    public function testRedisRateLimiterCountsAgainstLimit(): void
    {
        $limiter = new RedisRateLimiter($this->fakeStore(1), 5);
        $decision = $limiter->check('ip:1', 3, 60);
        self::assertInstanceOf(RateLimitDecision::class, $decision);
        self::assertTrue($decision->allowed);
        self::assertSame(3, $decision->limit);
        self::assertSame(2, $decision->remaining);
        self::assertGreaterThanOrEqual(1, $decision->retryAfter);
        $blocked = new RedisRateLimiter($this->fakeStore(5), 5)->check('ip:1', 1, 60);
        self::assertFalse($blocked->allowed);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(string $path = '/', array $attributes = []): ServerRequestInterface
    {
        $request = new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']));

        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }

    // ------------------------------------------------------------------
    // Middleware ConfigProvider (Zef\Middleware)
    // ------------------------------------------------------------------

    private function collectingLogger(): CollectingLogger
    {
        return new CollectingLogger();
    }

    private function securityRuntimeFactory(): callable
    {
        $config = new MiddlewareProvider()->getConfig();
        $services = $config['services'];
        self::assertIsArray($services);
        $spec = $services['middleware.security.runtime'];
        self::assertIsArray($spec);
        $factory = $spec['factory'];
        assert(is_callable($factory));

        return $factory;
    }

    private function fakeStore(int $count): SharedRateLimitStoreInterface
    {
        return new readonly class($count) implements SharedRateLimitStoreInterface {
            public function __construct(private int $initialCount) {}

            #[\Override]
            public function increment(string $key, int $windowSeconds, int $now): array
            {
                return ['count' => $this->initialCount, 'reset' => $now + $windowSeconds];
            }

            #[\Override]
            public function peek(string $key, int $windowSeconds, int $now): ?array
            {
                return null;
            }
        };
    }
}
