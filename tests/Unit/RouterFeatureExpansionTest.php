<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — router feature-expansion + audit-fix suite.
 *
 * Covers the five audited findings (per-route middleware execution, route:list
 * --json, radix recompile skip, empty route-name index, and the four roadmap
 * expansion features: subdomain routing, model binding, localization routing,
 * content negotiation) with positive AND negative probes.
 */

namespace Zef\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Inspector\RouteLister;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Http\Response;
use Zef\Framework\Router\ContentNegotiator;
use Zef\Framework\Router\HostPatternMatches;
use Zef\Framework\Router\LocaleNegotiator;
use Zef\Framework\Router\RouteModelBinderInterface;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\RouteRadixIndex;
use Zef\Framework\Router\UrlGenerator;

/**
 * @internal
 */
final class RouterFeatureExpansionTest extends TestCase
{
    // ------------------------------------------------------------------
    // Finding P0-1 — per-route middleware is actually EXECUTED (was bypassed)
    // ------------------------------------------------------------------

    public function testRouteGroupMiddlewareRunsAroundTheHandler(): void
    {
        $app = new Application();
        $app->setTrustedHosts(['example.com']);
        $app->getContainer()->register('mw.stamp', static fn (): MiddlewareInterface => new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                $headers = $response->getHeaders();
                $headers['X-Route-MW'] = ['ran'];

                return new Response($response->getStatusCode(), $headers, (string) $response->getBody());
            }
        });
        $app->getContainer()->register('probe.handler', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/plain'], 'ok');
            }
        });
        $app->getRouter()->group(['middleware' => ['mw.stamp']], static function (Router $router): void {
            $router->add('GET', '/with-mw', 'probe.handler');
        });
        $app->getRouter()->add('GET', '/without-mw', 'probe.handler');

        $withMw = $app->handle(new ServerRequest('GET', 'http://example.com/with-mw'));
        self::assertSame(200, $withMw->getStatusCode());
        // The regression: before the fix this header was NEVER set because the
        // route's middleware metadata was recorded but never executed.
        self::assertSame('ran', $withMw->getHeaderLine('X-Route-MW'));

        $withoutMw = $app->handle(new ServerRequest('GET', 'http://example.com/without-mw'));
        self::assertSame(200, $withoutMw->getStatusCode());
        self::assertFalse($withoutMw->hasHeader('X-Route-MW'), 'A route with no middleware must not run one.');
    }

    public function testRouteMiddlewareFailsClosedWhenServiceMissing(): void
    {
        $app = new Application();
        $app->setTrustedHosts(['example.com']);
        $app->getContainer()->register('probe.handler', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/plain'], 'ok');
            }
        });
        $app->getRouter()->group(['middleware' => ['mw.does_not_exist']], static function (Router $router): void {
            $router->add('GET', '/boom', 'probe.handler');
        });

        $this->expectException(InvalidConfigurationException::class);
        $app->handle(new ServerRequest('GET', 'http://example.com/boom'));
    }

    // ------------------------------------------------------------------
    // Finding P0-2 — route:list --json + middleware/host columns
    // ------------------------------------------------------------------

    public function testRouteListJsonExposesMiddlewareHostBindingsAccepts(): void
    {
        $router = new Router();
        $router->group(
            ['middleware' => ['mw.a'], 'host' => '{tenant}.example.com', 'bindings' => ['id' => 'binder.x'], 'accepts' => ['application/json']],
            static function (Router $r): void {
                $r->add('GET', '/dash/{id}', 'h.dash', null, 0, 'dash');
            },
        );
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            self::fail('Cannot open a temp stream for route:list output.');
        }
        new RouteLister(new ConsoleIO($stream, $stream))->run($router->getRoutes(), true);
        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "route:list --json must emit a JSON array; got: {$raw}");
        $first = $decoded[0] ?? null;
        self::assertIsArray($first);
        foreach (['method', 'path', 'name', 'handler', 'module', 'priority', 'middleware', 'host', 'bindings', 'accepts'] as $key) {
            self::assertArrayHasKey($key, $first);
        }
        self::assertSame(['mw.a'], $first['middleware']);
        self::assertSame('{tenant}.example.com', $first['host']);
    }

    public function testGetRoutesRecordCarriesRouterMetadata(): void
    {
        $router = new Router();
        $router->group(
            ['host' => '{tenant}.example.com', 'middleware' => ['mw.a'], 'accepts' => ['application/json'], 'bindings' => ['id' => 'binder.x']],
            static function (Router $r): void {
                $r->add('GET', '/dash/{id}', 'h.dash', null, 0, 'dash');
            },
        );
        $routes = $router->getRoutes();
        self::assertCount(1, $routes);
        self::assertSame(['mw.a'], $routes[0]['middleware']);
        self::assertSame('{tenant}.example.com', $routes[0]['host']);
        self::assertSame(['id' => 'binder.x'], $routes[0]['bindings']);
        self::assertSame(['application/json'], $routes[0]['accepts']);
    }

    // ------------------------------------------------------------------
    // Finding P2 — empty group name never lands in the name index
    // ------------------------------------------------------------------

    public function testUnnamedRouteFromEmptyNameGroupIsNotIndexed(): void
    {
        $router = new Router();
        $router->group(['name' => ''], static function (Router $r): void {
            $r->add('GET', '/a', 'h.a');
        });
        $router->add('GET', '/b', 'h.b', null, 0, '');
        // Neither route has a usable name — the empty string must NOT be a key.
        self::assertFalse($router->hasRouteName(''));
        self::assertSame([], $router->routeNames());
        $this->expectException(\InvalidArgumentException::class);
        $router->patternFor('');
    }

    public function testNamedRouteStillResolvesAfterEmptyNameGroup(): void
    {
        $router = new Router();
        $router->group(['name' => ''], static function (Router $r): void {
            $r->add('GET', '/a', 'h.a', null, 0, 'named');
        });
        self::assertTrue($router->hasRouteName('named'));
        self::assertSame('/a', $router->patternFor('named'));
        self::assertNotNull($router->routeRecordFor('named'));
    }

    // ------------------------------------------------------------------
    // Finding P2 — unfrozen match does not rebuild when nothing changed
    // ------------------------------------------------------------------

    public function testRadixIndexSkipsRecompileWhenRevisionUnchanged(): void
    {
        $router = new Router();
        $router->add('GET', '/one', 'h.one');
        $router->add('GET', '/two', 'h.two');

        // Two unfrozen matches: the first compiles, the second must reuse it.
        self::assertSame('h.one', $router->match('GET', '/one')['handler']);
        $index = $this->radixIndexOf($router);
        $firstCompiled = $this->compiledRevision($index);

        self::assertSame('h.two', $router->match('GET', '/two')['handler']);
        self::assertSame($firstCompiled, $this->compiledRevision($index), 'No registration happened, so no recompile.');

        // A new registration bumps the revision and forces a rebuild.
        $router->add('GET', '/three', 'h.three');
        self::assertSame('h.three', $router->match('GET', '/three')['handler']);
        self::assertGreaterThan($firstCompiled, $this->compiledRevision($index));
    }

    // ------------------------------------------------------------------
    // Roadmap R1 — subdomain routing (multi-tenancy)
    // ------------------------------------------------------------------

    public function testSubdomainRoutingCapturesTenant(): void
    {
        $router = new Router();
        $router->group(['host' => '{tenant}.example.com'], static function (Router $r): void {
            $r->add('GET', '/dashboard', 'h.dash');
        });

        $hit = $router->match('GET', '/dashboard', 'acme.example.com');
        self::assertSame('h.dash', $hit['handler']);
        self::assertSame('acme', $hit['params']['tenant']);
        self::assertSame('{tenant}.example.com', $hit['host']);
    }

    public function testHostRouteAndHostlessRouteCoexistOnSamePath(): void
    {
        $router = new Router();
        $router->group(['host' => '{tenant}.example.com'], static function (Router $r): void {
            $r->add('GET', '/dash', 'h.tenant');
        });
        // Same method+path, no host — was previously a duplicate-signature clash.
        $router->add('GET', '/dash', 'h.root');

        self::assertSame('h.tenant', $router->match('GET', '/dash', 'acme.example.com')['handler']);
        self::assertSame('h.root', $router->match('GET', '/dash', 'example.com')['handler']);
    }

    public function testHostMismatchIsNotFound(): void
    {
        $router = new Router();
        $router->group(['host' => '{tenant}.example.com'], static function (Router $r): void {
            $r->add('GET', '/dashboard', 'h.dash');
        });

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/dashboard', 'example.com');
    }

    public function testConflictingNestedHostFailsClosed(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $router->group(['host' => '{tenant}.example.com'], static function (Router $r): void {
            $r->group(['host' => 'api.other.org'], static function (Router $inner): void {
                $inner->add('GET', '/x', 'h.x');
            });
        });
    }

    public function testHostPatternMatcherGrammar(): void
    {
        self::assertSame(['tenant' => 'acme'], HostPatternMatches::match('{tenant}.example.com', 'ACME.Example.COM'));
        self::assertSame([], HostPatternMatches::match('*.example.com', 'acme.example.com'));
        self::assertSame(['t' => 'a'], HostPatternMatches::match('{t}.example.com', 'a.example.com:8443'));
        self::assertNull(HostPatternMatches::match('{tenant}.example.com', 'a.b.example.com'));
        self::assertNull(HostPatternMatches::match('api.example.com', 'www.example.com'));
        self::assertSame(['tenant'], HostPatternMatches::wildcardNames('{tenant}.example.com'));

        $this->expectException(\InvalidArgumentException::class);
        HostPatternMatches::assertValidPattern('api-{tenant}.example.com');
    }

    public function testUrlGeneratorBuildsHostFromWildcards(): void
    {
        $router = new Router();
        $router->group(['host' => '{tenant}.example.com'], static function (Router $r): void {
            $r->add('GET', '/d/{id}', 'h.d', null, 0, 'dash');
        });
        $generator = new UrlGenerator($router);
        self::assertSame('acme.example.com', $generator->generateHost('dash', ['tenant' => 'acme']));

        $this->expectException(\InvalidArgumentException::class);
        $generator->generateHost('dash');
    }

    // ------------------------------------------------------------------
    // Roadmap R2 — route model binding
    // ------------------------------------------------------------------

    public function testRouteModelBindingReplacesTheRawParameter(): void
    {
        $app = new Application();
        $app->setTrustedHosts(['example.com']);
        $app->getContainer()->register('binder.user', static fn (): RouteModelBinderInterface => new class implements RouteModelBinderInterface {
            public function resolve(string $value, ServerRequestInterface $request): mixed
            {
                return 'user#' . $value;
            }
        });
        $app->getContainer()->register('bind.handler', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $value = $request->getAttribute('id');

                return new Response(200, ['Content-Type' => 'text/plain'], is_scalar($value) ? (string) $value : '');
            }
        });
        $app->getRouter()->group(['bindings' => ['id' => 'binder.user']], static function (Router $r): void {
            $r->add('GET', '/users/{id}', 'bind.handler');
        });

        $response = $app->handle(new ServerRequest('GET', 'http://example.com/users/42'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user#42', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // Roadmap R3 — localization routing
    // ------------------------------------------------------------------

    public function testLocalizedRoutesRegisterUnderLocalePrefix(): void
    {
        $router = new Router();
        $router->localized(['en', 'id'], 'en', static function (Router $r): void {
            $r->add('GET', '/about', 'h.about', null, 0, 'en.about');
        });

        $hit = $router->match('GET', '/en/about');
        self::assertSame('h.about', $hit['handler']);
        self::assertSame('/en/about', $hit['pattern']);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/fr/about');
    }

    public function testLocalizedRejectsUnsupportedLocale(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $router->localized(['en'], 'de', static function (Router $r): void {
            $r->add('GET', '/x', 'h.x');
        });
    }

    public function testLocaleNegotiatorSplitsWellFormedPrefix(): void
    {
        $negotiator = new LocaleNegotiator(['en', 'id']);
        self::assertSame(['en', '/users'], $negotiator->splitPathPrefix('/en/users'));
        self::assertSame(['id', '/'], $negotiator->splitPathPrefix('/id'));
        // A plain path word is never hijacked as a locale.
        self::assertSame([null, '/'], $negotiator->splitPathPrefix('/'));
        self::assertSame([null, '/123'], $negotiator->splitPathPrefix('/123'));
    }

    // ------------------------------------------------------------------
    // Roadmap R4 — content negotiation routing
    // ------------------------------------------------------------------

    public function testContentNegotiationAnswers406WhenUnacceptable(): void
    {
        $app = new Application();
        $app->setTrustedHosts(['example.com']);
        $app->getContainer()->register('neg.handler', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{}');
            }
        });
        $app->getRouter()->group(['accepts' => ['application/json']], static function (Router $r): void {
            $r->add('GET', '/neg', 'neg.handler');
        });

        $ok = $app->handle(new ServerRequest('GET', 'http://example.com/neg', ['Accept' => 'application/json']));
        self::assertSame(200, $ok->getStatusCode());

        $notAcceptable = $app->handle(new ServerRequest('GET', 'http://example.com/neg', ['Accept' => 'text/html']));
        self::assertSame(406, $notAcceptable->getStatusCode());
    }

    public function testContentNegotiatorGrammar(): void
    {
        self::assertTrue(ContentNegotiator::matches('application/json', ['application/json']));
        self::assertTrue(ContentNegotiator::matches('application/*', ['application/json']));
        self::assertTrue(ContentNegotiator::matches('*/*', ['application/json']));
        self::assertTrue(ContentNegotiator::matches('', ['application/json']));
        self::assertFalse(ContentNegotiator::matches('text/html', ['application/json']));
        self::assertFalse(ContentNegotiator::matches('application/json;q=0', ['application/json']));
        self::assertSame('application/json', ContentNegotiator::select('application/json', ['application/json', 'text/html']));
        self::assertNull(ContentNegotiator::select('text/html', ['application/json']));
    }

    // ------------------------------------------------------------------

    private function radixIndexOf(Router $router): RouteRadixIndex
    {
        $prop = new \ReflectionProperty(Router::class, 'radix');
        $index = $prop->getValue($router);
        self::assertInstanceOf(RouteRadixIndex::class, $index);

        return $index;
    }

    private function compiledRevision(RouteRadixIndex $index): int
    {
        $prop = new \ReflectionProperty(RouteRadixIndex::class, 'compiledRevision');
        $value = $prop->getValue($index);

        return is_int($value) ? $value : -1;
    }
}
