<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.35.0 — Regression coverage: service middleware/interceptors
 * (roadmap "Container System / Target Enterprise" checklist item).
 *
 * Semantics locked by this suite:
 * - middleware wraps CONSTRUCTION (cache-miss instantiation only), like the
 *   resolving/resolved events;
 * - higher priority runs first (outermost), ties keep registration order;
 * - short-circuit (no $next() call) replaces the instance but still passes
 *   through resolved listeners and per-lifetime caching;
 * - resolving listeners fire before the onion, resolved listeners after it;
 * - middleware exceptions are wrapped in ServiceResolutionException;
 * - the pipeline is sealed at validateAndFreeze() (no mutation after freeze).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ResolutionContext;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\ServiceResolutionException;

/**
 * @internal
 */
final class ContainerServiceMiddlewareTest extends TestCase
{
    /** Middleware wraps construction and can post-process the instance. */
    public function testMiddlewareWrapsConstructionAndSeesInstanceId(): void
    {
        $container = new Container();
        $seen = [];
        $container->addServiceMiddleware(function (string $id, \Closure $next) use (&$seen): mixed {
            $seen[] = "before:{$id}";

            return $next();
        });
        $container->register('svc.plain', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        $container->get('svc.plain');
        self::assertSame(['before:svc.plain'], $seen);
    }

    /** Higher priority runs first (outermost); equal priorities keep registration order. */
    public function testPriorityOrderingIsOutermostFirst(): void
    {
        $container = new Container();
        $trace = [];
        $mk = static function (string $name) use (&$trace): callable {
            return static function (string $id, \Closure $next) use ($name, &$trace): mixed {
                $trace[] = "in:{$name}";

                return $next();
            };
        };
        $container->addServiceMiddleware($mk('low'), 1);
        $container->addServiceMiddleware($mk('high'), 10);
        $container->addServiceMiddleware($mk('mid'), 5);
        $container->addServiceMiddleware($mk('tie-a'), 5);
        $container->addServiceMiddleware($mk('tie-b'), 5);
        $container->register('svc.p', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        $container->get('svc.p');
        self::assertSame(
            ['in:high', 'in:mid', 'in:tie-a', 'in:tie-b', 'in:low'],
            $trace,
            'higher priority = outermost; ties keep registration order',
        );
    }

    /** Short-circuit: returning without $next() replaces the instance and caches it. */
    public function testShortCircuitReplacesInstanceAndStillCaches(): void
    {
        $container = new Container();
        $replacement = new \stdClass();
        $factoryRan = false;
        $resolvedSeen = [];
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => $replacement);
        $container->onResolved(static function (string $id, mixed $instance) use (&$resolvedSeen): void {
            $resolvedSeen[] = $instance;
        });
        $container->register('svc.swap', static function () use (&$factoryRan): \stdClass {
            $factoryRan = true;

            return new \stdClass();
        });
        $container->validateAndFreeze();

        $first = $container->get('svc.swap');
        $second = $container->get('svc.swap');
        self::assertFalse($factoryRan, 'the default construction is never reached on short-circuit');
        self::assertSame($replacement, $first);
        self::assertSame($replacement, $second, 'the short-circuited instance follows singleton caching');
        self::assertSame([$replacement], $resolvedSeen, 'resolved listeners still observe the replacement');
    }

    /** Wrapping middleware can decorate the constructed instance. */
    public function testWrappingMiddlewareDecoratesInstance(): void
    {
        $container = new Container();
        $container->addServiceMiddleware(static function (string $id, \Closure $next): \stdClass {
            $inner = $next();
            \assert($inner instanceof \stdClass);
            $inner->marked = true;

            return $inner;
        });
        $container->register('svc.marked', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        $marked = $container->get('svc.marked');
        \assert($marked instanceof \stdClass);
        self::assertTrue($marked->marked);
    }

    /** Middleware fires only on instantiation (same rule as the events). */
    public function testMiddlewareFiresOnlyOnInstantiation(): void
    {
        $container = new Container();
        $calls = [];
        $container->addServiceMiddleware(function (string $id, \Closure $next) use (&$calls): mixed {
            $calls[] = $id;

            return $next();
        });
        $container->register('svc.once', static fn (): \stdClass => new \stdClass());
        $container->register('tr.fresh', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $container->validateAndFreeze();

        $container->get('svc.once');
        $container->get('svc.once');
        $container->get('tr.fresh');
        $container->get('tr.fresh');
        self::assertSame(['svc.once', 'tr.fresh', 'tr.fresh'], $calls);
    }

    /** Request-scoped services run middleware per scope instantiation. */
    public function testRequestScopedMiddlewareRunsPerScope(): void
    {
        $container = new Container();
        $count = 0;
        $container->addServiceMiddleware(function (string $id, \Closure $next) use (&$count): mixed {
            ++$count;

            return $next();
        });
        $container->register('req.page', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->validateAndFreeze();

        $scopeOne = $container->createRequestScope();
        $scopeOne->get('req.page');
        $scopeOne->get('req.page');
        $scopeTwo = $container->createRequestScope();
        $scopeTwo->get('req.page');
        self::assertSame(2, $count, 'once per scope instantiation, not per get()');
    }

    /** Resolving listeners fire before the onion, resolved listeners after it. */
    public function testEventsBracketTheMiddlewareOnion(): void
    {
        $container = new Container();
        $trace = [];
        $container->onResolving(function (string $id) use (&$trace): void {
            $trace[] = "resolving:{$id}";
        });
        $container->addServiceMiddleware(function (string $id, \Closure $next) use (&$trace): mixed {
            $trace[] = "mw-in:{$id}";
            $instance = $next();
            $trace[] = "mw-out:{$id}";

            return $instance;
        });
        $container->onResolved(function (string $id) use (&$trace): void {
            $trace[] = "resolved:{$id}";
        });
        $container->register('svc.ordered', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        $container->get('svc.ordered');
        self::assertSame(
            ['resolving:svc.ordered', 'mw-in:svc.ordered', 'mw-out:svc.ordered', 'resolved:svc.ordered'],
            $trace,
        );
    }

    /** Middleware exceptions are wrapped in ServiceResolutionException (PSR-11 safe). */
    public function testMiddlewareExceptionIsWrappedAsContainerException(): void
    {
        $container = new Container();
        $container->addServiceMiddleware(static function (string $id, \Closure $next): never {
            throw new \RuntimeException('boom from middleware');
        });
        $container->register('svc.boom', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        try {
            $container->get('svc.boom');
            self::fail('middleware failure must surface as a container exception');
        } catch (ContainerExceptionInterface $e) {
            self::assertInstanceOf(ServiceResolutionException::class, $e);
            self::assertStringContainsString('service middleware failed: boom from middleware', $e->getMessage());
        }
    }

    /** The pipeline is sealed at freeze: no middleware can be added afterwards. */
    public function testPipelineSealedAfterFreeze(): void
    {
        $container = new Container();
        $container->register('svc.frozen', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Container is frozen.');
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => $next());
    }

    /** Middleware applies to unfrozen containers too (dev parity with production). */
    public function testMiddlewareAppliesBeforeFreeze(): void
    {
        $container = new Container();
        $touched = false;
        $container->addServiceMiddleware(function (string $id, \Closure $next) use (&$touched): mixed {
            $touched = true;

            return $next();
        });
        $container->register('svc.dev', static fn (): \stdClass => new \stdClass());

        $container->get('svc.dev');
        self::assertTrue($touched);
    }

    /** No middleware registered: zero overhead fast path (container behaves identically). */
    public function testNoMiddlewareFastPathUnchanged(): void
    {
        $container = new Container();
        $container->register('svc.fast', static fn (): \stdClass => new \stdClass());
        $container->register('dep.inner', static fn (): \stdClass => new \stdClass());
        $container->register('svc.outer', static fn (ResolutionContext $ctx, \stdClass $dep): object => (object) ['inner' => $dep], ['dep.inner']);
        $container->validateAndFreeze();

        $outer = $container->get('svc.outer');
        \assert($outer instanceof \stdClass);
        self::assertSame($outer->inner, $container->get('dep.inner'));
        self::assertSame(0, $container->serviceMiddlewareCount());
    }

    /** serviceMiddlewareCount() reflects registrations. */
    public function testServiceMiddlewareCount(): void
    {
        $container = new Container();
        self::assertSame(0, $container->serviceMiddlewareCount());
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => $next());
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => $next(), 3);
        self::assertSame(2, $container->serviceMiddlewareCount());
    }

    /** Middleware can short-circuit request-scoped services per scope. */
    public function testShortCircuitOnRequestScopedService(): void
    {
        $container = new Container();
        $override = new \stdClass();
        $container->addServiceMiddleware(
            static fn (string $id, \Closure $next): mixed => $id === 'req.override' ? $override : $next(),
        );
        $container->register('req.override', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->register('req.normal', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->validateAndFreeze();

        $scope = $container->createRequestScope();
        self::assertSame($override, $scope->get('req.override'));
        self::assertInstanceOf(\stdClass::class, $scope->get('req.normal'));
        self::assertNotSame($override, $scope->get('req.normal'));
    }
}
