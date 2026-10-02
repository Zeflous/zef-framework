<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.35.0 — Regression coverage: runtime implicit lifetime-capture guard.
 *
 * The compile-time pass (DependencyGraphValidator::assertSingletonClosure)
 * already rejects DECLARED non-singleton dependencies of a singleton. The
 * remaining hole was IMPLICIT capture: a singleton factory body pulling a
 * REQUEST/TRANSIENT service through $ctx->get() at runtime.
 *
 * Pre-fix behaviour (the bug): inside an active request scope the pull
 * succeeded and the request-scoped instance was captured in the singleton
 * store — under a long-running RoadRunner worker the SAME instance (with
 * request #1 state) was silently served to every later request: a
 * cross-request state leak with no error anywhere.
 *
 * Post-fix behaviour: resolution throws ServiceResolutionException naming
 * the owning singleton, the captured lifetime and the captured service.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ResolutionContext;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\ServiceResolutionException;

/**
 * @internal
 */
final class ContainerLifetimeCaptureGuardTest extends TestCase
{
    /** THE leak scenario: singleton factory body pulls a request-scoped service. */
    public function testSingletonFactoryBodyCannotCaptureRequestScopedService(): void
    {
        $container = new Container();
        $counter = 0;
        $container->register('req.session', static function () use (&$counter): \stdClass {
            $obj = new \stdClass();
            $obj->requestId = ++$counter;

            return $obj;
        }, [], null, ServiceLifetime::REQUEST);
        $container->register('svc.leaky', static fn (ResolutionContext $ctx): object => (object) ['session' => $ctx->get('req.session')]);
        $container->validateAndFreeze();

        try {
            $scopeOne = $container->createRequestScope();
            $scopeOne->get('svc.leaky');
            self::fail('Pre-fix this silently captured the request-scoped service into the singleton store.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('implicit lifetime capture', $e->getMessage());
            self::assertStringContainsString("singleton 'svc.leaky'", $e->getMessage());
            self::assertStringContainsString("request service 'req.session'", $e->getMessage());
        }
    }

    /** TRANSIENT capture is the same bug class: the transient stops being transient. */
    public function testSingletonFactoryBodyCannotCaptureTransientService(): void
    {
        $container = new Container();
        $container->register('tr.note', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $container->register('svc.holder', static fn (ResolutionContext $ctx): object => (object) ['note' => $ctx->get('tr.note')]);
        $container->validateAndFreeze();

        try {
            $container->get('svc.holder');
            self::fail('A singleton must not implicitly capture a transient service.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('implicit lifetime capture', $e->getMessage());
            self::assertStringContainsString("transient service 'tr.note'", $e->getMessage());
        }
    }

    /** Declared deps of a singleton are compile-guarded; the runtime guard also holds unfrozen. */
    public function testUnfrozenDeclaredSingletonToRequestDepThrowsAtResolution(): void
    {
        $container = new Container();
        $container->register('req.cfg', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        // Declared dependency — would be rejected by the compile pass at freeze time.
        $container->register('svc.declared', static fn (ResolutionContext $ctx, \stdClass $dep): object => (object) ['cfg' => $dep], ['req.cfg']);
        // NOT frozen: the runtime guard is the only line of defence here.
        $this->expectException(ServiceResolutionException::class);
        $this->expectExceptionMessage('implicit lifetime capture');
        $container->get('svc.declared');
    }

    /** Transitive implicit capture (singleton → transient → request) is caught at the first violation. */
    public function testTransitiveImplicitCaptureIsCaught(): void
    {
        $container = new Container();
        $container->register('req.identity', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->register('tr.middle', static fn (ResolutionContext $ctx): object => (object) ['id' => $ctx->get('req.identity')], [], null, ServiceLifetime::TRANSIENT);
        $container->register('svc.root', static fn (ResolutionContext $ctx): object => (object) ['middle' => $ctx->get('tr.middle')]);
        $container->validateAndFreeze();

        $scope = $container->createRequestScope();

        try {
            $scope->get('svc.root');
            self::fail('The singleton would capture the transient (and through it, the request-scoped service).');
        } catch (ServiceResolutionException $e) {
            // The guard fails fast at the FIRST violation on the resolution path:
            // the transient hop is already a capture, so it never descends further.
            self::assertStringContainsString("singleton 'svc.root'", $e->getMessage());
            self::assertStringContainsString("transient service 'tr.middle'", $e->getMessage());
        }
    }

    /** Legit pattern stays legal: singleton → singleton chain resolves fine. */
    public function testSingletonToSingletonChainStillResolves(): void
    {
        $container = new Container();
        $container->register('svc.inner', static fn (): \stdClass => new \stdClass());
        $container->register('svc.outer', static fn (ResolutionContext $ctx, \stdClass $dep): object => (object) ['inner' => $dep], ['svc.inner']);
        $container->validateAndFreeze();

        $outer = $container->get('svc.outer');
        \assert($outer instanceof \stdClass);
        self::assertInstanceOf(\stdClass::class, $outer->inner);
        self::assertSame($outer, $container->get('svc.outer'), 'singleton caching is untouched');
    }

    /** Legit pattern stays legal: a transient root may pull request-scoped deps inside a scope. */
    public function testTransientRootMayPullRequestScopedDepInsideScope(): void
    {
        $container = new Container();
        $container->register('req.ctx', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->register('action.handler', static fn (ResolutionContext $ctx, \stdClass $dep): object => (object) ['ctx' => $dep], ['req.ctx'], null, ServiceLifetime::TRANSIENT);
        $container->validateAndFreeze();

        $scope = $container->createRequestScope();
        $first = $scope->get('action.handler');
        $second = $scope->get('action.handler');
        \assert($first instanceof \stdClass);
        self::assertNotSame($first, $second, 'transient stays transient');
        self::assertSame($scope->get('req.ctx'), $first->ctx, 'request-scoped dep is shared within the scope');
    }

    /** Legit pattern stays legal: a request-scoped root inside its scope. */
    public function testRequestScopedRootInsideScopeResolves(): void
    {
        $container = new Container();
        $container->register('req.view', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $container->validateAndFreeze();

        $scope = $container->createRequestScope();
        $view = $scope->get('req.view');
        self::assertSame($view, $scope->get('req.view'), 'request scope caching is untouched');
    }

    /** warmSingletons() fail-fasts when a singleton factory body pulls a non-singleton. */
    public function testWarmSingletonsSurfacesImplicitCapture(): void
    {
        $container = new Container();
        $container->register('tr.thing', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $container->register('svc.warm', static fn (ResolutionContext $ctx): object => (object) ['thing' => $ctx->get('tr.thing')]);
        $container->validateAndFreeze();

        try {
            $container->warmSingletons();
            self::fail('Warming must surface the implicit capture instead of caching a captured instance.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString('implicit lifetime capture', $e->getMessage());
        }
    }

    /** The guard message names the INNERMOST singleton — the direct capturer. */
    public function testGuardNamesInnermostCapturingSingleton(): void
    {
        $container = new Container();
        $container->register('tr.pony', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $container->register('svc.mid', static fn (ResolutionContext $ctx): object => (object) ['pony' => $ctx->get('tr.pony')]);
        $container->register('svc.top', static fn (ResolutionContext $ctx, \stdClass $dep): object => (object) ['mid' => $dep], ['svc.mid']);
        $container->validateAndFreeze();

        try {
            $container->get('svc.top');
            self::fail('Nested singletons must not capture transients either.');
        } catch (ServiceResolutionException $e) {
            self::assertStringContainsString("singleton 'svc.mid'", $e->getMessage());
        }
    }
}
