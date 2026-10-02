<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Regression coverage: container-wide singleton
 * subtree tracking (audit #343, #345, #347).
 *
 * The v2.35.0 implicit-capture guard lives on the ResolutionContext, so a
 * factory body pulling services through the ROOT container (a fresh
 * context per call) bypassed it. These tests pin the closed side channel:
 * a non-singleton resolved through the root container while a singleton
 * construction is open on the same fiber fails fast, legal shapes stay
 * legal, synthetic machinery ids never reach the middleware onion, and
 * the null-construction diagnostics name the actual culprit.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\ServiceResolutionException;

/**
 * @internal
 */
final class ContainerRootPullCaptureTest extends TestCase
{
    /** THE #343 leak: singleton factory root-pulls a TRANSIENT via $container->get(). */
    public function testSingletonFactoryRootPullOfTransientIsRejected(): void
    {
        $container = new Container();
        $counter = 0;
        $container->register('tr.note', static function () use (&$counter): \stdClass {
            $obj = new \stdClass();
            $obj->seq = ++$counter;

            return $obj;
        }, [], null, 'transient');
        $container->register('svc.holder', static function () use ($container): \stdClass {
            $obj = new \stdClass();
            $obj->note = $container->get('tr.note');

            return $obj;
        });
        $container->validateAndFreeze();

        try {
            $container->get('svc.holder');
            self::fail('A root-container pull must not silently capture a transient into a singleton.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('implicit lifetime capture', $e->getMessage());
            self::assertStringContainsString("singleton 'svc.holder'", $e->getMessage());
            self::assertStringContainsString('via the root container', $e->getMessage());
        }
    }

    /** Legit shape: a top-level transient factory root-pulling another transient stays legal. */
    public function testTopLevelTransientRootPullStaysLegal(): void
    {
        $container = new Container();
        $container->register('tr.leaf', static fn (): \stdClass => new \stdClass(), [], null, 'transient');
        $container->register('tr.root', static function () use ($container): \stdClass {
            $obj = new \stdClass();
            $obj->leaf = $container->get('tr.leaf');

            return $obj;
        }, [], null, 'transient');
        $container->validateAndFreeze();

        $first = $container->get('tr.root');
        $second = $container->get('tr.root');
        assert($first instanceof \stdClass && $second instanceof \stdClass);

        self::assertNotSame($first, $second, 'transients stay transient');
        self::assertNotSame($first->leaf, $second->leaf, 'each construction gets its own fresh leaf');
    }

    /** Legit shape: singleton -> singleton through the root container stays legal. */
    public function testSingletonToSingletonRootPullStaysLegal(): void
    {
        $container = new Container();
        $container->register('svc.inner', static fn (): \stdClass => new \stdClass());
        $container->register('svc.outer', static function () use ($container): \stdClass {
            $obj = new \stdClass();
            $obj->inner = $container->get('svc.inner');

            return $obj;
        });
        $container->validateAndFreeze();

        $outer = $container->get('svc.outer');
        assert($outer instanceof \stdClass);
        self::assertSame($outer->inner, $container->get('svc.inner'), 'the pulled singleton is the shared instance');
    }

    /** Nested tracking: after a child singleton finishes, the parent subtree still guards. */
    public function testParentSubtreeStillTrackedAfterChildCompletes(): void
    {
        $container = new Container();
        $container->register('svc.child', static fn (): \stdClass => new \stdClass());
        $container->register('svc.parent', static function () use ($container): \stdClass {
            $obj = new \stdClass();
            $obj->child = $container->get('svc.child');
            $obj->note = $container->get('tr.note');

            return $obj;
        }, ['svc.child']);
        $container->register('tr.note', static fn (): \stdClass => new \stdClass(), [], null, 'transient');
        $container->validateAndFreeze();

        try {
            $container->get('svc.parent');
            self::fail('The parent singleton must still be tracked after its child completed.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString("singleton 'svc.parent'", $e->getMessage());
        }
    }

    /** THE #345 leak: singleton factory root-pulls a TRANSIENT namespace fallback. */
    public function testTransientFallbackCannotBeCapturedFromSingletonFactory(): void
    {
        $container = new Container();
        $counter = 0;
        $container->registerNamespaceFallback('tr\\', static function (string $id) use (&$counter): \stdClass {
            $obj = new \stdClass();
            $obj->seq = ++$counter;

            return $obj;
        }, 'transient');
        $container->register('svc.holder', static function () use ($container): \stdClass {
            $obj = new \stdClass();
            $obj->note = $container->get('tr\note');

            return $obj;
        });
        $container->validateAndFreeze();

        try {
            $container->get('svc.holder');
            self::fail('A transient fallback must not be silently captured into a singleton.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('implicit lifetime capture', $e->getMessage());
            self::assertStringContainsString('fallback service', $e->getMessage());
        }
    }

    /** A singleton FALLBACK under construction guards registry pulls the same way. */
    public function testSingletonFallbackRootPullOfRegistryTransientIsRejected(): void
    {
        $container = new Container();
        $container->register('tr.registry', static fn (): \stdClass => new \stdClass(), [], null, 'transient');
        $container->registerNamespaceFallback('fb\\', static function (Container $c): \stdClass {
            $obj = new \stdClass();
            $obj->leaf = $c->get('tr.registry');

            return $obj;
        });
        $container->validateAndFreeze();

        try {
            $container->get('fb\thing');
            self::fail('A constructing fallback singleton must not capture a registry transient.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString("singleton 'fb\\thing'", $e->getMessage());
            self::assertStringContainsString('via the root container', $e->getMessage());
        }
    }

    /** #347a: synthetic decorator plumbing ids never reach userland middleware. */
    public function testMiddlewareNeverSeesSyntheticDecoratorIds(): void
    {
        $container = new Container();
        $seen = [];
        $container->register('svc.dec', static fn (): \stdClass => new \stdClass());
        $container->decorate('svc.dec', static fn (object $inner): object => new readonly class($inner) {
            public function __construct(public object $inner) {}
        });
        $container->addServiceMiddleware(static function (string $id, \Closure $next) use (&$seen): mixed {
            $seen[] = $id;

            return $next();
        });
        $container->validateAndFreeze();
        $container->get('svc.dec');

        self::assertSame(['svc.dec'], $seen, 'only the public id may pass through the onion');
    }

    /** #347b: a null short-circuit names the middleware, not the factory. */
    public function testMiddlewareNullShortCircuitMessageNamesMiddleware(): void
    {
        $container = new Container();
        $factoryRan = false;
        $container->register('svc.null', static function () use (&$factoryRan): \stdClass {
            $factoryRan = true;

            return new \stdClass();
        });
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => null);
        $container->validateAndFreeze();

        try {
            $container->get('svc.null');
            self::fail('A null short-circuit must fail loudly.');
        } catch (ServiceResolutionException $e) {
            self::assertFalse($factoryRan, 'the factory must not have run');
            self::assertStringContainsString('service middleware returned null', $e->getMessage());
        }
    }

    /** Back-compat: without middleware, a null factory keeps the original message. */
    public function testFactoryNullMessageUnchangedWithoutMiddleware(): void
    {
        $container = new Container();
        $container->register('svc.nullish', static fn (): mixed => null);
        $container->validateAndFreeze();

        try {
            $container->get('svc.nullish');
            self::fail('A null factory result must fail loudly.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('factory returned null.', $e->getMessage());
        }
    }

    /** Fiber isolation: a suspended construction only guards its own fiber. */
    public function testCaptureGuardIsFiberIsolated(): void
    {
        $container = new Container();
        $container->register('tr.warm', static fn (): \stdClass => new \stdClass(), [], null, 'transient');
        $container->register('tr.late', static fn (): \stdClass => new \stdClass(), [], null, 'transient');
        $container->register('svc.suspend', static function () use ($container): \stdClass {
            \Fiber::suspend();
            $late = $container->get('tr.late');
            assert($late instanceof \stdClass);

            return $late;
        });
        $container->validateAndFreeze();

        $caught = null;
        $fiber = new \Fiber(static function () use ($container, &$caught): void {
            try {
                $container->get('svc.suspend');
            } catch (ServiceResolutionException $e) {
                $caught = $e;
            }
        });
        $fiber->start();

        // While the fiber's singleton construction is open, ANOTHER fiber (main) stays free.
        $warm = $container->get('tr.warm');
        self::assertInstanceOf(\stdClass::class, $warm);

        $fiber->resume();
        self::assertNotNull($caught, 'the same fiber resuming its own construction must not root-pull a transient');
        self::assertStringContainsString("singleton 'svc.suspend'", $caught->getMessage());
    }
}
