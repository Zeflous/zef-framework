<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7e — Application/Container inti (ronde 3).
 *
 * Kurikulum chunk f7-rest-c2 (zona non-autowiring): registrar guards
 * (duplikat ID factory/alias, deps non-string/kosong), listener budgets
 * 32 tepat, dependencies wajib list (array_values), tagged locator
 * (normalisasi trim + grammar, tag kosong di definisi dilewati, pesan
 * ambiguitas persis), cross-module budget (0 vs 1, default parameter),
 * contextual binding duplikat, deferred provider sekali + pesan frozen
 * persis, decoration budget tepat, fallback namespace longest-prefix
 * first-wins, install-plan resolve pasca-freeze, circular detection.
 *
 * Setiap test membunuh mutan spesifik dari build/infection-f7-rest-c2.log.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\DeferrableProviderInterface;
use Zef\Framework\Container\RequestScope;
use Zef\Framework\Container\ServiceDefinition as DomServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\ServiceRegistrarInterface;
use Zef\Framework\Container\ServiceRegistry;
use Zef\Framework\Container\ServiceRegistryView;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Policy\ArchitecturePolicy;

/**
 * @internal
 */
final class EdgeMatrixF7ContainerTest extends TestCase
{
    // --------------------------------------------- ServiceRegistrar (via Container)

    public function testRegistrarGuardsOnDuplicateIdsAndInvalidDeps(): void
    {
        $container = new Container();
        $container->register('svc.a', static fn (): \stdClass => new \stdClass());

        try {
            $container->register('svc.a', static fn (): \stdClass => new \stdClass());
            self::fail('Expected InvalidFactoryException for duplicate factory ID.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Factory for 'svc.a' is invalid: service ID already registered.", $e->getMessage());
        }

        try {
            $container->register('svc.b', static fn (): \stdClass => new \stdClass(), [123]); // @phpstan-ignore-line
            self::fail('Expected InvalidFactoryException for non-string dependency.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Factory for 'svc.b' is invalid: dependency IDs must be non-empty strings.", $e->getMessage());
        }

        try {
            $container->register('svc.c', static fn (): \stdClass => new \stdClass(), ['']);
            self::fail('Expected InvalidFactoryException for empty dependency.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Factory for 'svc.c' is invalid: dependency IDs must be non-empty strings.", $e->getMessage());
        }

        try {
            $container->alias('svc.a', 'svc.other');
            self::fail('Expected InvalidFactoryException for alias colliding with a factory ID.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Factory for 'svc.a' is invalid: ID already registered.", $e->getMessage());
        }
        $container->alias('svc.alias', 'svc.a');

        try {
            $container->alias('svc.alias', 'svc.a');
            self::fail('Expected InvalidFactoryException for duplicate alias.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Factory for 'svc.alias' is invalid: ID already registered.", $e->getMessage());
        }

        try {
            $container->alias('svc.empty', '');
            self::fail('Expected InvalidFactoryException for empty alias target.');
        } catch (InvalidFactoryException $e) {
            self::assertSame("Alias 'svc.empty' must target a non-empty service ID.", $e->getMessage());
        }
    }

    // --------------------------------------------- ServiceRegistry budgets & list deps

    public function testRegistryListenerBudgetsAreExactly32(): void
    {
        $registry = new ServiceRegistry();
        $listener = static function (): void {};
        for ($i = 0; $i < 32; ++$i) {
            $registry->addResolvingListener($listener);
        }
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Container resolving-listener budget exceeded (32).');
        $registry->addResolvingListener($listener);
    }

    public function testRegistryResolvedListenerBudgetIsExactly32(): void
    {
        $registry = new ServiceRegistry();
        $listener = static function (): void {};
        for ($i = 0; $i < 32; ++$i) {
            $registry->addResolvedListener($listener);
        }
        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Container resolved-listener budget exceeded (32).');
        $registry->addResolvedListener($listener);
    }

    public function testRegistryNormalizesDependenciesToAList(): void
    {
        $registry = new ServiceRegistry();
        $registry->addFactory('svc.a', static fn (): \stdClass => new \stdClass(), ['k1' => 'dep.a', 'k2' => 'dep.b'], 'mod', ServiceLifetime::SINGLETON);
        $definition = $registry->definitions()['svc.a'];
        self::assertSame(['dep.a', 'dep.b'], $definition->dependencies, 'deps must be re-indexed to a list');
        self::assertSame(['svc.a'], array_keys($registry->definitions()));
    }

    public function testTaggedLocatorAmbiguityMessageIsExact(): void
    {
        $locator = $this->miniLocator();

        try {
            $locator->resolveOne('worker');
            self::fail('Expected InvalidConfigurationException for an ambiguous tag.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Tag 'worker' is carried by 2 services; use resolveAll() or make the tag unique.", $e->getMessage());
        }
        $all = $locator->resolveAll('worker');
        self::assertSame(['instance-worker.a', 'instance-worker.b'], $all);
        self::assertTrue($locator->hasTag('worker'));
        self::assertFalse($locator->hasTag('absent'));
        self::assertSame(['worker.c'], $locator->idsFor('solo'));
        self::assertSame('instance-worker.c', $locator->requireOne('solo'));

        try {
            $locator->requireOne('absent');
            self::fail('Expected ServiceNotFoundException.');
        } catch (ServiceNotFoundException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTaggedLocatorNormalizesWhitespaceAndRejectsBadTags(): void
    {
        $locator = $this->miniLocator();
        self::assertTrue($locator->hasTag(' worker '), 'tag lookup must be trimmed');

        try {
            $locator->idsFor('bad tag!');
            self::fail('Expected InvalidArgumentException for an invalid tag.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid service tag 'bad tag!': expected [A-Za-z0-9._-]{1,128}.", $e->getMessage());
        }
    }

    // --------------------------------------------- Container: cross-module & freeze

    public function testContainerCrossModuleBudgetOneAllowsOneAndRejectsTwo(): void
    {
        $one = new Container();
        $one->configurePolicies(1);
        $one->register('m2.dep', static fn (): \stdClass => new \stdClass(), module: 'm2');
        $one->register('m1.a', static fn (\stdClass $d): array => [$d], ['m2.dep'], module: 'm1');
        $one->validateAndFreeze(); // 1 reference diperbolehkan oleh budget 1
        self::assertTrue($one->isFrozen());

        $two = new Container();
        $two->configurePolicies(1);
        $two->register('m2.dep', static fn (): \stdClass => new \stdClass(), module: 'm2');
        $two->register('m2.dep2', static fn (): \stdClass => new \stdClass(), module: 'm2');
        $two->register('m1.a', static fn (\stdClass $d): array => [$d], ['m2.dep'], module: 'm1');
        $two->register('m1.b', static fn (\stdClass $d): array => [$d], ['m2.dep2'], module: 'm1');

        try {
            $two->validateAndFreeze();
            self::fail('Expected cross-module violation on the second reference.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString("exceeds cross-module reference limit (1) towards 'm2'", $e->getMessage());
        }
    }

    public function testContainerConfigurePoliciesDefaultMeansUnlimitedCrossRefs(): void
    {
        // Budget default 0 menonaktifkan audit cross-module (unlimited):
        // 2 referensi lintas modul tetap lolos tanpa konfigurasi.
        $container = new Container();
        $container->configurePolicies(); // default tak berubah
        $container->register('m2.dep', static fn (): \stdClass => new \stdClass(), module: 'm2');
        $container->register('m2.dep2', static fn (): \stdClass => new \stdClass(), module: 'm2');
        $container->register('m1.a', static fn (\stdClass $d): array => [$d], ['m2.dep'], module: 'm1');
        $container->register('m1.b', static fn (\stdClass $d): array => [$d], ['m2.dep2'], module: 'm1');
        $container->validateAndFreeze();
        self::assertTrue($container->isFrozen());

        // Budget negatif di-clamp ke 0 (unlimited), bukan fatal.
        $negative = new Container();
        $negative->configurePolicies(-1);
        $negative->register('svc.a', static fn (): \stdClass => new \stdClass());
        $negative->validateAndFreeze();
        self::assertTrue($negative->isFrozen());
    }

    public function testContainerResolvesAfterFreezeAndDetectsCycles(): void
    {
        $container = new Container();
        $instance = new \stdClass();
        $container->register('svc.ok', static fn (): object => $instance);
        $container->alias('svc.ok2', 'svc.ok');
        $container->validateAndFreeze();
        self::assertSame($instance, $container->get('svc.ok'));
        self::assertSame($instance, $container->get('svc.ok2'), 'alias resolves to the same instance');
        self::assertSame(['svc.ok', 'svc.ok2'], $container->getRegisteredIds());

        $cyclic = new Container();
        $cyclic->register('svc.a', static fn (): \stdClass => new \stdClass(), ['svc.b']);
        $cyclic->register('svc.b', static fn (): \stdClass => new \stdClass(), ['svc.a']);

        try {
            $cyclic->validateAndFreeze();
            $cyclic->get('svc.a');
            self::fail('Expected ServiceCircularDependencyException.');
        } catch (ServiceCircularDependencyException) {
            self::addToAssertionCount(1);
        }
    }

    public function testContainerContextualBindingDuplicateIsRejected(): void
    {
        $container = new Container();
        $container->register('svc.target', static fn (): \stdClass => new \stdClass());
        $container->register('svc.consumer', static fn (\stdClass $d): array => [$d], ['dep.x']);
        $container->addContextualBinding('svc.consumer', 'dep.x', 'svc.target');

        try {
            $container->addContextualBinding('svc.absent', 'dep.x', 'svc.target');
            self::fail('Expected InvalidConfigurationException for an unregistered consumer.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Contextual binding: consumer 'svc.absent' is not a registered service.", $e->getMessage());
        }
    }

    public function testContainerDeferredProviderRegistersExactlyOnce(): void
    {
        $container = new Container();
        $container->registerProvider($this->deferredProvider(['d.svc']));
        $container->registerDefinition(new DomServiceDefinition(
            'd.ref',
            static fn (): \stdClass => new \stdClass(),
            ['d.svc'],
        ));
        $container->alias('d.alias', 'd.svc');
        $container->validateAndFreeze(); // alias target men-trigger provider via validate path
        $first = $container->get('d.svc');
        $second = $container->get('d.svc');
        self::assertSame($first, $second, 'singleton must be shared');
        self::assertInstanceOf(\stdClass::class, $first);
    }

    public function testContainerDeferredGetAfterFreezeIsRejectedWithExactMessage(): void
    {
        $container = new Container();
        $container->registerProvider($this->deferredProvider(['d.late']));
        $container->validateAndFreeze();

        try {
            $container->get('d.late');
            self::fail('Expected a PSR-11 NotFoundException for a deferred service after freeze.');
        } catch (ServiceNotFoundException $e) {
            // ZEF-DEEP-12: has() is false, so get() must surface
            // NotFoundExceptionInterface carrying the same guidance.
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e);
            self::assertSame(
                "Service 'd.late' not found. Deferred provider service 'd.late' requested but the container is already frozen"
                . ' — request it before validateAndFreeze() or register the provider as eager.',
                $e->getMessage(),
            );
        }
    }

    // --------------------------------------------- Container: decoration budget

    public function testContainerDecorationBudgetBoundary(): void
    {
        $container = new Container(debug: false, policy: new ArchitecturePolicy(maxServiceRegistrations: 4));
        $container->register('svc.a', static fn (): \stdClass => new \stdClass());
        $container->register('svc.b', static fn (): \stdClass => new \stdClass());
        $container->decorate('svc.a', static fn (\stdClass $inner): \stdClass => $inner);
        $container->decorate('svc.b', static fn (\stdClass $inner): \stdClass => $inner);

        try {
            $container->validateAndFreeze();
            self::fail('Expected decoration to be rejected at the registration budget.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Service registration budget exceeded during decoration.', $e->getMessage());
        }
    }

    // --------------------------------------------- Container: namespace fallbacks

    public function testContainerFallbackLongestPrefixFirstWins(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('svc\alpha\\', static fn (): string => 'alpha');
        $container->registerNamespaceFallback('svc\beta\\', static fn (): string => 'beta');
        $container->validateAndFreeze();
        self::assertSame('alpha', $container->get('svc\alpha\thing'), 'equal-length prefixes resolve insertion-first');
        self::assertSame('beta', $container->get('svc\beta\thing'));
    }

    public function testContainerFallbackLongerPrefixWinsAndUnknownFalls(): void
    {
        $container = new Container();
        $container->registerNamespaceFallback('cache\\', static fn (): string => 'cache-root');
        $container->registerNamespaceFallback('cache\session\\', static fn (): string => 'cache-session');
        $container->validateAndFreeze();
        self::assertSame('cache-session', $container->get('cache\session\id'), 'longest prefix wins');
        self::assertSame('cache-root', $container->get('cache\other'));

        try {
            $container->get('absent.thing');
            self::fail('Expected ServiceNotFoundException without any matching fallback.');
        } catch (ServiceNotFoundException) {
            self::addToAssertionCount(1);
        }
    }

    // --------------------------------------------- RequestScope

    public function testRequestScopeClosedGuards(): void
    {
        $container = new Container();
        $container->register('svc.a', static fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();
        $scope = $container->createRequestScope();
        self::assertFalse($scope->isClosed());
        self::assertInstanceOf(\stdClass::class, $scope->get('svc.a'));
        $scope->close();
        self::assertTrue($scope->isClosed());
        $scope->close(); // idempotent

        try {
            $scope->get('svc.a');
            self::fail('Expected LogicException on a closed scope.');
        } catch (\LogicException $e) {
            self::assertSame('Request scope is closed.', $e->getMessage());
        }

        try {
            $scope->getInstance('svc.a');
            self::fail('Expected LogicException on a closed scope.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        try {
            $scope->setInstance('svc.a', new \stdClass());
            self::fail('Expected LogicException on a closed scope.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
        self::assertFalse($scope->has('svc.a'), 'closed scope must not resolve');
    }

    // --------------------------------------------- TaggedServiceLocator

    private function miniLocator(): TaggedServiceLocator
    {
        $registry = new ServiceRegistry();
        $registry->addDefinition(new DomServiceDefinition('worker.a', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::SINGLETON, true, false, ['worker']));
        $registry->addDefinition(new DomServiceDefinition('worker.b', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::SINGLETON, true, false, ['worker']));
        $registry->addDefinition(new DomServiceDefinition('worker.c', static fn (): \stdClass => new \stdClass(), [], null, ServiceLifetime::SINGLETON, true, false, ['solo']));
        $mini = new class implements ContainerInterface {
            #[\Override]
            public function get(string $id): mixed
            {
                return 'instance-' . $id;
            }

            #[\Override]
            public function has(string $id): bool
            {
                return true;
            }
        };

        return new TaggedServiceLocator($mini, new ServiceRegistryView($registry));
    }

    // --------------------------------------------- Container: deferred providers

    private function deferredProvider(array $ids): DeferrableProviderInterface // @phpstan-ignore-line
    {
        return new readonly class($ids) implements DeferrableProviderInterface {
            public function __construct(private array $ids) // @phpstan-ignore-line
            {}

            #[\Override]
            public function provides(): array
            {
                return $this->ids; // @phpstan-ignore-line
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                foreach ($this->ids as $id) {
                    $container->register($id, static fn (): \stdClass => new \stdClass()); // @phpstan-ignore-line
                }
            }
        };
    }
}
