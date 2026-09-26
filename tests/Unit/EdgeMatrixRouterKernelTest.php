<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — fase 4 (Router/Kernel): adversarial tests distilled
 * from escaped mutants in chunks adapters-router-kernel (baseline 260
 * escapes, MSI 65).
 *
 * Curriculum: route-pattern grammar, canonical-signature collisions, radix
 * edge separation, HEAD→GET fallback ordering, constraint-vs-405 semantics,
 * compiled-cache sanitization, URL generation strictness, kernel lifecycle
 * guards, telemetry span/meter contracts, and emitter body admission.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\ResponseEmitter;
use Zef\Framework\Router\RouteCache;
use Zef\Framework\Router\RouteDefinition;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\UrlGenerator;

/**
 * @internal
 */
final class EdgeRouterKernelHandler implements RequestHandlerInterface
{
    /** @param \Closure(ServerRequestInterface): ResponseInterface $factory */
    public function __construct(private readonly \Closure $factory) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->factory)($request);
    }
}

/**
 * @internal
 */
final class EdgeRouterKernelRecordingMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private readonly string $tag = 'mw') {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->seen[] = 'enter:' . $this->tag;
        $response = $handler->handle($request);
        $this->seen[] = 'exit:' . $this->tag;

        return $response;
    }
}

/**
 * @internal
 */
final class EdgeMatrixRouterKernelTest extends TestCase
{
    // ------------------------------------------------------------------
    // Router: pattern grammar and signatures
    // ------------------------------------------------------------------

    public function testRoutePatternGrammarRejectsMalformedPlaceholders(): void
    {
        $router = new Router();

        foreach (['/{a}{b}', '/{id}x', '/x{id}', '/{1a}', '/{}', '/{a:1b}'] as $bad) {
            try {
                $router->add('GET', $bad, 'h');
                self::fail("Pattern '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid route segment', $e->getMessage(), "Pattern '{$bad}'");
            }
        }
    }

    public function testDuplicateRouteReportsTheCanonicalSignature(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id:int}', 'h1');

        try {
            $router->add('GET', '/users/{id:int}', 'h2');
            self::fail('A duplicate canonical signature must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Duplicate/unreachable route [GET] /users/{id:int}', $e->getMessage());
            self::assertStringContainsString('it collides with /users/{id:int}', $e->getMessage());
        }

        // Same shape, different constraint → different signature → allowed.
        $router->add('GET', '/users/{id:slug}', 'h3');
        $routes = $router->getRoutes();
        $first = $routes[0] ?? null;
        $second = $routes[1] ?? null;
        assert(is_array($first) && is_array($second));
        $firstSeq = $first['sequence'] ?? 0;
        $secondSeq = $second['sequence'] ?? 0;
        assert(is_int($firstSeq) && is_int($secondSeq));
        self::assertSame(1, $secondSeq - $firstSeq);
    }

    public function testDynamicLeadingRouteRequiresAnExactStaticTail(): void
    {
        $router = new Router();
        $router->add('GET', '/{a}/b', 'h');

        $hit = $router->match('GET', '/zz/b');
        self::assertSame(['a' => 'zz'], $hit['params']);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/zz/c');
    }

    public function testConstrainedAndUnconstrainedSiblingsOwnSeparateRadixEdges(): void
    {
        $router = new Router();
        $router->add('GET', '/item/{v}', 'h-any');
        $router->add('GET', '/item/{v:int}', 'h-int');

        self::assertSame('h-int', $router->match('GET', '/item/42')['handler']);
        self::assertSame('h-any', $router->match('GET', '/item/zzz')['handler']);
    }

    public function testHeadFallsBackToGetEvenWhenPostRouteSortsFirst(): void
    {
        $router = new Router();
        $router->add('POST', '/x', 'h-post', priority: 5);
        $router->add('GET', '/x', 'h-get', priority: 1);

        self::assertSame('h-get', $router->match('HEAD', '/x')['handler'], 'HEAD must ride the GET route regardless of sort order');
    }

    public function testMethodNotAllowedCarriesTheExactAllowList(): void
    {
        $router = new Router();
        $router->add('GET', '/x', 'h');
        $router->add('POST', '/x', 'h');

        try {
            $router->match('DELETE', '/x');
            self::fail('DELETE on a GET/POST route must raise 405 semantics.');
        } catch (MethodNotAllowedException $e) {
            self::assertSame('DELETE', $e->method);
            self::assertSame('/x', $e->path);
            self::assertSame(['GET', 'HEAD', 'POST'], $e->allowedMethods, 'HEAD is implied by GET');
        }
    }

    public function testConstraintFailureTakesPrecedenceOverMethodNotAllowed(): void
    {
        $router = new Router();
        $router->add('GET', '/u/{id:int}', 'h');

        try {
            $router->match('GET', '/u/notanint');
            self::fail('A shape-matching path with a violated constraint must report 400 semantics.');
        } catch (RouteConstraintException $e) {
            self::assertSame('id', $e->param);
            self::assertSame('int', $e->type);
            self::assertSame('notanint', $e->value);
        }

        // A method that matches no route keeps plain 405 semantics.
        try {
            $router->match('POST', '/u/notanint');
            self::fail('POST on a GET-only route must raise 405 semantics.');
        } catch (RouteConstraintException) {
            self::fail('A mismatching method must not surface as a constraint failure.');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD'], $e->allowedMethods);
        }
    }

    public function testGroupPriorityShiftsConstraintPrecedence(): void
    {
        $plain = new Router();
        $plain->add('GET', '/n/{v:int}', 'h-int');
        $plain->add('GET', '/n/{v}', 'h-any', priority: 5);
        self::assertSame('h-any', $plain->match('GET', '/n/7')['handler'], 'Without a group boost the later-registered loose route wins by priority');

        $grouped = new Router();
        $grouped->group(['priority' => 5], static function (Router $r): void {
            $r->add('GET', '/n/{v:int}', 'h-int');
        });
        $grouped->add('GET', '/n/{v}', 'h-any', priority: 5);
        self::assertSame('h-int', $grouped->match('GET', '/n/7')['handler'], 'Group priority must add to the route priority');
    }

    public function testGroupNamePrefixMergesAndUnnamedRoutesStayUnnamed(): void
    {
        $router = new Router();
        $router->group(['name' => 'api.', 'prefix' => '/api'], static function (Router $r): void {
            $r->add('GET', '/users', 'h', name: 'users');
            $r->add('GET', '/ping', 'h2'); // unnamed on purpose
        });

        self::assertSame('/api/users', $router->patternFor('api.users'));
        self::assertSame(['api.users' => '/api/users'], $router->routeNames(), 'An unnamed route must not invent a prefixed name entry');
    }

    public function testDuplicateRouteNameReportsTheOwner(): void
    {
        $router = new Router();
        $router->add('GET', '/a', 'h1', name: 'same');

        try {
            $router->add('GET', '/b', 'h2', name: 'same');
            self::fail('A duplicate route name must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Duplicate route name 'same'; already bound to /a", $e->getMessage());
        }
    }

    public function testRouteBudgetBoundaryIsEnforcedExactly(): void
    {
        $router = new Router();
        $router->setMaxRoutesBudget(2);
        $router->add('GET', '/a', 'h');
        $router->add('GET', '/b', 'h');

        try {
            $router->add('GET', '/c', 'h');
            self::fail('The third registration must exceed the budget of two.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('maximum 2 route registrations', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Router: compiled restore sanitization
    // ------------------------------------------------------------------

    public function testFromCompiledArrayRejectsMissingRoutesList(): void
    {
        try {
            Router::fromCompiledArray(['signatureIndex' => []]);
            self::fail('Compiled data without a routes list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Compiled route data is missing the routes list.', $e->getMessage());
        }
    }

    public function testFromCompiledArraySanitizesForeignFields(): void
    {
        $source = new Router();
        $source->add('GET', '/n/{v:int}', 'h-int', priority: 3, name: 'named');
        $source->fallback('edge.fallback');
        $data = $source->exportRoutes();

        $router = Router::fromCompiledArray([
            'routes' => $data['routes'],
            'signatureIndex' => $data['signatureIndex'],
            'nameIndex' => ['junk-key'],
            'constraints' => ['bad' => 42, 'ok' => '/^[0-9]+$/'],
            'sequence' => '9',
            'fallback' => '',
            'maxRoutesBudget' => 0,
        ]);

        // Dispatch still works through the restored radix index.
        self::assertSame('h-int', $router->match('GET', '/n/5')['handler']);
        self::assertSame(1, $router->getMaxRoutesBudget(), 'A zero budget must clamp up to one');
        self::assertFalse($router->hasFallback(), 'An empty fallback string must become null');
        $this->expectException(RouteConstraintException::class);
        $router->match('GET', '/n/other');
    }

    public function testFromCompiledArrayRestoresFallbackAndConstraints(): void
    {
        $source = new Router();
        $source->addConstraint('even', '/^[0-9]*[02468]$/');
        $source->add('GET', '/n/{v:even}', 'h-even');
        $source->fallback('edge.fallback');
        $data = $source->exportRoutes();

        $router = Router::fromCompiledArray($data);
        self::assertTrue($router->hasFallback());
        $hit = $router->matchOrFallback('GET', '/n/4');
        self::assertSame('h-even', $hit['handler']);
        self::assertFalse($hit['fallback']);

        $miss = $router->matchOrFallback('GET', '/elsewhere');
        self::assertSame('edge.fallback', $miss['handler']);
        self::assertTrue($miss['fallback'], 'The fallback only covers shape-less 404 paths');

        try {
            $router->matchOrFallback('GET', '/n/5');
            self::fail('A constraint violation must keep 400 semantics (no fallback).');
        } catch (RouteConstraintException $e) {
            self::assertSame('v', $e->param);
        }

        try {
            $router->matchOrFallback('POST', '/n/4');
            self::fail('The fallback must not swallow 405 semantics.');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD'], $e->allowedMethods);
        }
    }

    // ------------------------------------------------------------------
    // RouteCache: atomic export/load lifecycle
    // ------------------------------------------------------------------

    public function testRouteCacheRoundTripPreservesRoutesNamesAndConstraints(): void
    {
        $dir = (string) tempnam(sys_get_temp_dir(), 'zefrc');
        unlink($dir); // nosemgrep: php.lang.security.unlink-use
        self::assertTrue(mkdir($dir, 0o777, true));
        $path = $dir . '/routes.cache.php';

        $router = new Router();
        $router->add('GET', '/f/{v:hex}', 'h-hex', priority: 2, name: 'files');
        $router->add('POST', '/submit', 'h-post');
        RouteCache::write($router, $path);

        self::assertFileExists($path);
        $restored = RouteCache::load($path);
        self::assertSame('h-hex', $restored->match('GET', '/f/deadbeef')['handler']);
        self::assertSame('/f/{v:hex}', $restored->patternFor('files'));
        self::assertSame('h-post', $restored->match('POST', '/submit')['handler']);

        $leftovers = glob($dir . '/*');
        if ($leftovers !== false) {
            foreach ($leftovers as $leftover) {
                unlink($leftover); // nosemgrep: php.lang.security.unlink-use
            }
        }
        rmdir($dir);
    }

    public function testRouteCacheLoadAndWriteGuards(): void
    {
        $path = sys_get_temp_dir() . '/zef-missing-' . bin2hex(random_bytes(4)) . '.php';

        try {
            RouteCache::load($path);
            self::fail('A missing cache file must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }

        $nonArray = (string) tempnam(sys_get_temp_dir(), 'zefrc');
        file_put_contents($nonArray, "<?php\n\nreturn 42;\n");

        try {
            RouteCache::load($nonArray);
            self::fail('A cache file that does not return an array must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('did not return an array', $e->getMessage());
        }
        unlink($nonArray); // nosemgrep: php.lang.security.unlink-use

        $blocked = (string) tempnam(sys_get_temp_dir(), 'zefrc');
        $path = $blocked . '/sub/cache.php'; // parent is a FILE, not a directory
        set_error_handler(static function (int $severity, string $message): bool {
            return true; // mkdir() warns before it fails; the guard throws instead
        });
        $threw = null;

        try {
            RouteCache::write(new Router(), $path);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            restore_error_handler();
        }
        self::assertNotNull($threw, 'A file in place of the cache directory must fail the write.');
        self::assertStringContainsString('Cannot create route-cache directory', $threw->getMessage());
    }

    // ------------------------------------------------------------------
    // RouteDefinition: registration guards and fromArray coercion
    // ------------------------------------------------------------------

    public function testRouteDefinitionGuards(): void
    {
        $route = new RouteDefinition('  get ', '/x', 'h', name: ' n1 ');
        self::assertSame('GET', $route->method);
        self::assertSame('n1', $route->name);

        foreach ([
            "path ''" => ['', 'h', null],
            'path without slash' => ['x', 'h', null],
            'empty handler' => ['/x', '', null],
        ] as $label => [$path, $handler, $name]) {
            try {
                new RouteDefinition('GET', $path, $handler, name: $name);
                self::fail("RouteDefinition ({$label}) must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertTrue($e->getMessage() !== '');
            }
        }

        foreach (['a b', 'x/', str_repeat('y', 129)] as $badName) {
            try {
                new RouteDefinition('GET', '/x', 'h', name: $badName);
                self::fail("Route name '{$badName}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid route name', $e->getMessage());
            }
        }
    }

    public function testRouteDefinitionFromArrayCoercesAndRejects(): void
    {
        $route = RouteDefinition::fromArray(['method' => 'post', 'path' => '/p', 'handler' => 'h', 'priority' => '7.5', 'name' => 'n']);
        self::assertSame('POST', $route->method);
        self::assertSame(7, $route->priority);

        self::assertSame('GET', RouteDefinition::fromArray(['path' => '/d', 'handler' => 'h'])->method);
        self::assertSame('/', RouteDefinition::fromArray(['handler' => 'h'])->path);

        try {
            RouteDefinition::fromArray(['method' => ['GET'], 'path' => '/p', 'handler' => 'h']);
            self::fail('A non-string method must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Route definition method, path, and handler must be strings.', $e->getMessage());
        }

        foreach (['abc', true, []] as $badPriority) {
            try {
                RouteDefinition::fromArray(['path' => '/p', 'handler' => 'h', 'priority' => $badPriority]);
                self::fail('A non-numeric priority must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Route definition priority must be numeric.', $e->getMessage());
            }
        }

        try {
            RouteDefinition::fromArray(['path' => '/p', 'handler' => 'h', 'name' => 9]);
            self::fail('A non-string name must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Route definition name must be a string or null.', $e->getMessage());
        }
    }

    public function testUrlGeneratorHappyPathsEncodeAndValidate(): void
    {
        $gen = $this->generator();

        self::assertSame('/static', $gen->generate('static'));
        self::assertSame('/f/deadbeef', $gen->generate('files', ['v' => 'deadbeef']));
        self::assertSame('/f/ff', $gen->generate('files', ['v' => 'ff']));
        self::assertSame('/g/a%20b', $gen->generate('generic', ['v' => 'a b']), 'Values are rawurlencoded');
        self::assertSame('/g/17', $gen->generate('generic', ['v' => 17]), 'Scalar ints are stringified');

        $stringable = new readonly class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'str';
            }
        };
        self::assertSame('/g/str', $gen->generate('generic', ['v' => $stringable]));
    }

    public function testUrlGeneratorRejectsMissingUnknownExtraAndViolatingParams(): void
    {
        $gen = $this->generator();

        try {
            $gen->generate('files');
            self::fail('A missing parameter must be reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Route 'files' requires parameter 'v'.", $e->getMessage());
        }

        try {
            $gen->generate('static', ['extra' => 1]);
            self::fail('An extra parameter must be reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Route 'static' does not accept parameter 'extra'.", $e->getMessage());
        }

        try {
            $gen->generate('files', ['v' => 'zzz']);
            self::fail('A constraint violation must raise the router exception.');
        } catch (RouteConstraintException $e) {
            self::assertSame('v', $e->param);
        }

        try {
            // @phpstan-ignore-next-line (intentional type violation under test)
            $gen->generate('generic', ['v' => ['array']]);
            self::fail('A non-scalar parameter must be reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be scalar or Stringable, got array', $e->getMessage());
        }

        try {
            $gen->generate('nope');
            self::fail('An unknown route name must be reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unknown route name 'nope'", $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Kernel: lifecycle guards
    // ------------------------------------------------------------------

    public function testKernelFreezesConfigurationAfterBoot(): void
    {
        $app = $this->booted(configure: static function (Application $a): void {
            $a->setTrustedHosts(['localhost']);
            $a->setTrustedProxies(['10.0.0.1', '', 7]);
        });

        self::assertSame(['localhost'], $app->getTrustedHosts());
        self::assertTrue($app->isBooted());

        try {
            $app->addProvider(new readonly class implements ConfigProviderInterface {
                #[\Override]
                public function getModuleName(): string
                {
                    return 'late';
                }

                /**
                 * @return array<string, mixed>
                 */
                #[\Override]
                public function getConfig(): array
                {
                    return [];
                }
            });
            self::fail('addProvider after boot must be frozen.');
        } catch (\LogicException $e) {
            self::assertSame('Cannot add provider after boot.', $e->getMessage());
        }

        foreach ([
            'addModule after boot' => static fn () => $app->addModule(new readonly class implements ModuleInterface {
                #[\Override]
                public function getName(): string
                {
                    return 'late-module';
                }

                #[\Override]
                public function getDefinition(): ModuleDefinition
                {
                    return new ModuleDefinition('late-module');
                }

                #[\Override]
                public function register(ModuleContext $context): void {}

                #[\Override]
                public function boot(ModuleContext $context): void {}

                #[\Override]
                public function start(ModuleContext $context): void {}

                #[\Override]
                public function shutdown(ModuleContext $context): void {}
            }),
            'setTrustedHosts after boot' => static fn () => $app->setTrustedHosts(['x.example']),
            'setTrustedProxies after boot' => static fn () => $app->setTrustedProxies(['10.0.0.9']),
        ] as $label => $call) {
            try {
                $call();
                self::fail("{$label} must be frozen.");
            } catch (\LogicException $e) {
                self::assertStringContainsString('after boot', $e->getMessage());
            }
        }
    }

    public function testShutdownIsIdempotentAndTerminal(): void
    {
        $app = $this->booted();

        $app->shutdown();
        $app->shutdown(); // idempotent

        try {
            $app->handle($this->request());
            self::fail('handle() after shutdown must be rejected.');
        } catch (\LogicException $e) {
            self::assertSame('Application has already been shut down.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Kernel: request handling pipeline
    // ------------------------------------------------------------------

    public function testTrustedProxyListIsSanitizedAndAttachedToRequests(): void
    {
        $captured = null;
        $app = $this->booted(handlers: [
            'edge.svc' => static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
                $captured = $r->getAttribute('__zef_trusted_proxies');

                return new Response(200, [], 'ok');
            },
        ], configure: static function (Application $a): void {
            $a->setTrustedProxies(['10.0.0.1', '', 7, '0']);
        });

        $app->handle($this->request());

        self::assertSame(['10.0.0.1', '7', '0'], $captured, 'Empty strings are filtered, scalars are strval()-ed, list is re-indexed');
    }

    public function testHandleGlobalsMapsMalformedHostTo400WithDebugMessage(): void
    {
        $debug = $this->booted(debug: true);
        $quiet = $this->booted(debug: false);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/edge/ok';
        $_SERVER['HTTP_HOST'] = 'example.com:8080:9';

        try {
            $response = $debug->handleGlobals();
            self::assertSame(400, $response->getStatusCode());
            $body = $this->jsonBody($response);
            self::assertSame('Bad Request', $body['error']);
            self::assertSame('Malformed Host header.', $body['message'], 'Debug mode surfaces the underlying reason');

            $response = $quiet->handleGlobals();
            self::assertSame(400, $response->getStatusCode());
            $body = $this->jsonBody($response);
            self::assertSame('Invalid request.', $body['message'], 'Non-debug mode hides the underlying reason');
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function testHandleGlobalsMapsOversizedBodyTo413(): void
    {
        $app = $this->booted();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/edge/ok';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_CONTENT_LENGTH'] = (string) (RequestBodyPolicy::DEFAULT_MAX_BYTES + 1);

        try {
            $response = $app->handleGlobals();
            self::assertSame(413, $response->getStatusCode());
            $body = $this->jsonBody($response);
            self::assertSame('Content Too Large', $body['error']);
        } finally {
            unset($_SERVER['HTTP_CONTENT_LENGTH'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST']);
        }
    }

    public function testHeadRequestsReturnAnEmptyBody(): void
    {
        $app = $this->booted(routes: [['method' => 'GET', 'path' => '/edge/ok', 'handler' => 'edge.svc']]);

        $response = $app->handle($this->request('HEAD'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody(), 'A HEAD response must not carry a body even when the handler produced one');
    }

    /**
     * Verify handler exceptions are rethrown and counted as HTTP errors and
     * failed request lifecycles, without recording a completed request.
     */
    public function testHandlerExceptionIsRecordedThenRethrown(): void
    {
        $app = $this->booted(handlers: [
            'edge.svc' => static fn (): ResponseInterface => throw new \RuntimeException('handler exploded'),
        ]);

        try {
            $app->handle($this->request());
            self::fail('A throwing handler must bubble up after being recorded.');
        } catch (\RuntimeException $e) {
            self::assertSame('handler exploded', $e->getMessage());
        }

        $telemetry = $app->getContainer()->get(Telemetry::class);
        assert($telemetry instanceof Telemetry);
        $snapshot = $telemetry->meter()->snapshot();

        $errors = $this->meterCount($snapshot, 'zef.http.errors.total', [
            'http.request.method' => 'GET',
            'exception.type' => \RuntimeException::class,
        ]);
        self::assertSame(1, $errors);

        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', ['event.name' => 'request.started']));
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', ['event.name' => 'request.failed']));
        self::assertSame(0, $this->meterCount($snapshot, 'zef.lifecycle.events.total', ['event.name' => 'request.completed']), 'A failed request never reaches the completed event');
    }

    /**
     * Zone ad-kernel-dispatch telemetry contract (issue #90 round 3): the handler
     * span must carry the exception fingerprint — status ERROR with the exception
     * class as description, plus an "exception" event whose attributes carry
     * exception.type. Kills the Dispatcher.php:93 MethodCallRemoval and the :94
     * ArrayItemRemoval('exception.type') mutants.
     */
    public function testHandlerExceptionMarksHandlerSpanErrorAndExceptionEvent(): void
    {
        putenv('ZEF_OTEL_ENABLED=true');

        try {
            $app = $this->booted(handlers: [
                'edge.svc' => static fn (): ResponseInterface => throw new \RuntimeException('handler exploded'),
            ]);

            try {
                $app->handle($this->request());
                self::fail('A throwing handler must bubble up after being recorded.');
            } catch (\RuntimeException $e) {
                self::assertSame('handler exploded', $e->getMessage());
            }

            $handlerSpans = [];
            foreach ($this->exporterSpans($app) as $span) {
                if ($span['name'] === 'zef.handler.execute') {
                    $handlerSpans[] = $span;
                }
            }
            self::assertNotSame([], $handlerSpans, 'The handler span must be exported for a failing handler');

            $span = $handlerSpans[0];
            self::assertSame('ERROR', $span['status'], 'A rethrown handler exception must mark the handler span ERROR');
            self::assertSame(\RuntimeException::class, $span['statusDescription'], 'The span status description must carry the exception class');

            $exceptionEvents = [];
            foreach ($span['events'] as $event) {
                if (($event['name'] ?? null) === 'exception') {
                    $exceptionEvents[] = $event;
                }
            }
            self::assertNotSame([], $exceptionEvents, 'The handler span must record an exception event for a rethrown exception');
            self::assertSame(
                \RuntimeException::class,
                $exceptionEvents[0]['attributes']['exception.type'] ?? null,
                'The exception event attributes must carry exception.type pointing at the exception class',
            );
        } finally {
            putenv('ZEF_OTEL_ENABLED');
        }
    }

    /**
     * Verify successful requests record the method/status counter, started and
     * completed lifecycle counters, and a request duration histogram observation.
     */
    public function testSuccessfulRequestRecordsExactMeterSeries(): void
    {
        $app = $this->booted();
        $app->handle($this->request());

        $telemetry = $app->getContainer()->get(Telemetry::class);
        assert($telemetry instanceof Telemetry);
        $snapshot = $telemetry->meter()->snapshot();

        self::assertSame(1, $this->meterCount($snapshot, 'zef.http.requests.total', [
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
        ]));
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', ['event.name' => 'request.started']));
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', ['event.name' => 'request.completed']));
        self::assertTrue(isset($snapshot['zef.http.request.duration_seconds|' . json_encode(['http.request.method' => 'GET'])]), 'A duration histogram observation must be recorded per request');
    }

    public function testTelemetryDisabledResponsesCarryNoTraceparent(): void
    {
        $app = $this->booted();
        $response = $app->handle($this->request());

        self::assertFalse($response->hasHeader('traceparent'), 'With telemetry disabled the response must stay clean');
    }

    public function testTelemetryEnabledResponsesCarryTraceparentAndSpansRecordStatus(): void
    {
        putenv('ZEF_OTEL_ENABLED=true');

        try {
            $okApp = $this->booted();
            $okResponse = $okApp->handle($this->request());
            self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $okResponse->getHeaderLine('traceparent'));

            $errorApp = $this->booted(handlers: [
                'edge.svc' => static fn (): ResponseInterface => new Response(500, [], 'boom'),
            ]);
            $errorApp->handle($this->request());
            $errorApp2 = $this->booted(handlers: [
                'edge.svc' => static fn (): ResponseInterface => new Response(499, [], 'nearly'),
            ]);
            $errorApp2->handle($this->request());

            $spans = $this->exporterSpans($errorApp);
            $handlerSpans = array_values(array_filter($spans, static fn (array $s): bool => $s['name'] === 'zef.handler.execute'));
            self::assertNotSame([], $handlerSpans, 'The handler span must be exported');
            self::assertSame('ERROR', $handlerSpans[0]['status'], 'A 500 handler response must mark the span as ERROR');
            self::assertSame(500, $handlerSpans[0]['attributes']['http.response.status_code'] ?? null);
            self::assertSame('edge.svc', $handlerSpans[0]['attributes']['zef.handler'] ?? null, 'The handler span must carry the service id');
            self::assertSame('OK', $this->exporterSpans($errorApp2)[0]['status'] ?? '', '499 is not a server error and must stay OK');

            $routerSpans = array_values(array_filter($spans, static fn (array $s): bool => $s['name'] === 'zef.router.match'));
            self::assertNotSame([], $routerSpans);
            self::assertSame('GET', $routerSpans[0]['attributes']['http.request.method'] ?? null, 'The router span must carry the request method');
            self::assertSame('/edge/ok', $routerSpans[0]['attributes']['url.path'] ?? null);
            self::assertSame('/edge/ok', $routerSpans[0]['attributes']['http.route'] ?? null, 'The router span must carry the matched route pattern');
        } finally {
            putenv('ZEF_OTEL_ENABLED');
        }
    }

    // ------------------------------------------------------------------
    // Dispatcher: error mapping contracts
    // ------------------------------------------------------------------

    public function testDispatcherMapsRouteMissesToExactJsonPayloads(): void
    {
        $app = $this->booted(routes: [
            ['method' => 'GET', 'path' => '/edge/ok', 'handler' => 'edge.svc'],
            ['method' => 'POST', 'path' => '/edge/ok', 'handler' => 'edge.svc'],
            ['method' => 'GET', 'path' => '/edge/{v:int}', 'handler' => 'edge.svc'],
        ]);

        // 404 carries method + path.
        $miss = $app->handle($this->request('GET', 'http://localhost/nowhere'));
        self::assertSame(404, $miss->getStatusCode());
        $body = $this->jsonBody($miss);
        self::assertSame('Not Found', $body['error']);
        self::assertSame('GET', $body['method']);
        self::assertSame('/nowhere', $body['path']);

        // 405 carries method + path + allow and the Allow header.
        $notAllowed = $app->handle($this->request('DELETE', 'http://localhost/edge/ok'));
        self::assertSame(405, $notAllowed->getStatusCode());
        $body = $this->jsonBody($notAllowed);
        self::assertSame('Method Not Allowed', $body['error']);
        self::assertSame('DELETE', $body['method']);
        self::assertSame(['GET', 'HEAD', 'POST'], $body['allow'], 'The JSON payload carries the raw list');
        self::assertSame('GET, HEAD, POST', $notAllowed->getHeaderLine('Allow'));

        // 400 carries the constraint detail.
        $violating = $app->handle($this->request('GET', 'http://localhost/edge/notanint'));
        self::assertSame(400, $violating->getStatusCode());
        $body = $this->jsonBody($violating);
        self::assertSame('Bad Request', $body['error']);
        $detail = $body['detail'] ?? '';
        assert(is_string($detail));
        self::assertStringContainsString('int', $detail);
    }

    public function testMiddlewarePipelineWrapsTerminalInRegistrationOrder(): void
    {
        $outer = new EdgeRouterKernelRecordingMiddleware('a');
        $inner = new EdgeRouterKernelRecordingMiddleware('b');
        $terminal = new readonly class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'done');
            }
        };

        $pipeline = new MiddlewarePipeline([$outer, $inner], $terminal);
        $response = $pipeline->handle($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['enter:a', 'exit:a'], $outer->seen, 'The first middleware is outermost');
        self::assertSame(['enter:b', 'exit:b'], $inner->seen, 'The second middleware is innermost');

        // withMiddleware appends after construction.
        $added = new EdgeRouterKernelRecordingMiddleware('x');
        $extended = new MiddlewarePipeline([], $terminal)->withMiddleware($added);
        $extended->handle($this->request());
        self::assertSame(['enter:x', 'exit:x'], $added->seen, 'withMiddleware must append the new middleware');
    }

    // ------------------------------------------------------------------
    // ResponseEmitter: body admission and status wiring
    // ------------------------------------------------------------------

    public function testEmitterSetsTheResponseStatusBoundary(): void
    {
        $emitter = new ResponseEmitter();

        \ob_start();
        $emitter->emit(new Response(100, [], ''));
        $hundred = \http_response_code();
        \ob_end_clean();

        \ob_start();
        $emitter->emit(new Response(599, [], ''));
        $fiveNinetyNine = \http_response_code();
        \ob_end_clean();

        self::assertSame(100, $hundred, 'The 100..599 re-validation must wire the status through');
        self::assertSame(599, $fiveNinetyNine);
    }
    // ------------------------------------------------------------------
    // Application wiring
    // ------------------------------------------------------------------

    /**
     * @param array<string, \Closure(ServerRequestInterface): ResponseInterface> $handlers
     * @param list<array{method:string, path:string, handler:string}> $routes
     * @param array<string, mixed> $extraConfig
     */
    private function booted(
        array $routes = [['method' => 'GET', 'path' => '/edge/ok', 'handler' => 'edge.svc']],
        array $handlers = [],
        bool $debug = false,
        ?callable $configure = null,
        array $extraConfig = [],
    ): Application {
        $app = new Application($debug);
        $handlers += [
            'edge.svc' => static fn (): ResponseInterface => new Response(200, [], 'edge-ok'),
        ];
        $app->addProvider(new readonly class($handlers, $routes, $extraConfig) implements ConfigProviderInterface {
            /**
             * @param array<string, \Closure(ServerRequestInterface): ResponseInterface> $handlers
             * @param list<array{method:string, path:string, handler:string}> $routes
             * @param array<string, mixed> $extraConfig
             */
            public function __construct(
                private array $handlers,
                private array $routes,
                private array $extraConfig,
            ) {}

            #[\Override]
            public function getModuleName(): string
            {
                return 'edge-router-kernel';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                $services = [];
                foreach ($this->handlers as $id => $factory) {
                    $services[$id] = [
                        'factory' => static fn (): RequestHandlerInterface => new EdgeRouterKernelHandler($factory),
                        'deps' => [],
                    ];
                }

                return ['services' => $services, 'routes' => $this->routes] + $this->extraConfig;
            }
        });
        if ($configure !== null) {
            $configure($app);
        }
        $app->boot();

        return $app;
    }

    private function request(string $method = 'GET', string $uri = 'http://localhost/edge/ok'): ServerRequest
    {
        return new ServerRequest($method, new Uri($uri, ['localhost']));
    }

    // ------------------------------------------------------------------
    // UrlGenerator: strict reverse routing
    // ------------------------------------------------------------------

    private function generator(): UrlGenerator
    {
        $router = new Router();
        $router->add('GET', '/static', 'h', name: 'static');
        $router->add('GET', '/f/{v:hex}', 'h', name: 'files');
        $router->add('GET', '/g/{v}', 'h', name: 'generic');

        return new UrlGenerator($router);
    }

    // ------------------------------------------------------------------
    // Telemetry reflection helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        $body = [];
        foreach ($decoded as $key => $value) {
            assert(is_string($key));
            $body[$key] = $value;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $attributes
     */
    private function meterCount(array $snapshot, string $series, array $attributes): float|int
    {
        ksort($attributes); // the meter allowlists + ksorts before keying
        $key = $series . '|' . json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $seriesData = $snapshot[$key] ?? null;
        if ($seriesData === null) {
            return 0; // an absent series is exactly zero observations
        }
        assert(is_array($seriesData));
        $count = $seriesData['count'] ?? 0;
        assert(is_int($count) || is_float($count));

        return $count;
    }

    /**
     * Flush pending spans through the processor and read them from the
     * in-memory exporter (mirrors Infection-safe instrumentation).
     *
     * @return list<array{name: string, status: string, statusDescription: null|string, attributes: array<string, mixed>, events: list<array{name: string, time_unix_nano: int, attributes: array<string, mixed>}>}>
     */
    private function exporterSpans(Application $app): array
    {
        $telemetry = $app->getContainer()->get(Telemetry::class);
        assert($telemetry instanceof Telemetry);
        $telemetry->shutdown();

        $processor = new \ReflectionProperty(Telemetry::class, 'processor');
        $processorObject = $processor->getValue($telemetry);
        assert(is_object($processorObject));
        $exporterProperty = new \ReflectionProperty($processorObject::class, 'exporter');
        $exporter = $exporterProperty->getValue($processorObject);
        assert($exporter instanceof InMemorySpanExporter);

        $result = [];
        foreach ($exporter->spans() as $span) {
            $result[] = [
                'name' => $span->name,
                'status' => $span->status,
                'statusDescription' => $span->statusDescription,
                'attributes' => $span->attributes,
                'events' => $span->events,
            ];
        }

        return $result;
    }
}
