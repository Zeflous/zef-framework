<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Regression coverage: namespace-fallback
 * construction safety (audit #344, #346).
 *
 * Before the fix, NamespaceFallbackResolver::resolve() invoked the factory
 * raw: no initialization guard, no depth budget, no cycle detection —
 * while the calling convention HANDS the container to the factory, making
 * self-pull a first-class pattern. A self-pulling fallback factory
 * recursed without bound (memory-exhaustion fatal under long-running
 * workers). These tests pin the guarded contract.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\ConcurrentServiceInitializationException;
use Zef\Framework\Exception\ServiceResolutionException;

/**
 * @internal
 */
final class ContainerFallbackReentrancyTest extends TestCase
{
    /** THE #344 crash loop: a fallback factory pulling its own id via the container. */
    public function testFallbackSelfPullFailsFastWithConcurrentInitialization(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('fb\\', static function (Container $c, string $id): \stdClass {
            $instance = $c->get($id);
            assert($instance instanceof \stdClass);

            return $instance;
        });
        $container->validateAndFreeze();

        try {
            $container->get('fb\widget');
            self::fail('Same-id fallback re-entry must fail fast instead of recursing.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('namespace fallback factory failed', $e->getMessage());
            self::assertStringContainsString('Concurrent initialization detected', $e->getMessage());
            self::assertInstanceOf(
                ConcurrentServiceInitializationException::class,
                $e->getPrevious(),
                'the guard exception stays reachable as the previous link',
            );
        }
    }

    /** Unbounded recursion through GENERATED ids hits the depth budget instead of looping forever. */
    public function testUnboundedFallbackRecursionHitsDepthBudget(): void
    {
        $container = new Container();
        // Every pull manufactures a FRESH id under the same prefix — the
        // same-id initialization guard can never fire, so only the depth
        // budget stands between this factory and an unbounded recursion.
        $n = 0;
        $container->registerNamespaceFallback('fb\\', static function (Container $c) use (&$n): \stdClass {
            $instance = $c->get('fb\deep\\' . (++$n));
            assert($instance instanceof \stdClass);

            return $instance;
        });
        $container->validateAndFreeze();

        try {
            $container->get('fb\deep\0');
            self::fail('Unbounded fallback recursion must hit the depth budget.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('namespace fallback factory failed', $e->getMessage());
            self::assertStringContainsString('depth exceeds configured safety budget', $e->getMessage());
        }
    }

    /** A cycle over FINITE fallback ids is caught even earlier — by the shared init guard. */
    public function testMutuallyRecursiveFallbackPrefixesHitInitGuard(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('fb\a\\', static function (Container $c): \stdClass {
            $instance = $c->get('fb\b\hop');
            assert($instance instanceof \stdClass);

            return $instance;
        });
        $container->registerNamespaceFallback('fb\b\\', static function (Container $c): \stdClass {
            $instance = $c->get('fb\a\hop');
            assert($instance instanceof \stdClass);

            return $instance;
        });
        $container->validateAndFreeze();

        try {
            $container->get('fb\a\start');
            self::fail('Ping-pong fallback recursion must fail fast on the revisited id.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('Concurrent initialization detected', $e->getMessage());
        }
    }

    /** #346: the service-middleware onion now wraps fallback construction too. */
    public function testMiddlewareWrapsFallbackConstruction(): void
    {
        $container = new Container();
        $seen = [];
        $container->registerNamespaceFallback('fb\\', static fn (Container $c, string $id): \stdClass => new \stdClass());
        $container->addServiceMiddleware(static function (string $id, \Closure $next) use (&$seen): mixed {
            $seen[] = $id;

            return $next();
        });
        $container->validateAndFreeze();

        $instance = $container->get('fb\thing');
        self::assertInstanceOf(\stdClass::class, $instance);
        self::assertSame(['fb\thing'], $seen, 'fallback construction passes through the onion');
    }

    /** Fallback singleton caching still works with middleware registered. */
    public function testFallbackSingletonCachingUnchangedWithMiddleware(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('fb\\', static fn (Container $c, string $id): \stdClass => new \stdClass());
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): mixed => $next());
        $container->validateAndFreeze();

        $first = $container->get('fb\once');
        $second = $container->get('fb\once');
        self::assertSame($first, $second, 'singleton fallbacks stay cached');
    }

    /** A short-circuiting middleware may replace a fallback instance. */
    public function testMiddlewareShortCircuitReplacesFallbackInstance(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('fb\\', static fn (Container $c, string $id): \stdClass => new \stdClass());
        $replacement = new \stdClass();
        $container->addServiceMiddleware(static fn (string $id, \Closure $next): object => $replacement);
        $container->validateAndFreeze();

        self::assertSame($replacement, $container->get('fb\decorated'));
        self::assertSame($replacement, $container->get('fb\decorated'), 'the replacement is what gets cached');
    }

    /** Depth budget is fiber-scoped: concurrent fibers never exhaust each other. */
    public function testFallbackDepthBudgetIsFiberIsolated(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('fb\\', static function (Container $c, string $id): \stdClass {
            if ($id === 'fb\a' && \Fiber::getCurrent() instanceof \Fiber) {
                \Fiber::suspend();
            }

            return new \stdClass();
        });
        $container->validateAndFreeze();

        // Results are collected by reference: fiber return-value plumbing
        // differs across PHP builds, side effects do not.
        $insideFiber = null;
        $fiber = new \Fiber(static function () use ($container, &$insideFiber): void {
            $insideFiber = $container->get('fb\a');
        });
        $fiber->start();

        // While the fiber sits mid-construction, the main fiber resolves independently.
        $main = $container->get('fb\main');
        self::assertInstanceOf(\stdClass::class, $main);

        $fiber->resume();
        self::assertInstanceOf(\stdClass::class, $insideFiber, 'the suspended fiber completes its own resolution');
    }
}
