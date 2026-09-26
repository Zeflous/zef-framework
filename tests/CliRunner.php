<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Self-test suite
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Test;

use Psr\Container\ContainerExceptionInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Zef\App\Bootstrap;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventDispatchException;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Http\LimitedInputStream;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\Request;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Message\InProcessMessageBus;
use Zef\Framework\Message\JsonMessageSerializer;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageHandlerInterface;
use Zef\Framework\Message\MessageMiddlewareInterface;
use Zef\Framework\Message\MessageResult;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\ResponseEmitter;
use Zef\Framework\Router\Router;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\Distributed\AllowScopeAuthorizationPolicy;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\StaticCredentialProvider;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Validation\HeaderValidator;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\ErrorResponseFactory;
use Zef\Middleware\GlobalErrorHandler;

final class CliRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private ?string $filter = null;
    private int $matchedSuites = 0;
    private bool $exactMatch = false;

    /**
     * Run exactly ONE suite by its exact key (e.g. 'psr', 'v280'), regardless
     * of substring overlaps such as 'psr' matching 'psr7'. Used by the native
     * PHPUnit integration so every suite maps to a dedicated test case and
     * all exercised framework code is recorded by the coverage driver.
     */
    public function runOne(string $key, bool $html = false): int
    {
        $this->exactMatch = true;

        return $this->run($html, $key);
    }

    /** Number of successful assertions recorded by the last run. */
    public function passedCount(): int
    {
        return $this->passed;
    }

    /**
     * Run the self-test suite. An optional $filter (case-insensitive
     * substring match against suite keys or labels) restricts execution
     * to matching suites, e.g. 'v280' for the v2.8.0 feature suite.
     */
    public function run(bool $html = false, ?string $filter = null): int
    {
        $this->filter = $filter === null ? null : strtolower(trim($filter));
        $this->banner($html);
        $this->suite('psr', 'PSR contracts', fn () => $this->testPsr());
        $this->suite('routes', 'Application routes', fn () => $this->testRoutes());
        $this->suite('container', 'Container lifetime/cycles', fn () => $this->testContainer());
        $this->suite('concurrency', 'Concurrent request scope isolation', fn () => $this->testConcurrencyScopes());
        $this->suite('security', 'Security/header/URI', fn () => $this->testSecurity());
        $this->suite('request', 'Request/factory boundaries', fn () => $this->testRequestFactoryBoundaries());
        $this->suite('router', 'Router ambiguity', fn () => $this->testRouterSemantics());
        $this->suite('pipeline', 'Pipeline/error boundary', fn () => $this->testPipeline());
        $this->suite('psr7', 'PSR-7 edge cases & resource safety', fn () => $this->testPsrHardening());
        $this->suite('hardening', 'Final production hardening', fn () => $this->testFinalHardening());
        $this->suite('json', 'JSON scalar boundary', fn () => $this->testJsonScalar());
        $this->suite('gate', 'Zero critical bugs gate', fn () => $this->testZeroCriticalGate());
        $this->suite('beta3', 'beta3 hardening', fn () => $this->testBeta3Hardening());
        $this->suite('v260', 'v2.6.0 regression', fn () => $this->testV260Regression());
        $this->suite('v270', 'v2.7.0 edge-case regression', fn () => $this->testV270Regression());
        $this->suite('v280', 'v2.8.0 feature suite', fn () => new V280FeatureSuite($this)->run());
        $this->suite('v290', 'v2.9.0 autowiring suite', fn () => new V290AutowireSuite($this)->run());
        $this->suite('v210', 'v2.10.0 enterprise suite', fn () => new V2100EnterpriseSuite($this)->run());
        $this->suite('v211', 'v2.11.0 radix-tree suite', fn () => new V2110RadixTreeSuite($this)->run());
        if ($this->filter !== null && $this->matchedSuites === 0) {
            ++$this->failed;
            $this->out("[FAIL] no suite matches filter '{$this->filter}'. Suite keys: "
                . 'psr, routes, container, concurrency, security, request, router, pipeline, '
                . 'psr7, hardening, json, gate, beta3, v260, v270, v280, v290, v210, v211.');
        }
        $this->summary($html);

        return $this->failed === 0 ? 0 : 1;
    }

    public function ok(bool $cond, string $label): void
    {
        if ($cond) {
            ++$this->passed;
            $this->out('  ✔ ' . $label);
        } else {
            ++$this->failed;
            $this->out('  ✘ ' . $label);
        }
    }

    public function throws(string $class, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->ok(false, $label . ' (no exception)');
        } catch (\Throwable $e) {
            $this->ok($e instanceof $class, $label . ' -> ' . $e::class);
        }
    }

    private function testPsr(): void
    {
        $this->ok(is_subclass_of(Response::class, ResponseInterface::class), 'Response implements PSR-7 ResponseInterface');
        $this->ok(is_subclass_of(ServerRequest::class, ServerRequestInterface::class), 'ServerRequest implements PSR-7 ServerRequestInterface');
        $c = new Psr17Factory();
        $this->ok($c->createResponse() instanceof ResponseInterface, 'PSR-17 response factory');
        $this->ok($c->createStream('abc')->__toString() === 'abc', 'PSR-17 stream factory');
        $msg = new Response(200, ['X-Test' => 'one'])->withHeader('x-test', 'two');
        $this->ok($msg->getHeaderLine('X-TEST') === 'two' && array_key_exists('x-test', $msg->getHeaders()), 'case-insensitive header replacement preserves supplied case');
        $added = $msg->withAddedHeader('X-TEST', 'three');
        $this->ok($added->getHeaderLine('x-test') === 'two, three' && count($added->getHeaders()) === 1, 'case-insensitive withAddedHeader cannot create duplicate keys');
        $this->ok($added->withoutHeader('x-Test')->getHeaders() === [], 'header dictionary removes canonical key');
    }

    private function testRoutes(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();
        foreach (
            [
                ['GET', '/', 200],
                ['GET', '/about', 200],
                ['GET', '/toko', 200],
                ['GET', '/toko/produk/2', 200],
                ['GET', '/missing', 404],
                ['GET', '/toko/produk/abc', 400],
            ] as [$m, $p, $s]
        ) {
            $r = $app->handle(new ServerRequest($m, new Uri('http://localhost' . $p, ['localhost'])));
            $this->ok($r->getStatusCode() === $s, "{$m} {$p} -> {$s}");
        }
        $r = $app->handle(new ServerRequest('GET', new Uri('http://localhost/toko/produk/2', ['localhost'])));
        $this->ok(str_contains($r->bodyString(), 'Mouse Wireless'), 'detail body');
        $this->ok($r->hasHeader('x-request-id'), 'correlation ID');
        $this->ok($r->hasHeader('x-content-type-options'), 'security middleware');
    }

    private function testContainer(): void
    {
        $c = new Container();
        $c->register('a', static fn (): \stdClass => new \stdClass(), [], 'm', ServiceLifetime::SINGLETON);
        $c->register('b', static fn ($x, $a): \stdClass => new \stdClass(), ['a'], 'm', ServiceLifetime::REQUEST);
        $c->validateAndFreeze();
        $s1 = $c->createRequestScope();
        $b1 = $s1->get('b');
        $this->ok($s1->get('b') === $b1, 'request scope singleton within scope');
        $s1->close();
        $s2 = $c->createRequestScope();
        $this->ok($s2->get('b') !== $b1, 'request scope cleared between scopes');
        $s2->close();
        $this->ok($c->get('a') === $c->get('a'), 'singleton stable');
        $cycle = new Container();
        $cycle->register('x', static fn ($c, $y): \stdClass => new \stdClass(), ['y'], 'm');
        $cycle->register('y', static fn ($c, $x): \stdClass => new \stdClass(), ['x'], 'm');
        $this->throws(ServiceCircularDependencyException::class, fn () => $cycle->validateAndFreeze(), 'boot dependency cycle');
    }

    private function testConcurrencyScopes(): void
    {
        $c = new Container();
        $c->register('request.counter', static fn (): \stdClass => new \stdClass(), [], 'test', ServiceLifetime::REQUEST);
        $c->register('singleton.bad', static fn ($c, $x): \stdClass => new \stdClass(), ['request.counter'], 'test', ServiceLifetime::SINGLETON);
        $this->throws(InvalidConfigurationException::class, fn () => $c->validateAndFreeze(), 'captive request dependency rejected');

        $c2 = new Container();
        $c2->register('request.counter', static fn (): \stdClass => new \stdClass(), [], 'test', ServiceLifetime::REQUEST);
        $c2->validateAndFreeze();
        $a = $c2->createRequestScope();
        $b = $c2->createRequestScope();
        $a1 = $a->get('request.counter');
        $a2 = $a->get('request.counter');
        $b1 = $b->get('request.counter');
        $this->ok($a1 === $a2, 'request scope stable within scope');
        $this->ok($a1 !== $b1, 'independent request scopes do not share instances');
        $a->close();
        $b->close();
    }

    private function testSecurity(): void
    {
        $r = new Response();
        $this->throws(\InvalidArgumentException::class, fn (): MessageInterface => $r->withHeader("X-Bad\r\nX-Evil", 'x'), 'header-name CRLF blocked');
        $this->throws(\InvalidArgumentException::class, fn (): MessageInterface => $r->withHeader('X-Bad', "x\r\ny"), 'header-value CRLF blocked');
        $u = new Uri('http://localhost/a', ['localhost']);
        $this->ok($u->withPath('/x/y')->getPath() === '/x/y', 'URI immutable path');
        $this->throws(\InvalidArgumentException::class, fn (): Uri => new Uri('http://evil.test/', ['localhost']), 'trusted host enforced');
        $this->throws(\InvalidArgumentException::class, fn (): UriInterface => $u->withPort(70000), 'port range');
        $rv = new RouteConstraintValidator();
        $this->throws(InvalidConfigurationException::class, fn () => $rv->addCustom('broken', '/^[0-9/'), 'malformed custom regex rejected without warning leakage');
    }

    private function testPsrHardening(): void
    {
        $factory = new Psr17Factory();
        $req = $factory->createServerRequest('GET', 'http://localhost/')->withoutHeader('Host');
        $newUri = $factory->createUri('http://zef.test');
        $mutated = $req->withUri($newUri, true);
        $this->ok($mutated->getHeaderLine('Host') === 'zef.test', 'PSR-7: missing Host populated with preserveHost=true');
        $emptyHost = $factory->createServerRequest('GET', 'http://localhost/')->withHeader('Host', '');
        $this->ok($emptyHost->withUri($newUri, true)->getHeaderLine('Host') === 'zef.test', 'PSR-7: empty Host populated with preserveHost=true');
        $preserved = $factory->createServerRequest('GET', 'http://localhost/')->withHeader('Host', 'legacy.test');
        $this->ok($preserved->withUri($newUri, true)->getHeaderLine('Host') === 'legacy.test', 'PSR-7: non-empty Host preserved');
        $parsed = $factory->createServerRequest('POST', '/')->withParsedBody(['x' => 1]);
        $this->ok($parsed->getParsedBody() === ['x' => 1], 'PSR-7: array parsed body accepted');
        $this->ok($parsed->withParsedBody(null)->getParsedBody() === null, 'PSR-7: null parsed body accepted');
        $this->throws(\InvalidArgumentException::class, fn (): ServerRequestInterface => $parsed->withParsedBody('invalid'), 'PSR-7: scalar parsed body rejected');
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, 'emit-close-contract');
        $stream = $factory->createStreamFromResource($resource);
        $response = $factory->createResponse(200)->withBody($stream);
        ob_start();
        new ResponseEmitter()->emit($response);
        ob_end_clean();
        $this->ok($stream->isReadable(), 'Emitter: borrowed stream remains open by default');
        $resource2 = fopen('php://temp', 'w+b');
        fwrite($resource2, 'explicit-close');
        $stream2 = $factory->createStreamFromResource($resource2);
        $response2 = $factory->createResponse(200)->withBody($stream2);
        ob_start();
        new ResponseEmitter()->emit($response2, true);
        ob_end_clean();
        $this->throws(\RuntimeException::class, fn (): int => $stream2->tell(), 'Emitter: explicit close invalidates stream deterministically');
        $agg = new ConfigAggregator();
        $provider = new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'core';
            }

            #[\Override]
            public function getConfig(): array
            {
                return [];
            }
        };
        $provider2 = new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'Core';
            }

            #[\Override]
            public function getConfig(): array
            {
                return [];
            }
        };
        $agg->addProvider($provider);
        $this->throws(InvalidConfigurationException::class, fn () => $agg->addProvider($provider2), 'Config: module collision is case-insensitive');
    }

    private function testRequestFactoryBoundaries(): void
    {
        $factory = new Psr17Factory();
        $req = $factory->createRequest('GET', 'http://localhost/demo');
        $this->ok($req->getHeaderLine('Host') === 'localhost', 'request factory populates Host from URI');
        $this->ok($req->withMethod('PATCH')->getMethod() === 'PATCH', 'HTTP method case preserved');
        $this->ok($req->getRequestTarget() === '/demo', 'request target defaults to origin-form');
        $retargeted = $req->withUri($factory->createUri('http://localhost/other?q=1'));
        $this->ok($retargeted->getRequestTarget() === '/other?q=1', 'withUri updates derived request target');
        $explicit = $req->withRequestTarget('*')->withUri($factory->createUri('http://localhost/other'));
        $this->ok($explicit->getRequestTarget() === '*', 'explicit request target survives withUri');
        $emptyTarget = $req->withRequestTarget('');
        $this->ok($emptyTarget->getRequestTarget() === '', 'explicit empty request target retained verbatim');
        $this->throws(\InvalidArgumentException::class, fn (): StreamInterface => $factory->createStreamFromFile('/does/not/exist', 'q'), 'invalid stream mode rejected');
        $container = new Container();
        $container->register('request.s', fn (): \stdClass => new \stdClass(), [], 't', ServiceLifetime::REQUEST);
        $container->validateAndFreeze();
        $a = $container->createRequestScope();
        $b = $container->createRequestScope();
        $aa = $a->get('request.s');
        $bb = $b->get('request.s');
        $this->ok($aa !== $bb, 'independent request scopes do not share mutable state');
        $a->close();
        $b->close();
    }

    private function testRouterSemantics(): void
    {
        $r = new Router();
        $r->add('GET', '/user/{id:int}', 'dynamic', priority: 0);
        $r->add('GET', '/user/admin', 'static', priority: 10);
        $m = $r->match('GET', '/user/admin');
        $this->ok($m['handler'] === 'static', 'static route wins over constraint mismatch');
        $this->throws(RouteConstraintException::class, fn (): array => $r->match('GET', '/user/abc'), 'constraint failure produces 400-class exception');
    }

    private function testPipeline(): void
    {
        $terminal = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };
        $mw = new GlobalErrorHandler(new NullLogger(), new ErrorResponseFactory(false));
        $p = new MiddlewarePipeline([], $terminal)->withMiddleware($mw);
        $res = $p->handle(new ServerRequest('GET', new Uri('http://localhost/', ['localhost'])));
        $this->ok($res->getStatusCode() === 500, 'error boundary catches terminal exception');
    }

    private function testFinalHardening(): void
    {
        $u = new Uri('http://localhost/', ['localhost', '::1']);
        $this->throws(\InvalidArgumentException::class, fn (): UriInterface => $u->withScheme("http\r\n"), 'URI scheme rejects control chars');
        $ipv6 = $u->withHost('[::1]');
        $this->ok($ipv6->getHost() === '::1' && $ipv6->getAuthority() === '[::1]', 'IPv6 host authority normalized');
        $relative = new Uri('foo/bar');
        $this->ok($relative->getPath() === 'foo/bar' && (string) $relative === 'foo/bar', 'URI rootless path preserved per PSR-7');
        $this->ok(new Request('GET', $relative)->getRequestTarget() === '/foo/bar', 'request target derives origin-form from rootless URI');
        $encoded = new Uri()->withPath('/hello world')->withQuery('q=a b');
        $this->ok($encoded->getPath() === '/hello%20world' && $encoded->getQuery() === 'q=a%20b', 'URI components are percent-encoded');
        $st = Stream::fromString('abcdef');
        $st->seek(2);
        $this->ok((string) $st === 'abcdef' && $st->tell() === 6, 'Stream::__toString__ rewinds and reads to EOF per PSR-7');
        $upStream = Stream::fromString('upload');
        $up = new UploadedFile($upStream);
        $bad = sys_get_temp_dir() . '/zef-missing-dir/' . bin2hex(random_bytes(4));
        $this->throws(\RuntimeException::class, fn () => $up->moveTo($bad), 'upload move failure does not poison object');
        $this->ok(!$upStream->eof(), 'source stream remains usable after failed move');
        $c = new Container();
        $c->register('req', static fn (): \stdClass => new \stdClass(), [], 'm', ServiceLifetime::REQUEST);
        $c->register('mid', static fn ($c, $r): \stdClass => new \stdClass(), ['req'], 'm', ServiceLifetime::SINGLETON);
        $c->register('top', static fn ($c, $m): \stdClass => new \stdClass(), ['mid'], 'm', ServiceLifetime::SINGLETON);
        $this->throws(InvalidConfigurationException::class, fn () => $c->validateAndFreeze(), 'transitive captive dependency rejected');
        $r = new Router();
        $r->add('GET', '/user/{id:int}', 'a');
        $this->throws(\InvalidArgumentException::class, fn () => $r->add('GET', '/user/{name:int}', 'b'), 'structural dynamic route collision rejected');
        $r->add('POST', '/user/{id:int}', 'p');
        $this->throws(MethodNotAllowedException::class, fn (): array => $r->match('PUT', '/user/7'), '405 route method mismatch detected');
        $sc = new Container();
        $sc->register('r', static fn (): \stdClass => new \stdClass(), [], 'm', ServiceLifetime::REQUEST);
        $sc->validateAndFreeze();
        $scope = $sc->createRequestScope();
        $scope->close();
        $this->throws(\LogicException::class, fn (): mixed => $scope->get('r'), 'closed request scope rejects access');
        $corsOff = new CorsMiddleware();
        $corsReq = new ServerRequest('GET', new Uri('http://localhost/', ['localhost']));
        $corsRes = $corsOff->process($corsReq, new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        });
        $this->ok(!$corsRes->hasHeader('Access-Control-Allow-Origin'), 'CORS disabled by default');
        $corsOn = new CorsMiddleware('https://example.test');
        $corsReqOrigin = new ServerRequest('GET', new Uri('http://localhost/', ['localhost']), [], [], [], [], null, ['Origin' => 'https://example.test']);
        $corsRes2 = $corsOn->process($corsReqOrigin, new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Response(200, ['Vary' => 'Accept-Encoding'], 'ok');
            }
        });
        $this->ok($corsRes2->getHeaderLine('Access-Control-Allow-Origin') === 'https://example.test' && $corsRes2->getHeaderLine('Vary') === 'Accept-Encoding, Origin', 'CORS opt-in merges existing Vary');
    }

    private function testJsonScalar(): void
    {
        $factory = new Psr17Factory();
        $req = $factory->createServerRequest('POST', 'http://localhost/', ['CONTENT_TYPE' => 'application/json']);
        $stream = $factory->createStream('123');
        $req = $req->withBody($stream);
        $this->ok(RequestFactory::decodeJsonBody($req) === 123, 'JSON scalar remains available through explicit decoder');
        $this->throws(\InvalidArgumentException::class, fn () => $req->withParsedBody(123), 'PSR parsedBody contract still rejects scalar');
    }

    private function testZeroCriticalGate(): void
    {
        $c = new Container();
        $c->register('req', static fn (): \stdClass => new \stdClass(), [], 'gate', ServiceLifetime::REQUEST);
        $c->validateAndFreeze();
        $this->ok($c->has('req'), 'PSR-11 has() reports registered request service');
        $this->throws(ContainerExceptionInterface::class, fn (): mixed => $c->get('req'), 'request service outside scope is a ContainerException');
        $badPct = new Uri()->withPath('/x%ZZ');
        $this->ok($badPct->getPath() === '/x%25ZZ', 'malformed percent escape is encoded');
        $this->throws(\InvalidArgumentException::class, fn (): Request => new Request('', $badPct), 'empty request method rejected');
        $this->throws(\InvalidArgumentException::class, fn (): Response => new Response(200, [], '', '', 'not-http'), 'invalid protocol rejected in constructor');
        $r = new Router();
        $this->throws(InvalidConfigurationException::class, fn () => $r->add('GET', '/x/{id:missing}', 'h'), 'unknown route constraint rejected during registration');
        $r->addConstraint('digits', '/^\d+$/');
        $r->add('GET', '/x/{id:digits}', 'h');
        $this->throws(\InvalidArgumentException::class, fn () => $r->add('GET', '/x/{other:digits}', 'h2'), 'structural duplicate remains rejected');
        $cors = new CorsMiddleware('https://example.test');
        $res = $cors->process(new ServerRequest('GET', new Uri('http://localhost/', ['localhost'])), new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Response(200, ['Vary' => 'Accept-Encoding, Origin'], 'ok');
            }
        });
        $this->ok($res->getHeaderLine('Vary') === 'Accept-Encoding, Origin', 'CORS does not duplicate Vary token');
        $sc = $c->createRequestScope();
        $sc->get('req');
        $sc->close();
        $this->throws(\LogicException::class, fn (): mixed => $sc->get('req'), 'closed scope rejects resolution');
    }

    /**
     * New 'beta3 hardening' suite covering bug fixes 1, 2, 3, 4, 6, 19
     * plus the withPath('foo') -> 'http://host/foo' test.
     */
    private function testBeta3Hardening(): void
    {
        // Fix #1: LimitedInputStream seek/rewind resets observedBytes.
        $inner = Stream::fromString(str_repeat('x', 100));
        $policy = new RequestBodyPolicy(150);
        $limited = new LimitedInputStream($inner, $policy);
        $first = $limited->getContents();
        $this->ok(strlen($first) === 100, 'beta3 fix1: first getContents reads 100 bytes');
        $limited->rewind();
        $second = '';

        try {
            $second = $limited->getContents();
        } catch (PayloadTooLargeException $e) {
            $this->ok(false, 'beta3 fix1: rewind+getContents threw PayloadTooLargeException unexpectedly');
        }
        $this->ok(strlen($second) === 100, 'beta3 fix1: rewind+getContents reads 100 bytes without throwing');

        // Fix #2: IPv6 literal URL no longer throws.
        try {
            $ipv6Uri = new Uri('http://[::1]:8080/x');
            $this->ok($ipv6Uri->getHost() === '::1', 'beta3 fix2: IPv6 host stripped of brackets');
            $this->ok($ipv6Uri->getPort() === 8080, 'beta3 fix2: IPv6 port parsed correctly');
            $this->ok($ipv6Uri->getAuthority() === '[::1]:8080', 'beta3 fix2: IPv6 authority formatted correctly');
        } catch (\InvalidArgumentException $e) {
            $this->ok(false, 'beta3 fix2: IPv6 URI threw: ' . $e->getMessage());
            $this->ok(false, 'beta3 fix2: IPv6 port not tested');
            $this->ok(false, 'beta3 fix2: IPv6 authority not tested');
        }

        // Fix #3: HeaderValidator rejects \x01, accepts HTAB.
        $validator = new HeaderValidator();
        $this->throws(
            InvalidHeaderException::class,
            fn () => $validator->assertValue('X-Test', "\x01value"),
            'beta3 fix3: control char \x01 rejected by HeaderValidator',
        );
        $htabPassed = true;

        try {
            $validator->assertValue('X-Test', "\tvalue");
        } catch (\Throwable) {
            $htabPassed = false;
        }
        $this->ok($htabPassed, 'beta3 fix3: HTAB accepted by HeaderValidator');

        // Fix #4: InMemoryRateLimiter guards.
        $this->throws(
            \InvalidArgumentException::class,
            fn (): InMemoryRateLimiter => new InMemoryRateLimiter(0),
            'beta3 fix4: InMemoryRateLimiter maxKeys=0 rejected',
        );
        $limiter = new InMemoryRateLimiter(10);
        $this->throws(
            \InvalidArgumentException::class,
            fn (): RateLimitDecision => $limiter->check('', 10, 60),
            'beta3 fix4: empty key rejected',
        );
        $this->throws(
            \InvalidArgumentException::class,
            fn (): RateLimitDecision => $limiter->check('key', 0, 60),
            'beta3 fix4: limit=0 rejected',
        );
        $this->throws(
            \InvalidArgumentException::class,
            fn (): RateLimitDecision => $limiter->check('key', 10, 0),
            'beta3 fix4: windowSeconds=0 rejected',
        );

        // Fix #6: TelemetrySanitizer::string() UTF-8 safe truncation.
        $threeByteChar = "\xE2\x82\xAC"; // € sign
        $longStr = str_repeat($threeByteChar, 700); // 2100 bytes
        $truncated = TelemetrySanitizer::string($longStr, 2048);
        $jsonEncoded = json_encode($truncated);
        $this->ok($jsonEncoded !== false, 'beta3 fix6: UTF-8 truncated string passes json_encode');

        // Fix #19: protocol version '2' accepted.
        $resp2 = new Response(200, [], '', '', '2');
        $this->ok($resp2->getProtocolVersion() === '2', 'beta3 fix19: protocol version "2" accepted');
        $resp3 = new Response(200, [], '', '', '3');
        $this->ok($resp3->getProtocolVersion() === '3', 'beta3 fix19: protocol version "3" accepted');
        $this->throws(
            \InvalidArgumentException::class,
            fn (): Response => new Response(200, [], '', '', '1.1.1'),
            'beta3 fix19: invalid protocol "1.1.1" rejected',
        );

        // withPath('foo') on a URI with host must prepend '/'.
        $uriWithHost = new Uri('http://host');
        $withFoo = $uriWithHost->withPath('foo');
        $this->ok((string) $withFoo === 'http://host/foo', 'beta3: withPath("foo") on URI with host stringifies to http://host/foo');
    }

    /**
     * Regression suite for the v2.6.0 full refactor: every fix is
     * exercised through its public API so a future regression cannot
     * land silently.
     */
    private function testV260Regression(): void
    {
        // 260-1/2/3: fromGlobals survives IPv6 Host (with and without
        // port), missing Host, FQDN trailing dot, and //path targets.
        $srv = ['REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_HOST' => '[::1]', 'REQUEST_URI' => '/x'];
        $req = RequestFactory::fromServer($srv);
        $this->ok($req->getUri()->getHost() === '::1', 'v260: IPv6 Host without port parsed');
        $srv['HTTP_HOST'] = '[2001:db8::1]:8080';
        $req = RequestFactory::fromServer($srv);
        $this->ok($req->getUri()->getHost() === '2001:db8::1' && $req->getUri()->getPort() === 8080, 'v260: IPv6 Host with port parsed');
        $srv['HTTP_HOST'] = 'Example.COM.';
        $req = RequestFactory::fromServer($srv);
        $this->ok($req->getUri()->getHost() === 'example.com', 'v260: FQDN trailing dot normalized');
        unset($srv['HTTP_HOST']);
        $req = RequestFactory::fromServer($srv);
        $this->ok($req->getUri()->getHost() === 'localhost', 'v260: missing Host falls back to localhost');
        $srv['HTTP_HOST'] = 'a.com';
        $srv['REQUEST_URI'] = '//foo/bar';
        $req = RequestFactory::fromServer($srv);
        $this->ok($req->getUri()->getPath() === '//foo/bar', 'v260: origin-form //path preserved');

        // 260-4: request target CRLF is rejected on both constructors.
        $this->throws(
            \InvalidArgumentException::class,
            fn (): Request => new Request('GET', new Uri('http://a.com/'), [], null, '1.1', "/p\r\nEvil: yes"),
            'v260: Request ctor rejects CRLF target',
        );
        $this->throws(
            \InvalidArgumentException::class,
            fn (): ServerRequest => new ServerRequest('GET', new Uri('http://a.com/'), requestTarget: "/p\r\nEvil: yes"),
            'v260: ServerRequest ctor rejects CRLF target',
        );

        // 260-5: Host header from an IPv6 URI is bracketed.
        $bracketed = new Request('GET', new Uri('http://a.com/'))->withUri(new Uri('http://[::1]:9090/'));
        $this->ok($bracketed->getHeaderLine('Host') === '[::1]:9090', 'v260: Host header brackets IPv6');

        // 260-6: uploaded tree is validated in the constructor too.
        $this->throws(
            \InvalidArgumentException::class,
            fn (): ServerRequest => new ServerRequest('GET', new Uri('http://a.com/'), uploadedFiles: ['f' => 'junk']),
            'v260: ServerRequest ctor validates uploaded tree',
        );

        // 260-7: ConfigAggregator reads without an explicit merge().
        $agg = new ConfigAggregator();
        $agg->addProvider(new class implements ConfigProviderInterface {
            public function getModuleName(): string
            {
                return 'core';
            }

            public function getConfig(): array
            {
                return ['db' => ['host' => 'db1']];
            }
        });
        $this->ok($agg->get('core.db.host') === 'db1', 'v260: ConfigAggregator::get without merge()');

        // 260-8: message bus walks the full middleware chain on every
        // $next invocation (retry middleware pattern).
        $runs = 0;
        $innerCalls = 0;
        $handler = new class($runs) implements MessageHandlerInterface {
            public function __construct(public int &$runs) {}

            public function __invoke(MessageEnvelope $m, MessageContext $c): mixed
            {
                ++$this->runs;

                return null;
            }
        };
        $doubleNext = new class($innerCalls) implements MessageMiddlewareInterface {
            public function __construct(public int &$calls) {}

            public function process(
                MessageEnvelope $m,
                MessageContext $c,
                \Closure $next,
            ): MessageResult {
                ++$this->calls;
                $next($m, $c);

                return $next($m, $c);
            }
        };
        $bus = new InProcessMessageBus(['t.demo' => $handler], [$doubleNext]);
        $bus->dispatch(new MessageEnvelope('msg-2608-aaa', 't.demo', null));
        $this->ok($runs === 2 && $innerCalls === 1, 'v260: message bus chain consistent on double $next');

        // 260-9: unknown job types are dead-lettered instead of losing the
        // dequeued envelope and aborting the run loop.
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $queue->enqueue(new JobEnvelope('job-2609-aaa', 't.unknown', null, 0));
        $queue->enqueue(new JobEnvelope('job-2609-bbb', 't.known', null, 0));
        $worker = new InProcessJobWorker($queue, deadLetterQueue: $dlq, pollIntervalMs: 0);
        $executed = [];
        $worker->register('t.known', function (JobEnvelope $j) use (&$executed): void {
            $executed[] = $j->jobId;
        });
        $worker->run(2);
        $this->ok($executed === ['job-2609-bbb'] && $dlq->size() === 1, 'v260: unknown job type dead-lettered, loop continues');

        // 260-10: string callables are valid event listeners.
        $reg = new EventRegistration(\stdClass::class, 'strlen');
        $this->ok($reg->acceptsContext === false, 'v260: EventRegistration accepts string callable');

        // 260-11: listener errors aggregate into EventDispatchException.
        $evt = new EventDispatcher();
        $evt->listen(\stdClass::class, function (): never {
            throw new \RuntimeException('e1');
        }, 10);
        $evt->listen(\stdClass::class, function (): never {
            throw new \LogicException('e2');
        }, 5);
        $evt->freeze();
        $caught = 0;

        try {
            $evt->dispatchWithContext(new \stdClass(), new EventContext('event-26011-aa', 0));
        } catch (EventDispatchException $e) {
            $caught = count($e->errors);
        }
        $this->ok($caught === 2, 'v260: dispatchWithContext aggregates all listener errors');

        // 260-12: request scope lookup of an unknown id throws instead of
        // raising an undefined-array-key warning.
        $scopeContainer = new Container();
        $scopeContainer->register(
            'req.26012',
            static fn (): \stdClass => new \stdClass(),
            [],
            'test',
            ServiceLifetime::REQUEST,
        );
        $scope = $scopeContainer->createRequestScope();
        $this->throws(\LogicException::class, fn (): mixed => $scope->getInstance('req.unknown.26012'), 'v260: unknown request-scope id throws LogicException');
        $scope->close();

        // 260-13: auth rejects URL paths that exceed the security resource bound.
        $authMw = new AuthenticationMiddleware(
            new StaticCredentialProvider(['tok']),
            new AllowScopeAuthorizationPolicy('api', true),
            new BoundedInMemoryReplayProtector(),
            new DefaultSecurityBoundary(),
        );
        $nextH = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Response(200);
            }
        };
        $longReq = new ServerRequest('GET', new Uri('http://a.com/' . str_repeat('a', 500)));
        $longReq = $longReq->withHeader('Authorization', 'Bearer tok');
        $this->ok($authMw->process($longReq, $nextH)->getStatusCode() === 414, 'v260: long URL path is rejected before authorization');

        // 260-14: IPv6 origins are accepted by OriginPolicy.
        $this->ok(
            OriginPolicy::normalizeOrigin('http://[::1]:8080') === 'http://[::1]:8080',
            'v260: OriginPolicy accepts IPv6 origins',
        );

        // 260-15: stale CSRF cookies are re-issued on safe methods and the
        // Secure attribute follows policy, not the request scheme.
        $policy = new SecurityPolicy(csrfSecret: str_repeat('s', 32));
        $csrfMw = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter());
        $csrfReq = new ServerRequest('GET', new Uri('http://a.com/'));
        $csrfResp = $csrfMw->process(
            $csrfReq->withHeader('Cookie', 'ZEF-XSRF-TOKEN=tampered.value'),
            $nextH,
        );
        $cookie = $csrfResp->getHeaderLine('Set-Cookie');
        $this->ok(str_contains($cookie, 'ZEF-XSRF-TOKEN=') && str_contains($cookie, 'Secure'), 'v260: stale CSRF cookie re-issued with Secure per policy');

        // 260-16: telemetry shutdown still exports final lifecycle metrics.
        $metricExports = 0;
        $tel = new Telemetry(
            new NoopTracer(),
            new CounterMeter(),
            new BatchSpanProcessor(new InMemorySpanExporter()),
            new class($metricExports) implements MetricExporterInterface {
                public function __construct(public int &$n) {}

                public function exportMetrics(array $metrics): void
                {
                    ++$this->n;
                }

                public function shutdown(): void {}
            },
            null,
            true,
        );
        $tel->shutdown();
        $this->ok($metricExports >= 1, 'v260: telemetry shutdown exports final metrics');

        // 260-17: OTLP span events use camelCase + KeyValue attributes.
        $exporter = new OtlpHttpJsonExporter('http://collector:4318', []);
        $spanData = new SpanData(
            name: 'op',
            context: new SpanContext('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'),
            parent: null,
            startNs: 1,
            endNs: 2,
            startUnixNano: 1000,
            endUnixNano: 2000,
            status: 'OK',
            statusDescription: null,
            attributes: [],
            events: [['name' => 'evt', 'time_unix_nano' => 42, 'attributes' => ['k' => 'v']]],
        );
        $spanMethod = new \ReflectionMethod($exporter, 'span');
        $spanOut = $spanMethod->invoke($exporter, $spanData);
        $evtOut = $spanOut['events'][0] ?? [];
        $this->ok(
            ($evtOut['timeUnixNano'] ?? null) === '42' && is_int(array_key_first($evtOut['attributes'] ?? [])),
            'v260: OTLP event uses timeUnixNano + KeyValue attributes',
        );
    }

    /**
     * v2.7.0 edge-case regression: one assertion per audited fix, driven
     * through public APIs only (mirrors the audit probe suite).
     */
    private function testV270Regression(): void
    {
        // --- 1/2/3: request-target + protocol + port hardening ----------
        $req = RequestFactory::fromServer(['REQUEST_METHOD' => 'OPTIONS', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_URI' => '*', 'HTTP_HOST' => 'localhost']);
        $this->ok($req->getRequestTarget() === '*', 'v270: asterisk-form target "*" accepted and preserved');

        $req = RequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/22', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'localhost']);
        $this->ok($req->getProtocolVersion() === '1.1', 'v270: garbage protocol "HTTP/22" degrades to 1.1');

        $req = RequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => 'abc']);
        $this->ok($req->getUri()->getPort() === null, 'v270: non-numeric SERVER_PORT ignored (no Uri throw)');

        // --- 4/5: response + URI validation ------------------------------
        $threw = null;

        try {
            new Response(200, [], 'x', "OK\r\nX-Inject: 1");
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: reason-phrase CRLF injection rejected at construction');

        $this->ok(new Uri('http://my_host.example/')->getHost() === 'my_host.example', 'v270: "_" accepted in reg-name hosts');

        // --- 6: fully injectable fromServer ------------------------------
        $req = RequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'localhost'], [], [], null, ['q' => '1'], ['sid' => 'abc']);
        $this->ok($req->getQueryParams() === ['q' => '1'] && $req->getCookieParams() === ['sid' => 'abc'], 'v270: fromServer() query/cookies fully injectable');

        // --- 7: inbound header caps --------------------------------------
        putenv('ZEF_MAX_HEADER_COUNT=8'); // Env::int floor-clamps to min 8
        $threw = null;

        try {
            RequestFactory::fromServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost', 'HTTP_A' => '1', 'HTTP_B' => '2', 'HTTP_C' => '3', 'HTTP_D' => '4', 'HTTP_E' => '5', 'HTTP_F' => '6', 'HTTP_G' => '7', 'HTTP_H' => '8', 'HTTP_I' => '9']);
        } catch (PayloadTooLargeException $e) {
            $threw = $e;
        }
        putenv('ZEF_MAX_HEADER_COUNT');
        $this->ok($threw instanceof PayloadTooLargeException, 'v270: inbound header count cap -> PayloadTooLargeException');

        // --- 8: header value type check (nested array is coercion bait) --
        $threw = null;

        try {
            new Response(200)->withHeader('X-Bad', [['a']]);
        } catch (InvalidHeaderException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof InvalidHeaderException, 'v270: non-scalar header value -> InvalidHeaderException');

        // --- 10: ghost upload downgraded ---------------------------------
        $req = RequestFactory::fromServer(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'], [], [], null, null, null, null, [
            'f' => ['name' => 'x.txt', 'type' => 'text/plain', 'size' => 99, 'tmp_name' => '', 'error' => UPLOAD_ERR_OK],
        ]);
        $files = $req->getUploadedFiles();
        $this->ok(($files['f'] ?? null) !== null && $files['f']->getError() === UPLOAD_ERR_NO_FILE, 'v270: ghost upload (err=OK, no tmp_name) downgraded to NO_FILE');

        // --- 11: negative upload size ------------------------------------
        $threw = null;

        try {
            new UploadedFile(Stream::fromString('x'), -5);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: UploadedFile negative size rejected');

        // --- 12: config reentrancy guard ---------------------------------
        $agg = new ConfigAggregator();
        $agg->addProvider(new readonly class($agg) implements ConfigProviderInterface {
            public function __construct(private ConfigAggregator $agg) {}

            public function getModuleName(): string
            {
                return 'V270Reentrant';
            }

            public function getConfig(): array
            {
                return ['peek' => $this->agg->get('k')];
            }
        });
        $threw = null;

        try {
            $agg->merge();
        } catch (\LogicException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \LogicException, 'v270: reentrant config read during merge() -> LogicException');

        // --- 13/14: PSR-14 stopPropagation --------------------------------
        $ev = new class implements StoppableEventInterface {
            public bool $stopped = true;
            public bool $touched = false;

            public function isPropagationStopped(): bool
            {
                return $this->stopped;
            }
        };
        $d = new EventDispatcher();
        $d->listen($ev::class, function (object $e): void {
            $e->touched = true;
        });
        $d->dispatch($ev);
        $this->ok($ev->touched === false, 'v270: pre-stopped event skips all listeners');

        $ev2 = new class implements StoppableEventInterface {
            public bool $stopped = false;
            public array $log = [];

            public function isPropagationStopped(): bool
            {
                return $this->stopped;
            }
        };
        $d2 = new EventDispatcher();
        $d2->listen($ev2::class, function (object $e): void {
            $e->log[] = 'a';
            $e->stopped = true;
        });
        $d2->listen($ev2::class, function (object $e): void {
            $e->log[] = 'b';
        });
        $d2->dispatch($ev2);
        $this->ok($ev2->log === ['a'], 'v270: mid-chain propagation stop skips later listeners');

        // --- 15: event re-entrancy bounded --------------------------------
        $d3 = new EventDispatcher();
        $reEv = new class {
            public int $depth = 0;
        };
        $d3->listen($reEv::class, function (object $e) use ($d3): void {
            ++$e->depth;
            $d3->dispatch($e);
        });
        $bounded = false;

        try {
            $d3->dispatch($reEv);
        } catch (\Throwable) {
            $bounded = true;
        }
        $this->ok($bounded && $reEv->depth <= 66, 'v270: re-entrant event dispatch bounded (catchable, no OOM)');

        // --- 16: CommandBus idempotency vs event fan-out ------------------
        $fired = 0;
        $eventBus = new EventDispatcher();
        $eventBus->listen(\stdClass::class, function (\stdClass $e) use (&$fired): never {
            ++$fired;

            throw new \RuntimeException('listener boom');
        });
        $bus = new CommandBus(new InMemoryIdempotencyStore(), 3600, $eventBus);
        $bus->register(V270IdemCmd::class, fn (V270IdemCmd $c, CqrsContext $ctx): mixed => new CqrsEventResult('ok', [new \stdClass()]));
        $firstThrew = false;

        try {
            $bus->dispatch(new V270IdemCmd(), CqrsContext::create(idempotencyKey: 'idem-key-1'));
        } catch (\Throwable) {
            $firstThrew = true;
        }
        $replay = null;
        $replayThrew = false;

        try {
            $replay = $bus->dispatch(new V270IdemCmd(), CqrsContext::create(idempotencyKey: 'idem-key-1'));
        } catch (\Throwable) {
            $replayThrew = true;
        }
        $this->ok($firstThrew && !$replayThrew && $replay === 'ok', 'v270: listener failure keeps cached result; replay succeeds');
        $this->ok($fired === 1, 'v270: replay never re-fires event fan-out');

        // --- 17: command re-entrancy bounded ------------------------------
        $bus2 = new CommandBus();
        $bus2->register(V270ReCmd::class, fn (V270ReCmd $c, CqrsContext $ctx): mixed => $bus2->dispatch($c, $ctx));
        $bounded = false;

        try {
            $bus2->dispatch(new V270ReCmd());
        } catch (\Throwable) {
            $bounded = true;
        }
        $this->ok($bounded, 'v270: re-entrant command dispatch bounded (catchable LogicException)');

        // --- 18/19/20/21/22: job worker guards ----------------------------
        $dlqFull = new InMemoryJobQueue(maxSize: 1);
        $dlqFull->enqueue(new JobEnvelope('job-filler-1', 'dlq.pad', null, 0));
        $w = new InProcessJobWorker(new InMemoryJobQueue(), new RetryPolicy(maxAttempts: 1, initialDelayMs: 0), null, $dlqFull);
        $w->register('job.boom', fn (JobEnvelope $j, $c): mixed => throw new \RuntimeException('fail'));
        $wq = new InMemoryJobQueue();
        $w2 = new InProcessJobWorker($wq, new RetryPolicy(maxAttempts: 1, initialDelayMs: 0), null, $dlqFull);
        $w2->register('job.boom', fn (JobEnvelope $j, $c): mixed => throw new \RuntimeException('fail'));
        $wq->enqueue(new JobEnvelope('job-v270-1', 'job.boom', null, 0));
        $noThrow = true;
        $res = null;

        try {
            $res = $w2->processOne();
        } catch (\Throwable) {
            $noThrow = false;
        }
        $this->ok($noThrow && $res instanceof JobResult && $res->completed === false && $res->deadLettered === false, 'v270: FULL DLQ -> terminal result, no throw, honest deadLettered=false');

        $dlq = new InMemoryJobQueue();
        $w3q = new InMemoryJobQueue();
        $w3 = new InProcessJobWorker($w3q, new RetryPolicy(maxAttempts: 1, initialDelayMs: 0), null, $dlq);
        $w3q->enqueue(new JobEnvelope('job-v270-2', 'job.nobody', null, 0));
        $res = $w3->processOne();
        $this->ok($res->deadLettered === true && $dlq->size() === 1, 'v270: unknown type + healthy DLQ -> deadLettered=true');

        $w4q = new InMemoryJobQueue();
        $w4 = new InProcessJobWorker($w4q, new RetryPolicy(maxAttempts: 1), null, null, 1);
        $w4->register('job.h', fn (JobEnvelope $j): mixed => 1);
        $w4q->enqueue(new JobEnvelope('job-v270-3', 'job.h', null, 0));
        $this->ok($w4->run(maxJobs: 5, drain: true) === 1, 'v270: run(drain: true) returns when queue empties');

        $same = new InMemoryJobQueue();
        $threw = null;

        try {
            new InProcessJobWorker($same, new RetryPolicy(), null, $same);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: deadLetterQueue === queue rejected');

        $rq = new InMemoryJobQueue();
        $w5 = new InProcessJobWorker($rq, new RetryPolicy(maxAttempts: 2, initialDelayMs: 0));
        $tries = 0;
        $w5->register('job.flaky', function (JobEnvelope $j) use (&$tries): mixed {
            ++$tries;
            if ($tries === 1) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });
        $rq->enqueue(new JobEnvelope('job-v270-4', 'job.flaky', null, 0));
        $r1 = $w5->processOne();
        $r2 = $w5->processOne();
        $this->ok($r1->completed === false && $r1->willRetry === true && $r2->completed === true, 'v270: retry path reports willRetry=true, then completes');

        // --- 23/24: listener + envelope edges -----------------------------
        $d4 = new EventDispatcher();
        $magic = new class {
            public bool $hit = false;

            public function __call(string $m, array $a): mixed
            {
                $this->hit = true;

                return $a[0] ?? null;
            }
        };
        // [$obj, 'unrealMethod'] passes is_callable() via __call; EventRegistration
        // must not leak ReflectionException and must invoke context-less.
        $noThrow = true;

        try {
            $d4->listen(V270MagicEvent::class, [$magic, 'handleAnything']);
            $d4->dispatch(new V270MagicEvent());
        } catch (\ReflectionException) {
            $noThrow = false;
        }
        $this->ok($noThrow && $magic->hit, 'v270: object-with-__call listener invoked context-less, no ReflectionException');

        $threw = null;

        try {
            new MessageEnvelope('msg-v270-1', 't.a', null, ['bad' => 123]);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: non-string envelope header -> InvalidArgumentException');

        // --- 25: serializer depth symmetric -------------------------------
        $s = new JsonMessageSerializer();
        $mk = static function (int $n): array {
            $a = ['leaf' => 1];
            for ($i = 0; $i < $n; ++$i) {
                $a = ['n' => $a];
            }

            return $a;
        };
        $round = $s->deserialize($s->serialize(new MessageEnvelope('msg-v270-2', 't.a', $mk(400))));
        $this->ok($round instanceof MessageEnvelope && is_array($round->payload), 'v270: serializer round-trips 400-deep payload');
        $threw = null;

        try {
            $s->serialize(new MessageEnvelope('msg-v270-3', 't.a', $mk(600)));
        } catch (\JsonException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \JsonException, 'v270: over-depth payload -> catchable JsonException (symmetric 512)');

        // --- 26/27: security env + replay-id bound ------------------------
        $policy = SecurityPolicy::fromEnvironment();
        $this->ok($policy->rateLimitMaxRequests >= 1, 'v270: security policy sane defaults');

        // --- 28: counter monotonicity -------------------------------------
        $meter = new CounterMeter();
        $threw = null;

        try {
            $meter->increment('c.neg', -1);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: negative counter delta rejected');
        $meter->increment('c.max', PHP_INT_MAX);
        $meter->increment('c.max', 100);
        $snap = $meter->snapshot();
        $this->ok(($snap['c.max|[]']['count'] ?? null) === PHP_INT_MAX, 'v270: counter overflow clamps at PHP_INT_MAX');
        $threw = null;

        try {
            $meter->increment('c.nan', NAN);
        } catch (\InvalidArgumentException $e) {
            $threw = $e;
        }
        $this->ok($threw instanceof \InvalidArgumentException, 'v270: NaN counter delta rejected');

        // --- 29: sanitizer scrubbing ---------------------------------------
        $this->ok(TelemetrySanitizer::string("\xB1\x31") === '?1', 'v270: invalid UTF-8 scrubbed by sanitizer');
        $this->ok(TelemetrySanitizer::value(NAN) === 'NAN', 'v270: NaN attribute mapped to string form');
        $this->ok(TelemetrySanitizer::attributes(['password' => 'x', 'ok' => 'y']) === ['ok' => 'y'], 'v270: sensitive attribute keys dropped');

        // --- 30: debug error redaction -------------------------------------
        $dbg = new ErrorResponseFactory(true)->create(500, 'secret SQL details: password=hunter2', 'c1');
        $prod = new ErrorResponseFactory(false)->create(500, 'secret SQL details: password=hunter2', 'c2');
        $dbgBody = $dbg->getBody()->getContents();
        $prodBody = $prod->getBody()->getContents();
        $this->ok(str_contains($dbgBody, 'password=[REDACTED]') && !str_contains($dbgBody, 'hunter2'), 'v270: dev-mode error message redacts credential values');
        $this->ok(str_contains($prodBody, 'An error occurred') && !str_contains($prodBody, 'hunter2'), 'v270: production error body stays generic');
    }

    private function suite(string $key, string $name, callable $test): void
    {
        if (!$this->matchesFilter($key, $name)) {
            return;
        }
        ++$this->matchedSuites;

        try {
            $test();
            $this->out("[PASS] {$name}");
        } catch (\Throwable $e) {
            ++$this->failed;
            $this->out("[FAIL] {$name}: {$e->getMessage()}");
        }
    }

    private function matchesFilter(string $key, string $name): bool
    {
        if ($this->filter === null) {
            return true;
        }
        if ($this->exactMatch) {
            return strtolower($key) === $this->filter;
        }

        return str_contains(strtolower($key), $this->filter)
            || str_contains(strtolower($name), $this->filter);
    }

    private function out(string $line): void
    {
        echo $line . (PHP_SAPI === 'cli' ? "\n" : "<br>\n");
    }

    private function banner(bool $html): void
    {
        if ($html) {
            echo '<!doctype html><html><head><meta charset="utf-8"><title>ZEF self-test</title>'
                . '<style>body{font-family:system-ui;background:#111;color:#eee;padding:24px}pre{white-space:pre-wrap}</style>'
                . '</head><body><pre>';
        }
        $filterSuffix = $this->filter !== null ? " (filter: {$this->filter})" : '';
        echo 'ZEF Framework v' . ZefVersion::VERSION . " — SELF TEST{$filterSuffix}\n";
    }

    private function summary(bool $html): void
    {
        $this->out("\nPASSED: {$this->passed}  FAILED: {$this->failed}");
        if ($html) {
            echo '</pre></body></html>';
        }
        $GLOBALS['zef_test_summary'] = ['passed' => $this->passed, 'failed' => $this->failed];
    }
}

// v2.7.0 suite fixtures (concrete classes are required by the buses).
