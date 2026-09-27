<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix fase 2b (v2.14.3): Application\Container core.
 * Adversarial scenarios mined from escaped Infection mutants of
 * AutowireCompilerPass (75), Container (68) and ContainerResolver (40):
 * reuse/reuse-dedup semantics, cycle-chain trimming, diamond pop/finally
 * integrity, variadic collection typing, config-literal injection edges,
 * contextual via-IDs, decoration budgets, deferred providers, namespace
 * fallbacks and request-scope state preservation.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Zef\Framework\Autowiring\Inject;
use Zef\Framework\Autowiring\Target;
use Zef\Framework\Autowiring\Value;
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;
use Zef\Framework\Container\BootableProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ContainerResolver;
use Zef\Framework\Container\DeferrableProviderInterface;
use Zef\Framework\Container\InitializationGuard;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\ServiceProviderInterface;
use Zef\Framework\Container\ServiceRegistrarInterface;
use Zef\Framework\Container\ServiceRegistry;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * @internal
 */
final class EdgeMatrixContainerTest extends TestCase
{
    // =====================================================================
    // AutowireCompilerPass — constructor, process() guards, reuse ledger
    // =====================================================================

    public function testPassConstructorRejectsUnknownLifetime(): void
    {
        try {
            new AutowireCompilerPass(lifetime: 'immortal');
            self::fail('unknown lifetime must be rejected at construction.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Unknown service lifetime 'immortal'.", $e->getMessage());
        }
    }

    public function testPassOnFrozenContainerThrowsLogicException(): void
    {
        $c = new Container();
        $c->register('ct2b.one', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Container is frozen.');
        new AutowireCompilerPass()->process($c, [Ct2bLeaf::class]);
    }

    public function testPassRejectsNonStringAndEmptyEntriesWithIndex(): void
    {
        $c = new Container();

        /** @var list<mixed> $badEntries */
        $badEntries = [123];

        try {
            // @phpstan-ignore argument.type (deliberately invalid entry under test)
            new AutowireCompilerPass()->process($c, $badEntries);
            self::fail('non-string class entry must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Cannot autowire classes[0]: expected a class-name string.', $e->getMessage());
        }

        /** @var list<mixed> $emptyEntries */
        $emptyEntries = [Ct2bLeaf::class, ''];

        try {
            // @phpstan-ignore argument.type (deliberately invalid entry under test)
            new AutowireCompilerPass()->process($c, $emptyEntries);
            self::fail('empty class entry must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Cannot autowire classes[1]: expected a class-name string.', $e->getMessage());
        }
    }

    public function testReuseEntryRecordedExactlyOncePerPass(): void
    {
        // reusedIds ledger records PRE-EXISTING registrations that the pass
        // reuses — generated classes land in generatedIds instead.
        $c = new Container();
        $c->register(Ct2bLeaf::class, static fn (): Ct2bLeaf => new Ct2bLeaf());
        $result = new AutowireCompilerPass()->process($c, [Ct2bLeaf::class, Ct2bLeaf::class]);

        self::assertSame([Ct2bLeaf::class], $result->reusedIds, 'pre-existing class reused once, even across two entries');
        self::assertSame([], $result->generatedIds, 'reused class must not be generated');
    }

    public function testPassRejectsUnknownClassInterfaceAndAbstractTargets(): void
    {
        $c = new Container();

        /** @var list<mixed> $unknown */
        $unknown = ['Zef\Ghost\Missing'];

        try {
            // @phpstan-ignore argument.type (unknown class string is the scenario)
            new AutowireCompilerPass()->process($c, $unknown);
            self::fail('unknown class must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Cannot autowire 'Zef\\Ghost\\Missing': class does not exist.", $e->getMessage());
        }

        // An interface FQCN fails the class_exists guard first (clear message).
        /** @var list<mixed> $interface */
        $interface = [Ct2bLog::class];

        try {
            // @phpstan-ignore argument.type (interface FQCN is the scenario)
            new AutowireCompilerPass()->process($c, $interface);
            self::fail('interface target must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Cannot autowire '" . Ct2bLog::class . "': class does not exist.", $e->getMessage());
        }

        // An abstract class reaches the instantiability guard.
        /** @var list<mixed> $abstract */
        $abstract = [Ct2bAbstractBase::class];

        try {
            // @phpstan-ignore argument.type (abstract FQCN is the scenario)
            new AutowireCompilerPass()->process($c, $abstract);
            self::fail('abstract target must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Cannot autowire '" . Ct2bAbstractBase::class . "': interface, abstract class, or otherwise non-instantiable.",
                $e->getMessage(),
            );
        }
    }

    // =====================================================================
    // AutowireCompilerPass — cycles
    // =====================================================================

    public function testCircularChainIsTrimmedToTheCycleOnly(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bCycD::class]);
            self::fail('circular autowire chain must be rejected.');
        } catch (ServiceCircularDependencyException $e) {
            self::assertSame(
                [Ct2bCycA::class, Ct2bCycB::class, Ct2bCycC::class, Ct2bCycA::class],
                $e->getChain(),
                'chain must start at the first re-entered class, not at the stack root',
            );
        }
    }

    public function testSelfCycleThrowsCircularException(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bSelfCycle::class]);
            self::fail('self-referencing class must be rejected.');
        } catch (ServiceCircularDependencyException $e) {
            self::assertSame([Ct2bSelfCycle::class, Ct2bSelfCycle::class], $e->getChain());
        }
    }

    public function testDiamondDependencyResolvesAfterInnerPop(): void
    {
        $c = new Container();
        $c->register('ct2b.DB', static fn (): Ct2bDiamondB => new Ct2bDiamondB(), [], null, ServiceLifetime::TRANSIENT);
        $c->register(
            'ct2b.DC',
            static fn (ContainerInterface $ctx, Ct2bDiamondB $b): Ct2bDiamondC => new Ct2bDiamondC($b),
            ['ct2b.DB'],
            null,
            ServiceLifetime::TRANSIENT,
        );
        $c->register(
            'ct2b.DD',
            static fn (ContainerInterface $ctx, Ct2bDiamondB $b, Ct2bDiamondC $cc): Ct2bDiamondD => new Ct2bDiamondD($b, $cc),
            ['ct2b.DB', 'ct2b.DC'],
            null,
            ServiceLifetime::TRANSIENT,
        );
        $c->validateAndFreeze();

        $dd = $c->get('ct2b.DD');
        self::assertInstanceOf(Ct2bDiamondD::class, $dd);
        self::assertNotSame($dd->b1, $dd->b2, 'transient dependency must instantiate fresh per edge');
    }

    // =====================================================================
    // AutowireCompilerPass — variadic service collection typing
    // =====================================================================

    public function testVariadicCollectionMatchesExactlyTypedReaderServices(): void
    {
        $c = new Container();
        // String-id service whose factory declares no return type: never collectable.
        $c->register('ct2b.plain', static fn (ContainerInterface $ctx): \stdClass => new \stdClass());
        // Class id that exists but is NOT a Ct2bLog: mutant `||` would leak it in.
        $c->register(\stdClass::class, static fn (): \stdClass => new \stdClass());
        // Service id = the interface FQCN itself: collectable via interface_exists.
        $c->register(Ct2bLog::class, static fn (): Ct2bNullLog => new Ct2bNullLog());
        // Class id matching via is_a().
        $c->register(Ct2bFileLog::class, static fn (): Ct2bFileLog => new Ct2bFileLog());
        // Closure factory with a declared Ct2bLog return type.
        $c->register('ct2b.anonlog', static fn (): Ct2bLog => new Ct2bFileLog());
        // Array-callable factory ([class, method]) with a declared return type.
        $c->register('ct2b.kit', Ct2bFactoryKit::make(...));
        new AutowireCompilerPass()->process($c, [Ct2bVariadicConsumer::class]);

        $c->validateAndFreeze();
        $consumer = $c->get(Ct2bVariadicConsumer::class);

        self::assertInstanceOf(Ct2bVariadicConsumer::class, $consumer);
        self::assertCount(4, $consumer->items, 'plain/string-id/stdClass services must never enter the collection');
        self::assertInstanceOf(Ct2bNullLog::class, $consumer->items[0]);
        self::assertInstanceOf(Ct2bFileLog::class, $consumer->items[1]);
        self::assertInstanceOf(Ct2bFileLog::class, $consumer->items[2]);
        self::assertInstanceOf(Ct2bFileLog::class, $consumer->items[3]);
    }

    // =====================================================================
    // AutowireCompilerPass — binding failure guidance (exact messages)
    // =====================================================================

    public function testAbstractUnboundDependencyFailsWithExactGuidance(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bAbstractUser::class]);
            self::fail('unbound abstract dependency must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bAbstractUser::class . '::$base: no binding for \'' . Ct2bAbstractBase::class . '\'. '
                . 'Register the service, add an alias with that FQCN, use #[Target] / #[Inject], or give the parameter a default value.',
                $e->getMessage(),
            );
        }
    }

    public function testNullableRequiredUnboundDependencyFailsWithExactGuidance(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bNullableUser::class]);
            self::fail('nullable-but-required unbound dependency must be rejected (a default is the correct mechanism).');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bNullableUser::class . '::$log: no binding for \'' . Ct2bLog::class . '\'. '
                . 'Register the service, add an alias with that FQCN, use #[Target] / #[Inject], or give the parameter a default value.',
                $e->getMessage(),
            );
        }
    }

    public function testUnionTypeDefaultSkippedAndRequiredFails(): void
    {
        $c = new Container();
        $result = new AutowireCompilerPass()->process($c, [Ct2bUnionDefaultUser::class]);
        $c->validateAndFreeze();
        $unionUser = $c->get(Ct2bUnionDefaultUser::class);
        self::assertInstanceOf(Ct2bUnionDefaultUser::class, $unionUser);
        self::assertSame('fallback', $unionUser->mode, 'optional union relies on the declared default');
        self::assertContains(Ct2bUnionDefaultUser::class, $result->generatedIds);

        $c2 = new Container();

        try {
            new AutowireCompilerPass()->process($c2, [Ct2bUnionRequiredUser::class]);
            self::fail('required union dependency must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bUnionRequiredUser::class . '::$mode: union type (string|int) is not autowireable.',
                $e->getMessage(),
            );
        }
    }

    public function testInjectUnknownIdFailsWithServiceNotFound(): void
    {
        $c = new Container();

        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage("Service 'ct2b.ghost' not found.");
        new AutowireCompilerPass()->process($c, [Ct2bInjectGhost::class]);
    }

    // =====================================================================
    // AutowireCompilerPass — #[Value] lookup and literal rendering matrix
    // =====================================================================

    public function testNullConfigValueIsRejectedWithExactGuidance(): void
    {
        $c = new Container();
        $pass = new AutowireCompilerPass(configValues: ['ct2b.isnull' => null]);

        try {
            $pass->process($c, [Ct2bNullValueUser::class]);
            self::fail('null config value must be rejected at compile time.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Config value 'ct2b.isnull' is null: services can never resolve to null. "
                . 'Give the parameter a default value instead of #[Value].',
                $e->getMessage(),
            );
        }
    }

    public function testMissingConfigValueFailsNamingOwnerParameterAndKey(): void
    {
        $c = new Container();
        $pass = new AutowireCompilerPass(configValues: []);

        try {
            $pass->process($c, [Ct2bMissingValueUser::class]);
            self::fail('missing config key must be rejected naming owner, parameter and key.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bMissingValueUser::class . '::$v: #[Value(\'ct2b.missing\')] has no value in the '
                . 'AutowireCompilerPass configuration.',
                $e->getMessage(),
            );
        }
    }

    public function testScalarVariadicListInjectedAndNonArrayRejected(): void
    {
        $config = [
            'ct2b.nums' => [1, 2, 3],
            'ct2b.notarray' => 'definitely not a list',
        ];
        $c = new Container();
        new AutowireCompilerPass(configValues: $config)->process($c, [Ct2bScalarVariadic::class]);
        $c->validateAndFreeze();
        $variadicUser = $c->get(Ct2bScalarVariadic::class);
        self::assertInstanceOf(Ct2bScalarVariadic::class, $variadicUser);
        self::assertSame([1, 2, 3], $variadicUser->nums);

        $c2 = new Container();

        try {
            new AutowireCompilerPass(configValues: ['ct2b.notarray' => 'nope'])->process($c2, [Ct2bScalarVariadicBad::class]);
            self::fail('non-array config for a scalar variadic must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bScalarVariadicBad::class . '::$nums: config \'ct2b.notarray\' must be an array for a variadic parameter.',
                $e->getMessage(),
            );
        }
    }

    public function testLiteralRenderingMatrixRoundTripsThroughAot(): void
    {
        $config = [
            'ct2b.str' => "it's \"quoted\" \\ back\nline",
            'ct2b.float' => 0.5,
            'ct2b.bool' => false,
            'ct2b.zerostring' => '0',
            'ct2b.nested' => ['a' => 1, 'b' => ['c' => 'x', 'd' => null]],
            'ct2b.withnull' => ['k' => null],
        ];
        $c = new Container();
        new AutowireCompilerPass(configValues: $config)->process($c, [Ct2bValueUser::class]);
        $c->validateAndFreeze();

        $user = $c->get(Ct2bValueUser::class);
        self::assertInstanceOf(Ct2bValueUser::class, $user);
        self::assertSame("it's \"quoted\" \\ back\nline", $user->str, 'string escaping must survive var_export + eval');
        self::assertSame(0.5, $user->f);
        self::assertFalse($user->b, 'boolean false must not collapse to null/string');
        self::assertSame('0', $user->zerostring, 'string zero must stay a string');
        self::assertSame(['a' => 1, 'b' => ['c' => 'x', 'd' => null]], $user->nested);
        self::assertSame(['k' => null], $user->withNull, 'null array elements are exportable and must not throw');
    }

    public function testNonExportableConfigElementRejectedExact(): void
    {
        $c = new Container();
        $pass = new AutowireCompilerPass(configValues: ['ct2b.badelem' => ['ok' => 1, 'bad' => new \stdClass()]]);

        try {
            $pass->process($c, [Ct2bBadElemUser::class]);
            self::fail('object nested inside a config array must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "config value 'ct2b.badelem' contains a non-exportable element (stdClass).",
                $e->getMessage(),
            );
        }

        $c2 = new Container();
        $pass2 = new AutowireCompilerPass(configValues: ['ct2b.topobj' => new \stdClass()]);

        try {
            $pass2->process($c2, [Ct2bTopObjUser::class]);
            self::fail('top-level object config value must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "config value 'ct2b.topobj' is not exportable (stdClass).",
                $e->getMessage(),
            );
        }
    }

    // =====================================================================
    // Container — construction flags, policies, warm/reset lifecycle
    // =====================================================================

    public function testDebugFlagDefaultOffAndExplicitOn(): void
    {
        self::assertFalse(new Container()->isDebug(), 'debug must default to false');
        self::assertTrue(new Container(true)->isDebug());
    }

    public function testCustomInitializationGuardParticipatesInResolution(): void
    {
        $guard = new Ct2bRecordingGuard();
        $c = new Container(initializationGuard: $guard);
        $c->register('ct2b.one', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();
        $c->get('ct2b.one');

        self::assertSame(['ct2b.one'], $guard->keys, 'the injected guard must wrap every instantiation');
    }

    public function testConfigurePoliciesFrozenGuardAndNegativeClamp(): void
    {
        $c = new Container();
        $c->register('ct2b.a.svc', static fn (): \stdClass => new \stdClass(), [], 'alpha');
        $c->register('ct2b.b.svc', static fn (): \stdClass => new \stdClass(), ['ct2b.a.svc'], 'beta');
        $c->configurePolicies(-5);
        $c->validateAndFreeze();
        self::assertTrue($c->isFrozen());

        try {
            $c->configurePolicies(1);
            self::fail('configurePolicies on a frozen container must be rejected.');
        } catch (\LogicException $e) {
            self::assertSame('Container is frozen.', $e->getMessage());
        }

        // Budget 0 (default and clamped): cross-module validation is DISABLED —
        // any number of cross-module edges compiles.
        $c2 = new Container();
        $c2->register('ct2b.x1', static fn (): \stdClass => new \stdClass(), [], 'modx');
        $c2->register('ct2b.x2', static fn (): \stdClass => new \stdClass(), [], 'modx');
        $c2->register('ct2b.y1', static fn (): \stdClass => new \stdClass(), ['ct2b.x1'], 'mody');
        $c2->register('ct2b.y2', static fn (): \stdClass => new \stdClass(), ['ct2b.x2'], 'mody');
        $c2->validateAndFreeze();
        self::assertTrue($c2->isFrozen());

        // Explicit budget 1: the second edge of the same module pair violates.
        $c3 = new Container();
        $c3->configurePolicies(1);
        $c3->register('ct2b.p1', static fn (): \stdClass => new \stdClass(), [], 'modp');
        $c3->register('ct2b.p2', static fn (): \stdClass => new \stdClass(), [], 'modp');
        $c3->register('ct2b.q1', static fn (): \stdClass => new \stdClass(), ['ct2b.p1'], 'modq');
        $c3->register('ct2b.q2', static fn (): \stdClass => new \stdClass(), ['ct2b.p2'], 'modq');

        try {
            $c3->validateAndFreeze();
            self::fail('the second cross-module edge of one pair must violate budget 1.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString('exceeds cross-module reference limit (1)', $e->getMessage());
        }
    }

    public function testWarmSingletonsInstantiatesExactlyEagerSharedSingletons(): void
    {
        $c = new Container();
        $eagerHits = 0;
        $lazyHits = 0;
        $transientHits = 0;
        $unsharedHits = 0;
        $c->register('ct2b.w.eager', static function () use (&$eagerHits): \stdClass {
            ++$eagerHits;

            return new \stdClass();
        });
        $c->register('ct2b.w.transient', static function () use (&$transientHits): \stdClass {
            ++$transientHits;

            return new \stdClass();
        }, [], null, ServiceLifetime::TRANSIENT);
        $c->registerDefinition(new ServiceDefinition(
            id: 'ct2b.w.lazy',
            factory: static function () use (&$lazyHits): \stdClass {
                ++$lazyHits;

                return new \stdClass();
            },
            dependencies: [],
            lifetime: ServiceLifetime::SINGLETON,
            shared: true,
            lazy: true,
        ));

        $c->registerDefinition(new ServiceDefinition(
            id: 'ct2b.w.unshared',
            factory: static function () use (&$unsharedHits): \stdClass {
                ++$unsharedHits;

                return new \stdClass();
            },
            dependencies: [],
            lifetime: ServiceLifetime::SINGLETON,
            shared: false,
        ));

        try {
            $c->warmSingletons();
            self::fail('warming before freeze must be rejected.');
        } catch (\LogicException $e) {
            self::assertSame('Container must be frozen before warming singletons.', $e->getMessage());
        }

        $c->validateAndFreeze();
        $c->warmSingletons();

        self::assertSame(1, $eagerHits, 'eager shared singleton must be instantiated exactly once');
        self::assertSame(0, $lazyHits, 'lazy singletons must stay untouched by warming');
        self::assertSame(0, $transientHits, 'transients must never be warmed');
        self::assertSame(0, $unsharedHits, 'unshared singletons must never be warmed — they are per-request by contract');

        $eager = $c->get('ct2b.w.eager');
        self::assertSame($c->get('ct2b.w.eager'), $eager, 'warmed singleton must be reused');
    }

    public function testResetPreservesSingletonsUnlessExplicitlyCleared(): void
    {
        $c = new Container();
        $hits = 0;
        $c->register('ct2b.r.svc', static function () use (&$hits): \stdClass {
            ++$hits;

            return new \stdClass();
        });
        $c->registerNamespaceFallback('Ct2b\Fb\\', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();

        $first = $c->get('ct2b.r.svc');
        $fbFirst = $c->get('Ct2b\Fb\One');
        $c->reset();
        self::assertSame($first, $c->get('ct2b.r.svc'), 'default reset keeps singletons alive');
        self::assertSame($fbFirst, $c->get('Ct2b\Fb\One'), 'default reset keeps fallback singletons alive');

        $c->reset(true);
        self::assertNotSame($first, $c->get('ct2b.r.svc'), 'explicit reset must drop singleton instances');
        self::assertNotSame($fbFirst, $c->get('Ct2b\Fb\One'), 'explicit reset must drop fallback singleton cache');
        self::assertSame(2, $hits, 'factory must run exactly twice in total');
    }

    public function testGetRegisteredIdsReturnsMergedListInInsertionOrder(): void
    {
        $c = new Container();
        $c->register('ct2b.one', static fn (): \stdClass => new \stdClass());
        $c->register('ct2b.two', static fn (): \stdClass => new \stdClass());
        $c->alias('ct2b.alias1', 'ct2b.one');
        $c->alias('ct2b.alias2', 'ct2b.one');

        self::assertSame(
            ['ct2b.one', 'ct2b.two', 'ct2b.alias1', 'ct2b.alias2'],
            $c->getRegisteredIds(),
            'factories first, then aliases, list-shaped (0-indexed)',
        );
    }

    // =====================================================================
    // Container — contextual bindings
    // =====================================================================

    public function testContextualBindingDuplicateRejectedDistinctAllowedAndViaIdExact(): void
    {
        $c = new Container();
        $c->register('ct2b.log', static fn (): Ct2bFileLog => new Ct2bFileLog());
        $c->register('ct2b.alt', static fn (): Ct2bNullLog => new Ct2bNullLog());
        $c->register(
            'ct2b.c1',
            static fn (ContainerInterface $ctx, Ct2bLog $log): Ct2bCtxConsumer => new Ct2bCtxConsumer($log),
            ['ct2b.log'],
        );
        $c->register(
            'ct2b.c2',
            static fn (ContainerInterface $ctx, Ct2bLog $log): Ct2bCtxConsumer => new Ct2bCtxConsumer($log),
            ['ct2b.log'],
        );

        $c->when('ct2b.c1')->needs('ct2b.log')->give('ct2b.alt');
        self::assertSame('@contextual:ct2b.c1|ct2b.log', $c->getContextualBindings()[0]['via']);

        // NOTE: the first give() rewrites the consumer's dependency list to the
        // synthetic via id, so the duplicate attempt is caught by the
        // declared-dependency guard — the ledgered-duplicate check below it is
        // defence in depth for rewritten graphs. Either way: hard failure.
        try {
            $c->when('ct2b.c1')->needs('ct2b.log')->give('ct2b.log');
            self::fail('duplicate contextual binding for the same consumer+dep must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("consumer 'ct2b.c1'", $e->getMessage());
        }

        // A different consumer may bind the same dep independently.
        $c->when('ct2b.c2')->needs('ct2b.log')->give('ct2b.log');
        self::assertCount(2, $c->getContextualBindings());
        self::assertSame('@contextual:ct2b.c2|ct2b.log', $c->getContextualBindings()[1]['via']);

        $c->validateAndFreeze();
        $first = $c->get('ct2b.c1');
        $second = $c->get('ct2b.c2');
        self::assertInstanceOf(Ct2bCtxConsumer::class, $first);
        self::assertInstanceOf(Ct2bCtxConsumer::class, $second);
        self::assertInstanceOf(Ct2bNullLog::class, $first->log, 'consumer 1 routed through the synthetic alias');
        self::assertInstanceOf(Ct2bFileLog::class, $second->log, 'consumer 2 keeps the default target');
    }

    // =====================================================================
    // Container — decoration chains and budgets
    // =====================================================================

    public function testDecorationAppliesChainInOrderWithSyntheticInnerIds(): void
    {
        $c = new Container();
        $c->register('ct2b.core', static fn (): string => 'core');
        $c->decorate('ct2b.core', static fn (ContainerInterface $ctx, string $inner): string => "A({$inner})");
        $c->decorate('ct2b.core', static fn (ContainerInterface $ctx, string $inner): string => "B({$inner})");
        $c->validateAndFreeze();

        self::assertSame('A(B(core))', $c->get('ct2b.core'), 'first-registered decorator must be outermost');
        self::assertContains('@inner:ct2b.core:base', $c->getRegisteredIds(), 're-homed base must keep its exact synthetic id');
        self::assertContains('@inner:ct2b.core:1', $c->getRegisteredIds(), 'inner wrapper must keep its exact synthetic id');
    }

    public function testDecorationOfUnknownServiceRejectedAtFreeze(): void
    {
        $c = new Container();
        $c->decorate('ct2b.nothere', static fn (ContainerInterface $ctx, mixed $inner): mixed => $inner);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Cannot decorate unknown service 'ct2b.nothere'.");
        $c->validateAndFreeze();
    }

    public function testDecorationBudgetAllows128AndRejects129th(): void
    {
        $c = new Container();
        $decorator = static fn (ContainerInterface $ctx, mixed $inner): mixed => $inner;

        for ($i = 0; $i < 128; ++$i) {
            $c->decorate('ct2b.budget', $decorator);
        }
        $c->register('ct2b.budget', static fn (): \stdClass => new \stdClass());

        try {
            $c->decorate('ct2b.budget', $decorator);
            self::fail('the 129th decorator must exceed the budget.');
        } catch (\OverflowException $e) {
            self::assertSame('Container decoration budget exceeded (128).', $e->getMessage());
        }
    }

    public function testDecorationHonoursRegistrationBudget(): void
    {
        $c = new Container(false, new ArchitecturePolicy(maxServiceRegistrations: 4));
        $c->register('ct2b.s1', static fn (): \stdClass => new \stdClass());
        $c->register('ct2b.s2', static fn (): \stdClass => new \stdClass());
        // Two decorators: base re-home (+1) and the inner wrapper id (+1) push
        // the definition count to exactly the budget — the outermost wrapper
        // replaces the original id, so only the INNER one allocates a slot.
        $c->decorate('ct2b.s1', static fn (ContainerInterface $ctx, mixed $inner): mixed => $inner);
        $c->decorate('ct2b.s1', static fn (ContainerInterface $ctx, mixed $inner): mixed => $inner);

        try {
            $c->validateAndFreeze();
            self::fail('decoration pushing the definition count to the budget must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Service registration budget exceeded during decoration.', $e->getMessage());
        }
    }

    // =====================================================================
    // Container — providers: budget, eager boot, deferred triggering
    // =====================================================================

    public function testProviderBudgetAllows64AndRejects65th(): void
    {
        $c = new Container();
        for ($i = 0; $i < 64; ++$i) {
            $c->registerProvider(new Ct2bNullProvider());
        }

        try {
            $c->registerProvider(new Ct2bNullProvider());
            self::fail('the 65th provider must exceed the budget.');
        } catch (\OverflowException $e) {
            self::assertSame('Container provider budget exceeded (64).', $e->getMessage());
        }
    }

    public function testEagerProviderRegistersImmediatelyAndBootsOnce(): void
    {
        $c = new Container();
        $eager = new Ct2bEagerProvider();
        $c->registerProvider($eager);

        self::assertSame(1, $eager->registered, 'eager provider must register synchronously');
        self::assertSame(0, $eager->booted);

        $c->bootProviders();
        $c->bootProviders();
        self::assertSame(1, $eager->booted, 'boot hook must run exactly once');
    }

    public function testDeferredProviderStaysLazyUntilRequestedAndBootsAfterTrigger(): void
    {
        $c = new Container();
        $lazy = new Ct2bLazyProvider();
        $c->registerProvider($lazy);

        self::assertSame(0, $lazy->registered, 'deferred provider must NOT register eagerly');

        $c->register('ct2b.other', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();
        self::assertFalse($c->has('ct2b.late.svc'), 'untriggered deferred service is unknown');
        self::assertSame(0, $lazy->registered);

        $fresh = new Container();
        $lazy2 = new Ct2bLazyProvider();
        $fresh->registerProvider($lazy2);
        $svc = $fresh->get('ct2b.late.svc');
        self::assertInstanceOf(\stdClass::class, $svc);
        self::assertSame(1, $lazy2->registered, 'get() must trigger the deferred provider');
        $fresh->bootProviders();
        self::assertSame(1, $lazy2->booted, 'triggered deferred provider becomes bootable');
    }

    public function testTwoDeferredProvidersForSameIdBothRun(): void
    {
        $c = new Container();
        $a = new Ct2bDupProviderA();
        $b = new Ct2bDupProviderB();
        $c->registerProvider($a);
        $c->registerProvider($b);

        self::assertInstanceOf(\stdClass::class, $c->get('ct2b.dup'));
        self::assertSame(1, $a->registered);
        self::assertSame(1, $b->registered, 'both pending providers for one id must run — break instead of continue would skip the second');
    }

    public function testDeferredProviderRequestedAfterFreezeFailsWithExactMessage(): void
    {
        $c = new Container();
        $c->registerProvider(new Ct2bNobodyProvider());
        $c->register('ct2b.seed', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.nobody');
            self::fail('deferred service requested post-freeze must fail loudly.');
        } catch (ServiceNotFoundException $e) {
            // ZEF-DEEP-12: PSR-11 — has() is false, so get() surfaces
            // NotFoundExceptionInterface carrying the same guidance.
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e);
            self::assertSame(
                "Service 'ct2b.nobody' not found. Deferred provider service 'ct2b.nobody' requested but the container is already frozen"
                . ' — request it before validateAndFreeze() or register the provider as eager.',
                $e->getMessage(),
            );
        }
    }

    public function testDeferredProviderReferencedByAliasTargetIsTriggeredBeforeFreeze(): void
    {
        $c = new Container();
        $c->registerProvider(new Ct2bAliasDepProvider());
        $c->alias('ct2b.front', 'ct2b.dep.foralias');
        $c->validateAndFreeze();

        self::assertInstanceOf(\stdClass::class, $c->get('ct2b.front'), 'alias target provided by a deferred provider must compile');
    }

    // =====================================================================
    // Container — namespace fallbacks
    // =====================================================================

    public function testNamespaceFallbackRejectsRequestLifetimeAndUnknownLifetime(): void
    {
        $c = new Container();

        try {
            $c->registerNamespaceFallback('Ct2b\Req\\', static fn (): \stdClass => new \stdClass(), ServiceLifetime::REQUEST);
            self::fail('REQUEST fallback lifetime must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Namespace fallback lifetime cannot be REQUEST (fallback IDs are not scoped).', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown service lifetime 'eternal'.");
        $c->registerNamespaceFallback('Ct2b\Bad\\', static fn (): \stdClass => new \stdClass(), 'eternal');
    }

    public function testNamespaceFallbackWrapsFactoryFailureAndRejectsNull(): void
    {
        $c = new Container();
        $c->registerNamespaceFallback('Ct2b\Boom\\', static fn (): never => throw new \RuntimeException('disk on fire'));
        $c->validateAndFreeze();

        try {
            $c->get('Ct2b\Boom\X');
            self::fail('failing fallback factory must be wrapped.');
        } catch (ServiceResolutionException $e) {
            self::assertSame(
                "Cannot resolve service 'Ct2b\\Boom\\X': namespace fallback factory failed: disk on fire",
                $e->getMessage(),
            );
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $c2 = new Container();
        $c2->registerNamespaceFallback('Ct2b\Nil\\', static fn (): null => null);
        $c2->validateAndFreeze();

        try {
            $c2->get('Ct2b\Nil\X');
            self::fail('null fallback instance must be rejected.');
        } catch (ServiceResolutionException $e) {
            self::assertSame(
                "Cannot resolve service 'Ct2b\\Nil\\X': namespace fallback factory returned null.",
                $e->getMessage(),
            );
        }
    }

    public function testNamespaceFallbackPrefixSpecificityAndLifetimes(): void
    {
        $c = new Container();
        $rootHits = 0;
        $c->registerNamespaceFallback('Ct2b\Ns\\', static fn (): \stdClass => new \stdClass());
        $c->registerNamespaceFallback('Ct2b\Ns\Deep\\', static fn (): \stdClass => new \stdClass());
        $c->registerNamespaceFallback('Ct2b\Tr\\', static fn (): \stdClass => new \stdClass(), ServiceLifetime::TRANSIENT);
        $c->registerNamespaceFallback('', static function () use (&$rootHits): \stdClass {
            ++$rootHits;

            return new \stdClass();
        });
        $c->validateAndFreeze();

        self::assertTrue($c->has('Ct2b\Ns\Deep\X'), 'longest registered prefix covers the id');
        $deep = $c->get('Ct2b\Ns\Deep\X');
        self::assertSame($deep, $c->get('Ct2b\Ns\Deep\X'), 'deep singleton fallback is cached per requested id');

        $transient = $c->get('Ct2b\Tr\One');
        self::assertNotSame($transient, $c->get('Ct2b\Tr\One'), 'transient fallback instantiates every call');

        self::assertInstanceOf(\stdClass::class, $c->get('\Global\Unknown'), 'normalized root prefix "\" matches ids starting with a separator');
        self::assertSame(1, $rootHits, 'root catch-all singleton cached');
    }

    public function testNamespaceStatsNullBeforeFreeze(): void
    {
        self::assertNull(new Container()->namespaceStats(), 'no radix tree exists before freeze');
    }

    // =====================================================================
    // ContainerResolver — direct adversarial probes
    // =====================================================================

    public function testResolutionDepthLimitClampedAndReported(): void
    {
        $probe = static fn (int $limit): ContainerResolver => new ContainerResolver(
            new ServiceRegistry(),
            new DependencyGraphValidator(),
            resolutionDepthLimit: $limit,
        );

        self::assertSame(1, $probe(0)->maxResolutionDepth(), 'limit is clamped up to 1');
        self::assertSame(1, $probe(-7)->maxResolutionDepth(), 'negative limits clamp to 1');
        self::assertSame(255, $probe(255)->maxResolutionDepth());
        self::assertSame(257, $probe(257)->maxResolutionDepth());
        self::assertSame(256, new ContainerResolver(new ServiceRegistry(), new DependencyGraphValidator())->maxResolutionDepth(), 'default limit is exactly 256');
    }

    public function testResolveRootWithoutBoundContainerThrows(): void
    {
        $resolver = new ContainerResolver(new ServiceRegistry(), new DependencyGraphValidator());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Container resolver is not bound.');
        $resolver->resolveRoot('ct2b.anything');
    }

    public function testScopeHelpersRoundTripAndMissThrows(): void
    {
        $resolver = new ContainerResolver(new ServiceRegistry(), new DependencyGraphValidator());
        $resolver->bind(new Container());
        $scope = $resolver->createRequestScope();
        $probe = new \stdClass();

        $resolver->scopeSetPublic($scope, 'ct2b.probe', $probe);
        self::assertTrue($resolver->scopeHasPublic($scope, 'ct2b.probe'));
        self::assertSame($probe, $resolver->scopeGetPublic($scope, 'ct2b.probe'));

        try {
            $resolver->scopeGetPublic($scope, 'ct2b.ghost');
            self::fail('missing scope entry must throw.');
        } catch (\LogicException $e) {
            self::assertSame("No instance for 'ct2b.ghost' in the active request scope.", $e->getMessage());
        }
    }

    public function testRequestScopedStateIsPreservedAcrossServicesWithinScope(): void
    {
        $c = new Container();
        $hits = 0;
        $c->register('ct2b.req1', static function () use (&$hits): \stdClass {
            ++$hits;

            return new \stdClass();
        }, [], null, ServiceLifetime::REQUEST);
        $c->register('ct2b.req2', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::REQUEST);
        $c->validateAndFreeze();

        $scope = $c->createRequestScope();
        $first = $scope->get('ct2b.req1');
        $scope->get('ct2b.req2');
        self::assertSame($first, $scope->get('ct2b.req1'), 'caching a second request service must not evict the first');
        self::assertSame(1, $hits);

        $scope2 = $c->createRequestScope();
        self::assertNotSame($first, $scope2->get('ct2b.req1'), 'a fresh scope starts empty');
        self::assertSame(2, $hits);

        $scope2->close();

        try {
            $scope2->get('ct2b.req1');
            self::fail('closed scope must refuse resolution.');
        } catch (\LogicException $e) {
            self::assertSame('Request scope is closed.', $e->getMessage());
        }

        try {
            $c->get('ct2b.req1');
            self::fail('request-scoped service outside a scope must be rejected.');
        } catch (ServiceResolutionException $e) {
            // resolveRoot wraps the inner LogicException into an SRE.
            self::assertSame(
                "Cannot resolve service 'ct2b.req1': Request-scoped service 'ct2b.req1' resolved outside a request scope.",
                $e->getMessage(),
            );
        }
    }

    public function testTransientAndUnsharedSingletonsNeverCache(): void
    {
        $c = new Container();
        $c->register('ct2b.t.svc', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $c->registerDefinition(new ServiceDefinition(
            id: 'ct2b.us.svc',
            factory: static fn (): \stdClass => new \stdClass(),
            dependencies: [],
            lifetime: ServiceLifetime::SINGLETON,
            shared: false,
        ));
        $c->validateAndFreeze();

        $t1 = $c->get('ct2b.t.svc');
        self::assertNotSame($t1, $c->get('ct2b.t.svc'), 'transient must never be cached even with shared=true by default');

        $u1 = $c->get('ct2b.us.svc');
        self::assertNotSame($u1, $c->get('ct2b.us.svc'), 'unshared singleton must bypass the instance cache');
    }

    public function testResolvingListenerFailureWrappedExactly(): void
    {
        $c = new Container();
        $c->register('ct2b.listen', static fn (): \stdClass => new \stdClass());
        $c->onResolving(static fn (): never => throw new \RuntimeException('boom'));
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.listen');
            self::fail('throwing resolving listener must be wrapped.');
        } catch (ServiceResolutionException $e) {
            self::assertSame("Cannot resolve service 'ct2b.listen': resolving listener failed: boom", $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testResolvedListenerFailureWrappedExactly(): void
    {
        $c = new Container();
        $c->register('ct2b.listen2', static fn (): \stdClass => new \stdClass());
        $c->onResolved(static fn (): never => throw new \RuntimeException('bad replacement'));
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.listen2');
            self::fail('throwing resolved listener must be wrapped.');
        } catch (ServiceResolutionException $e) {
            self::assertSame("Cannot resolve service 'ct2b.listen2': resolved listener failed: bad replacement", $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testServiceResolutionExceptionFromFactoryIsRethrownAsIs(): void
    {
        $c = new Container();
        $c->register('ct2b.thrower', static fn (): never => throw new ServiceResolutionException('ct2b.inner', 'upstream broke'));
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.thrower');
            self::fail('factory SRE must propagate unwrapped.');
        } catch (ServiceResolutionException $e) {
            self::assertSame(
                "Cannot resolve service 'ct2b.inner': upstream broke",
                $e->getMessage(),
                'the original exception must not be double-wrapped with the consumer id',
            );
        }
    }

    public function testGenericFactoryFailureWrappedWithCanonicalId(): void
    {
        $c = new Container();
        $c->register('ct2b.kaput', static fn (): never => throw new \RuntimeException('kaput'));
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.kaput');
            self::fail('generic factory failure must be wrapped.');
        } catch (ServiceResolutionException $e) {
            self::assertSame("Cannot resolve service 'ct2b.kaput': kaput", $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    // =====================================================================
    // Batch 2 — mutants escaped after round 1 (triage-driven kills)
    // =====================================================================

    public function testFrozenPassRejectsEvenAnEmptyClassList(): void
    {
        $c = new Container();
        $c->register('ct2b.one', static fn (): \stdClass => new \stdClass());
        $c->validateAndFreeze();

        // No definitions would be touched, so the only guard left is the
        // frozen check itself — it must fire before any state reset.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Container is frozen.');
        new AutowireCompilerPass()->process($c, []);
    }

    public function testInjectIdPathReturnsBeforeClassTypeResolution(): void
    {
        $c = new Container();
        $c->register('ct2b.log', static fn (): Ct2bFileLog => new Ct2bFileLog());
        new AutowireCompilerPass()->process($c, [Ct2bInjectOk::class]);
        $c->validateAndFreeze();

        $user = $c->get(Ct2bInjectOk::class);
        self::assertInstanceOf(Ct2bInjectOk::class, $user);
        self::assertInstanceOf(Ct2bFileLog::class, $user->log, '#[Inject] must satisfy the parameter without touching the unbound class type');
    }

    public function testVariadicClassCollectionTakesPrecedenceOverValueAttribute(): void
    {
        $c = new Container();
        $c->register(Ct2bFileLog::class, static fn (): Ct2bFileLog => new Ct2bFileLog());
        new AutowireCompilerPass(configValues: ['ct2b.varlist' => [1, 2]])->process($c, [Ct2bVariadicWithValue::class]);
        $c->validateAndFreeze();

        $user = $c->get(Ct2bVariadicWithValue::class);
        self::assertInstanceOf(Ct2bVariadicWithValue::class, $user);
        self::assertCount(1, $user->logs, 'class-typed variadic collects services, the #[Value] list must be ignored');
        self::assertInstanceOf(Ct2bFileLog::class, $user->logs[0]);
    }

    public function testEnumDefaultOnUnboundClassDependencyIsRejected(): void
    {
        // Documented contract (v2.14.3): enum defaults cannot be rendered by
        // var_export — the pass fails fast with the exportable guard.
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bEnumDefaultUser::class]);
            self::fail('enum default on an unbound class dependency must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'default of ' . Ct2bEnumDefaultUser::class . '::$suit is not exportable (' . Ct2bSuit::class . ').',
                $e->getMessage(),
            );
        }
    }

    public function testMixedParamWithoutDefaultBakesNullLiteral(): void
    {
        $c = new Container();
        new AutowireCompilerPass()->process($c, [Ct2bMixedUser::class]);
        $c->validateAndFreeze();
        $mixedUser = $c->get(Ct2bMixedUser::class);
        self::assertInstanceOf(Ct2bMixedUser::class, $mixedUser);
        self::assertNull($mixedUser->payload, 'untyped/mixed param without default resolves to null');
    }

    public function testRequiredScalarWithoutValueOrDefaultFailsExact(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bRequiredScalarUser::class]);
            self::fail('required scalar without #[Value]/default must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bRequiredScalarUser::class . '::$required: required scalar parameter without #[Value] and without a default value.',
                $e->getMessage(),
            );
        }
    }

    public function testTargetAbstractClassRejectedExact(): void
    {
        $c = new Container();

        try {
            new AutowireCompilerPass()->process($c, [Ct2bTargetAbstractUser::class]);
            self::fail('#[Target] on a non-instantiable class must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                'Cannot autowire ' . Ct2bTargetAbstractUser::class . '::$base: #[Target(' . Ct2bAbstractBase::class . '::class)] is not an instantiable class.',
                $e->getMessage(),
            );
        }
    }

    public function testSharedValueKeyRegistersExactlyOneSyntheticService(): void
    {
        $c = new Container();
        new AutowireCompilerPass(configValues: ['ct2b.shared' => 'shared-value'])
            ->process($c, [Ct2bSharedValueUserA::class, Ct2bSharedValueUserB::class])
        ;
        $c->validateAndFreeze();

        $userA = $c->get(Ct2bSharedValueUserA::class);
        $userB = $c->get(Ct2bSharedValueUserB::class);
        self::assertInstanceOf(Ct2bSharedValueUserA::class, $userA);
        self::assertInstanceOf(Ct2bSharedValueUserB::class, $userB);
        self::assertSame('shared-value', $userA->v);
        self::assertSame('shared-value', $userB->v, 'second #[Value] consumer must reuse the synthetic @value service');
    }

    public function testSameTransientDependencyIsDeduplicatedAcrossParams(): void
    {
        $c = new Container();
        $c->register('ct2b.logt', static fn (): Ct2bFileLog => new Ct2bFileLog(), [], null, ServiceLifetime::TRANSIENT);
        $c->alias(Ct2bLog::class, 'ct2b.logt');
        new AutowireCompilerPass(lifetime: ServiceLifetime::TRANSIENT)->process($c, [Ct2bTwinDepUser::class]);
        $c->validateAndFreeze();

        $twin = $c->get(Ct2bTwinDepUser::class);
        self::assertInstanceOf(Ct2bTwinDepUser::class, $twin);
        self::assertSame($twin->a, $twin->b, 'dependency deduplication must give both params the same resolved instance');
    }

    public function testConfigurePoliciesDefaultArgumentDisablesCrossModuleValidation(): void
    {
        $c = new Container();
        $c->configurePolicies(); // default argument — budget stays 0 (validation off)
        $c->register('ct2b.d.x1', static fn (): \stdClass => new \stdClass(), [], 'modx');
        $c->register('ct2b.d.x2', static fn (): \stdClass => new \stdClass(), [], 'modx');
        $c->register('ct2b.d.y1', static fn (): \stdClass => new \stdClass(), ['ct2b.d.x1'], 'mody');
        $c->register('ct2b.d.y2', static fn (): \stdClass => new \stdClass(), ['ct2b.d.x2'], 'mody');
        $c->validateAndFreeze();
        self::assertTrue($c->isFrozen(), 'default configurePolicies() keeps validation disabled');
    }

    public function testRepeatedDeferredTriggerSkipsAlreadyRegisteredProvider(): void
    {
        $c = new Container();
        $a = new Ct2bMultiIdProvider();
        $b = new Ct2bSecondProvider();
        $c->registerProvider($a);
        $c->registerProvider($b);

        self::assertInstanceOf(\stdClass::class, $c->get('ct2b.dup.1'));
        self::assertSame(1, $a->registered);
        self::assertInstanceOf(\stdClass::class, $c->get('ct2b.dup.2'));
        self::assertSame(1, $b->registered, 'the second pending index must still run after a continue on the first');
    }

    public function testRuntimeSelfDependencyIsDetectedByTheContextStack(): void
    {
        // No declared deps → graph validator cannot see the cycle; only the
        // runtime resolution stack (push/pop) can catch it.
        $c = new Container();
        $c->register('ct2b.r1', static fn (ContainerInterface $ctx) => $ctx->get('ct2b.r2'));
        $c->register('ct2b.r2', static fn (ContainerInterface $ctx) => $ctx->get('ct2b.r1'));
        $c->validateAndFreeze();

        try {
            $c->get('ct2b.r1');
            self::fail('runtime mutual dependency must be caught by the resolution stack.');
        } catch (ServiceResolutionException $e) {
            // The inner push() throw surfaces as a factory error of 'ct2b.r2'
            // and is wrapped — the chain in the message proves the stack caught it.
            self::assertSame(
                "Cannot resolve service 'ct2b.r2': Circular service dependency detected: ct2b.r1 -> ct2b.r2 -> ct2b.r1",
                $e->getMessage(),
            );
        }
    }

    public function testTransientResolutionInsideScopeDoesNotTouchScopeCache(): void
    {
        $registry = new ServiceRegistry();
        $registry->addFactory('ct2b.t2', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::TRANSIENT);
        $resolver = new ContainerResolver($registry, new DependencyGraphValidator());
        $resolver->bind(new Container());
        $scope = $resolver->createRequestScope();

        $first = $scope->get('ct2b.t2');
        self::assertNotSame($first, $scope->get('ct2b.t2'), 'transient stays transient inside a scope');
        self::assertFalse($resolver->scopeHasPublic($scope, 'ct2b.t2'), 'transient resolution must never write the scope cache');
    }

    public function testNotFoundAliasWithModuleReportsTheModule(): void
    {
        $registry = new ServiceRegistry();
        $registry->addAlias('ct2b.front.ghost', 'ct2b.missing.target', 'mymod');
        $resolver = new ContainerResolver($registry, new DependencyGraphValidator());
        $resolver->bind(new Container());

        try {
            $resolver->resolveRoot('ct2b.front.ghost');
            self::fail('alias to an unknown target must be reported with its module.');
        } catch (ServiceNotFoundException $e) {
            self::assertSame("Service 'ct2b.front.ghost' not found (module: 'mymod').", $e->getMessage());
        }
    }
}

// ============================================================================
// Fixtures (Ct2b* prefix — unique across the whole test tree)
// ============================================================================

interface Ct2bLog {}

final class Ct2bFileLog implements Ct2bLog {}

final class Ct2bNullLog implements Ct2bLog {}

final class Ct2bLeaf {}

final class Ct2bCtxConsumer
{
    public function __construct(public readonly Ct2bLog $log) {}
}

final class Ct2bSelfCycle
{
    public function __construct(public readonly Ct2bSelfCycle $me) {}
}

final class Ct2bCycA
{
    public function __construct(public readonly Ct2bCycB $b) {}
}

final class Ct2bCycB
{
    public function __construct(public readonly Ct2bCycC $c) {}
}

final class Ct2bCycC
{
    public function __construct(public readonly Ct2bCycA $a) {}
}

final class Ct2bCycD
{
    public function __construct(public readonly Ct2bCycA $a) {}
}

final class Ct2bDiamondB {}

final class Ct2bDiamondC
{
    public function __construct(public readonly Ct2bDiamondB $b) {}
}

final class Ct2bDiamondD
{
    public function __construct(public readonly Ct2bDiamondB $b1, public readonly object $b2) {}
}

final class Ct2bVariadicConsumer
{
    /** @var list<Ct2bLog> */
    public readonly array $items;

    public function __construct(Ct2bLog ...$items)
    {
        $this->items = array_values($items);
    }
}

final class Ct2bScalarVariadic
{
    /** @var list<int> */
    public readonly array $nums;

    public function __construct(#[Value('ct2b.nums')] int ...$nums)
    {
        $this->nums = array_values($nums);
    }
}

final class Ct2bScalarVariadicBad
{
    /** @var list<int> */
    public readonly array $nums;

    public function __construct(#[Value('ct2b.notarray')] int ...$nums)
    {
        $this->nums = array_values($nums);
    }
}

final class Ct2bValueUser
{
    /**
     * @param array<string,mixed> $nested
     * @param array<string,mixed> $withNull
     */
    public function __construct(
        #[Value('ct2b.str')]
        public readonly string $str,
        #[Value('ct2b.float')]
        public readonly float $f,
        #[Value('ct2b.bool')]
        public readonly bool $b,
        #[Value('ct2b.zerostring')]
        public readonly string $zerostring,
        #[Value('ct2b.nested')]
        public readonly array $nested,
        #[Value('ct2b.withnull')]
        public readonly array $withNull,
    ) {}
}

final class Ct2bNullValueUser
{
    public function __construct(
        #[Value('ct2b.isnull')]
        public readonly string $v,
    ) {}
}

final class Ct2bMissingValueUser
{
    public function __construct(
        #[Value('ct2b.missing')]
        public readonly string $v,
    ) {}
}

final class Ct2bBadElemUser
{
    /** @param array<string,mixed> $cfg */
    public function __construct(
        #[Value('ct2b.badelem')]
        public readonly array $cfg,
    ) {}
}

final class Ct2bTopObjUser
{
    public function __construct(
        #[Value('ct2b.topobj')]
        public readonly string $v,
    ) {}
}

final class Ct2bInjectGhost
{
    public function __construct(
        #[Inject('ct2b.ghost')]
        public readonly Ct2bLog $log,
    ) {}
}

abstract class Ct2bAbstractBase {}

final class Ct2bAbstractUser
{
    public function __construct(public readonly Ct2bAbstractBase $base) {}
}

final class Ct2bNullableUser
{
    public function __construct(public readonly ?Ct2bLog $log) {}
}

final class Ct2bUnionDefaultUser
{
    public function __construct(public readonly int|string $mode = 'fallback') {}
}

final class Ct2bUnionRequiredUser
{
    public function __construct(public readonly int|string $mode) {}
}

final class Ct2bFactoryKit
{
    public static function make(): Ct2bLog
    {
        return new Ct2bFileLog();
    }
}

final class Ct2bRecordingGuard implements InitializationGuard
{
    /** @var list<string> */
    public array $keys = [];

    public function synchronized(string $key, \Closure $factory): mixed
    {
        $this->keys[] = $key;

        return $factory();
    }
}

final class Ct2bNullProvider implements ServiceProviderInterface
{
    /** @return list<string> */
    public function provides(): array
    {
        return [];
    }

    public function register(ServiceRegistrarInterface $container): void {}
}

final class Ct2bEagerProvider implements ServiceProviderInterface, BootableProviderInterface
{
    public int $registered = 0;
    public int $booted = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.eager.provided'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
    }

    public function boot(ServiceRegistrarInterface $container): void
    {
        ++$this->booted;
    }
}

final class Ct2bLazyProvider implements DeferrableProviderInterface, BootableProviderInterface
{
    public int $registered = 0;
    public int $booted = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.late.svc'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
        $container->register('ct2b.late.svc', static fn (): \stdClass => new \stdClass());
    }

    public function boot(ServiceRegistrarInterface $container): void
    {
        ++$this->booted;
    }
}

final class Ct2bDupProviderA implements DeferrableProviderInterface
{
    public int $registered = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.dup'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
        $container->register('ct2b.dup', static fn (): \stdClass => new \stdClass());
    }
}

final class Ct2bDupProviderB implements DeferrableProviderInterface
{
    public int $registered = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.dup'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
    }
}

final class Ct2bNobodyProvider implements DeferrableProviderInterface
{
    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.nobody'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        $container->register('ct2b.nobody', static fn (): \stdClass => new \stdClass());
    }
}

final class Ct2bInjectOk
{
    public function __construct(
        #[Inject('ct2b.log')]
        public readonly Ct2bLog $log,
    ) {}
}

final class Ct2bVariadicWithValue
{
    /** @var list<Ct2bLog> */
    public readonly array $logs;

    public function __construct(#[Value('ct2b.varlist')] Ct2bLog ...$logs)
    {
        $this->logs = array_values($logs);
    }
}

enum Ct2bSuit: string
{
    case Hearts = 'hearts';
}

final class Ct2bEnumDefaultUser
{
    public function __construct(public readonly Ct2bSuit $suit = Ct2bSuit::Hearts) {}
}

final class Ct2bMixedUser
{
    public function __construct(public readonly mixed $payload) {}
}

final class Ct2bRequiredScalarUser
{
    public function __construct(public readonly int $required) {}
}

final class Ct2bTargetAbstractUser
{
    public function __construct(
        #[Target(Ct2bAbstractBase::class)]
        public readonly Ct2bAbstractBase $base,
    ) {}
}

final class Ct2bSharedValueUserA
{
    public function __construct(
        #[Value('ct2b.shared')]
        public readonly string $v,
    ) {}
}

final class Ct2bSharedValueUserB
{
    public function __construct(
        #[Value('ct2b.shared')]
        public readonly string $v,
    ) {}
}

final class Ct2bTwinDepUser
{
    public function __construct(public readonly Ct2bLog $a, public readonly Ct2bLog $b) {}
}

final class Ct2bMultiIdProvider implements DeferrableProviderInterface
{
    public int $registered = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.dup.1', 'ct2b.dup.2'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
        $container->register('ct2b.dup.1', static fn (): \stdClass => new \stdClass());
        $container->register('ct2b.dup.2', static fn (): \stdClass => new \stdClass());
    }
}

final class Ct2bSecondProvider implements DeferrableProviderInterface
{
    public int $registered = 0;

    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.dup.2'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        ++$this->registered;
    }
}

final class Ct2bAliasDepProvider implements DeferrableProviderInterface
{
    /** @return list<string> */
    public function provides(): array
    {
        return ['ct2b.dep.foralias'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        $container->register('ct2b.dep.foralias', static fn (): \stdClass => new \stdClass());
    }
}
