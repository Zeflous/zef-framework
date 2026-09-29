<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zef\Framework\Container\Autowiring\AutowireAotCompiler;
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;
use Zef\Framework\Container\Autowiring\ReflectionMetadataExtractor;
use Zef\Framework\Container\CompiledContainerPlan;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ContainerCompiler;
use Zef\Framework\Container\NamespaceLayer;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Container\RequestScope;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Policy\NamespaceScopePolicy;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * Fase 10 — kurikulum edge-case adversarial: zona Container sisa.
 *
 * Setiap test menyebut mutator + baris target yang dibunuh (1 test = 1+ pembunuh mutan).
 * Mutan ekuivalen yang tersisa dianotasi @infection-ignore-all di source dengan justifikasi.
 *
 * @internal
 */
final class EdgeMatrixF10ContainerTest extends TestCase
{
    // ------------------------------------------------------------- AOT loader

    /** AutowireAotCompiler:133 UnwrapArrayValues — dependencies wajib reindex jadi list ketat. */
    public function testAotLoadReindexesStringKeyedDependencies(): void
    {
        $path = $this->writeAot(<<<'PHP'
            <?php
            return [
                'svc.a' => [
                    'factory' => static fn (): \stdClass => new \stdClass(),
                    'dependencies' => ['z' => 'dep.z', 'a' => 'dep.a'],
                    'lifetime' => 'singleton',
                ],
            ];
            PHP);

        try {
            $definitions = AutowireAotCompiler::loadDefinitions($path);
            self::assertSame(['dep.z', 'dep.a'], $definitions['svc.a']->dependencies, 'string-keyed deps wajib direindex jadi list');
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    /** AutowireAotCompiler:136 Identical — default shared mengikuti lifetime === SINGLETON. */
    public function testAotLoadDefaultsSharedToSingletonIdentity(): void
    {
        $path = $this->writeAot(<<<'PHP'
            <?php
            return [
                'svc.t' => [
                    'factory' => static fn (): \stdClass => new \stdClass(),
                    'dependencies' => [],
                    'lifetime' => 'transient',
                ],
            ];
            PHP);

        try {
            $definitions = AutowireAotCompiler::loadDefinitions($path);
            self::assertFalse($definitions['svc.t']->shared, 'lifetime non-singleton tanpa shared eksplisit wajib unshared');
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    // ------------------------------------------------------------- Autowire pass

    /** AutowireCompilerPass:320 LogicalOrSingleSubExprNegation + :337 InstanceOf_/Ternary + :342 LogicalAnd —
     * koleksi variadic wajib menangkap id interface, factory invokable-object, factory array-callable,
     * dan mengabaikan factory union-return tanpa fatal. */
    public function testVariadicTypeCollectionIsExact(): void
    {
        $container = new Container();
        $container->register(F10PingInterface::class, static fn (): F10PingService => new F10PingService(), []);
        $container->register('svc.invokable', new F10InvokableFactory(), []);
        $container->register('svc.arraycall', [new F10InvokableFactory(), 'produce'], []);

        $container->register('svc.union', static fn (): \Countable|F10PingInterface => \random_int(0, 1) === 0 ? new F10PingService() : new \ArrayObject(), []);
        $pass = new AutowireCompilerPass();
        $result = $pass->process($container, [F10VariadicCollector::class]);

        $deps = $result->metadata[F10VariadicCollector::class]->dependencies;
        self::assertContains(F10PingInterface::class, $deps, 'service id berupa interface wajib terkumpul sebagai provider tipe');
        self::assertContains('svc.invokable', $deps, 'factory object-invokable wajib dikenali lewat return type');
        self::assertContains('svc.arraycall', $deps, 'factory array-callable wajib dikenali lewat return type');
        self::assertNotContains('svc.union', $deps, 'factory union-return tidak boleh diklaim sebagai provider tipe');
    }

    /** AutowireCompilerPass:253 ReturnRemoval — nullable default wajib dirender satu argumen saja. */
    public function testNullableDefaultRendersSingleArgument(): void
    {
        $container = new Container();
        $pass = new AutowireCompilerPass();
        $result = $pass->process($container, [F10NullableDefaultConsumer::class]);

        $code = $result->factoryCode[F10NullableDefaultConsumer::class];
        self::assertStringContainsString(F10NullableDefaultConsumer::class . '(null)', $code, 'tepat satu argumen null');

        // False positive on argument shape, not provenance: `$code` is
        // `$result->factoryCode[F10NullableDefaultConsumer::class]`, a factory expression produced by
        // AutowireCompilerPass itself; the test evaluates the compiler's own output to assert it is
        // callable, which is the assertion's subject. Registered in docs/security/php-sast.md §7.5.
        $factory = eval('return ' . $code . ';'); // nosemgrep: eval-use

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        self::assertInstanceOf(F10NullableDefaultConsumer::class, $factory([]), 'factory hasil autowire wajib bisa dipanggil tanpa TypeError argumen ganda');
    }

    /** ReflectionMetadataExtractor:37 FalseValue — kelas tanpa konstruktor wajib hasConstructor=false. */
    public function testExtractorReportsMissingConstructor(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(F10NoConstructor::class);
        self::assertFalse($spec->hasConstructor);
        self::assertSame([], $spec->parameters);
    }

    /** ReflectionMetadataExtractor:54 FalseValue + :62 TrueValue — flag isBuiltinScalar eksak per jenis parameter. */
    public function testExtractorBuiltinScalarFlagsAreExact(): void
    {
        $extractor = new ReflectionMetadataExtractor();
        $intersection = $extractor->extract(F10IntersectionParam::class)->parameters[0];
        self::assertFalse($intersection->isBuiltinScalar, 'parameter intersection tidak menyentuh flag builtin');

        $int = $extractor->extract(F10IntParam::class)->parameters[0];
        self::assertTrue($int->isBuiltinScalar, 'parameter int adalah builtin scalar');
        self::assertNull($int->className);
    }

    // ------------------------------------------------------------- Container

    /** Container:148 MethodCallRemoval — plan wajib terpasang sebelum freeze; resolusi + has() pasca-freeze tetap benar. */
    public function testResolutionAndHasWorkAfterFreeze(): void
    {
        $container = new Container();
        $container->register('svc.ok', static fn (): \stdClass => new \stdClass(), []);
        $container->validateAndFreeze();

        self::assertInstanceOf(\stdClass::class, $container->get('svc.ok'), 'resolusi pasca-freeze memakai plan');
        self::assertTrue($container->has('svc.ok'));
        self::assertFalse($container->has('svc.missing'));

        try {
            $container->get('svc.missing');
            self::fail('Expected ServiceNotFoundException.');
        } catch (ServiceNotFoundException $e) {
            self::assertSame('svc.missing', $e->serviceId);
        }
    }

    /** Container:521 GreaterThanOrEqualTo + :522 Throw_ — budget dekorasi wajib pecah pada tepat count == budget. */
    public function testDecorationBudgetThrowsAtExactBoundary(): void
    {
        $container = new Container(false, new ArchitecturePolicy(maxServiceRegistrations: 2));
        $container->register('svc.outer', static fn (): \stdClass => new \stdClass(), []);
        $container->decorate('svc.outer', static fn (object $inner): object => $inner);

        try {
            $container->validateAndFreeze();
            self::fail('Expected decoration budget violation.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Service registration budget exceeded during decoration.', $e->getMessage());
        }
    }

    /** ContainerResolver:114/:178 LogicalAnd — singleton shared=false wajib selalu instance baru. */
    public function testSingletonSharedFalseResolvesFreshInstances(): void
    {
        $container = new Container();
        $counter = 0;
        $container->registerDefinition(new ServiceDefinition(
            'svc.fresh',
            static function () use (&$counter): \stdClass {
                ++$counter;

                return new \stdClass();
            },
            [],
            null,
            ServiceLifetime::SINGLETON,
            false, // shared=false: tidak boleh masuk cache instance
        ));
        $container->validateAndFreeze();

        $a = $container->get('svc.fresh');
        $b = $container->get('svc.fresh');
        self::assertNotSame($a, $b, 'singleton shared=false wajib resolusi ulang, bukan cache');
        self::assertSame(2, $counter);
    }

    /** ResolutionContext:43 GreaterThanOrEqualTo — guard kedalaman wajib pecah pada tepat count == budget. */
    public function testResolutionDepthThrowsExactlyAtBudget(): void
    {
        $container = new Container(false, new ArchitecturePolicy(maxResolutionDepth: 2));
        $container->register('deep.a', static fn (): \stdClass => new \stdClass(), ['deep.b']);
        $container->register('deep.b', static fn (): \stdClass => new \stdClass(), ['deep.c']);
        $container->register('deep.c', static fn (): \stdClass => new \stdClass(), []);
        $container->validateAndFreeze();

        try {
            $container->get('deep.a');
            self::fail('Expected resolution depth violation.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('Dependency resolution depth exceeds configured safety budget.', $e->getMessage());
        }
    }

    /** ServiceRegistrar:32 MethodCallRemoval — lifetime tak dikenal wajib ditolak di register(). */
    public function testRegistrarRejectsUnknownLifetime(): void
    {
        $container = new Container();

        try {
            $container->register('svc.bad', static fn (): \stdClass => new \stdClass(), [], null, 'eternal');
            self::fail('Expected unknown-lifetime rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Unknown service lifetime 'eternal'.", $e->getMessage());
        }
    }

    /** ContainerCompiler:27 IncrementInteger — default 0 berarti unlimited untuk pemanggil langsung. */
    public function testCompilerDefaultCrossModuleBudgetIsUnlimited(): void
    {
        $container = new Container();
        $container->register('a.one', static fn (): \stdClass => new \stdClass(), [], 'modA');
        $container->register('a.two', static fn (): \stdClass => new \stdClass(), [], 'modA');
        $container->register('b.one', static fn (): \stdClass => new \stdClass(), ['a.one', 'a.two'], 'modB');

        $compiler = new ContainerCompiler(new DependencyGraphValidator());
        $registry = new \ReflectionProperty(Container::class, 'registry')->getValue($container);

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        $plan = $compiler->compile($registry);
        self::assertInstanceOf(CompiledContainerPlan::class, $plan, 'dua edge cross-modul wajib lolos saat budget default 0 (unlimited)');
    }

    // ------------------------------------------------------------- RequestScope

    /** RequestScope:38 LogicalAnd negations + :42-:63 guards + :51 Throw_ — has/get/set pasca-close wajib eksak. */
    public function testRequestScopeHasAndClosedGuards(): void
    {
        $container = new Container();
        $container->register('svc.inscope', static fn (): \stdClass => new \stdClass(), []);
        $container->validateAndFreeze();
        $scope = $container->createRequestScope();

        self::assertTrue($scope->has('svc.inscope'), 'scope terbuka wajib tahu service yang ada');
        self::assertFalse($scope->has('svc.absent'), 'scope terbuka wajib menolak id asing');
        self::assertFalse($scope->hasInstance('svc.absent'), 'hasInstance pada scope kosong wajib false');

        $probe = new \stdClass();
        $scope->setInstance('svc.manual', $probe);
        self::assertTrue($scope->hasInstance('svc.manual'), 'setInstance wajib terbaca via hasInstance');
        self::assertSame($probe, $scope->getInstance('svc.manual'), 'getInstance wajib mengembalikan instance yang di-set');

        $scope->close();
        self::assertTrue($scope->isClosed());
        self::assertFalse($scope->hasInstance('svc.manual'), 'scope tertutup wajib lapor kosong');

        try {
            $scope->getInstance('svc.inscope');
            self::fail('Expected closed-scope rejection.');
        } catch (\LogicException $e) {
            self::assertSame('Request scope is closed.', $e->getMessage());
        }
    }

    /** ServiceRegistryView:27-:51 — seluruh endpoint view wajib dapat diakses publik. */
    public function testRegistryViewExposesAllEndpoints(): void
    {
        $container = new Container();
        $container->register('view.one', static fn (): \stdClass => new \stdClass(), ['view.two'], 'modV');
        $container->register('view.two', static fn (): \stdClass => new \stdClass(), [], 'modV');
        $view = $container->getRegistry();

        self::assertArrayHasKey('view.one', $view->definitions());
        self::assertArrayHasKey('view.one', $view->factories());
        self::assertSame([], $view->aliases());
        self::assertSame(['view.two'], $view->depsOf()['view.one']);
        self::assertSame('modV', $view->moduleOf()['view.one']);
        self::assertSame(ServiceLifetime::SINGLETON, $view->lifetimeOf()['view.one']);
    }

    // ------------------------------------------------------------- Tagged locator

    /** TaggedServiceLocator:134 PregMatchRemoveCaret — grammar tag wajib full-match, bukan cukup akhiran. */
    public function testTagGrammarRejectsEmbeddedInvalidCharacter(): void
    {
        $container = new Container();
        $container->registerDefinition(new ServiceDefinition(
            'svc.badtag',
            static fn (): \stdClass => new \stdClass(),
            [],
            null,
            ServiceLifetime::SINGLETON,
            true,
            false,
            ['inv@lid'],
        ));
        $container->validateAndFreeze();
        $locator = new TaggedServiceLocator($container, $container->getRegistry());

        try {
            $locator->idsFor('inv@lid');
            self::fail('Expected invalid-tag rejection.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid service tag 'inv@lid': expected [A-Za-z0-9._-]{1,128}.", $e->getMessage());
        }
    }

    // ------------------------------------------------------------- Radix tree

    /** RadixTreeCompilerPass:39 LogicalAnd — id sintetis (alias @contextual:*) wajib keluar dari pohon publik. */
    public function testSyntheticContextualAliasKeptOutOfTree(): void
    {
        $container = new Container();
        $container->register('svc.consumer', static fn (): \stdClass => new \stdClass(), ['dep.one']);
        $container->register('target.one', static fn (): \stdClass => new \stdClass());
        $container->when('svc.consumer')->needs('dep.one')->give('target.one');
        $container->validateAndFreeze();

        $tree = $this->reflectTree($container);
        self::assertNull($tree->scopeOf('@contextual:svc.consumer|dep.one'), 'alias sintetis kontekstual tidak boleh masuk pohon');
    }

    /** RadixTreeCompilerPass:70 Continue_ — dep publik di depan tidak boleh membatalkan pemeriksaan sisa deps. */
    public function testPublicDepBeforeViolationStillThrows(): void
    {
        $policy = new NamespaceScopePolicy(['app\internal' => NamespaceRadixTree::SCOPE_INTERNAL]);
        $container = $this->scopedContainer($policy, [
            'pub.Svc' => [],
            'app\internal\Secret' => [],
            'outside.Client' => ['deps' => ['pub.Svc', 'app\internal\Secret']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected violation after public dep.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString("references internal service 'app\\internal\\Secret'", $e->getMessage());
        }
    }

    /** RadixTreeCompilerPass:75 Continue_ — dep satu subtree di depan tidak boleh membatalkan pemeriksaan sisa deps. */
    public function testSameSubtreeDepBeforeCrossViolationStillThrows(): void
    {
        $policy = new NamespaceScopePolicy([
            'app\internal' => NamespaceRadixTree::SCOPE_INTERNAL,
            'zzz\internal' => NamespaceRadixTree::SCOPE_INTERNAL,
        ]);
        $container = $this->scopedContainer($policy, [
            'app\internal\Ok' => [],
            'zzz\internal\Secret' => [],
            'app\internal\Client' => ['deps' => ['app\internal\Ok', 'zzz\internal\Secret']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected cross-subtree violation after same-subtree dep.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString("'zzz\\internal\\Secret'", $e->getMessage());
        }
    }

    /** RadixTreeCompilerPass:89 Continue_ — edge yang sudah terhitung tidak boleh membatalkan pemeriksaan sisa deps. */
    public function testDedupedEdgeBeforeViolationStillThrows(): void
    {
        $policy = new NamespaceScopePolicy([
            'mod\core' => NamespaceRadixTree::SCOPE_MODULE,
            'app\internal' => NamespaceRadixTree::SCOPE_INTERNAL,
        ], 5);
        $container = $this->scopedContainer($policy, [
            'mod\core\A' => [],
            'app\internal\Secret' => [],
            'first.Client' => ['deps' => ['mod\core\A']],
            'second.Client' => ['deps' => ['mod\core\A', 'app\internal\Secret']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected violation after deduped cross-scope edge.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString("references internal service 'app\\internal\\Secret'", $e->getMessage());
        }
    }

    private function writeAot(string $content): string
    {
        $path = \sys_get_temp_dir() . '/f10_aot_' . \uniqid('', true) . '.php';
        \file_put_contents($path, $content);

        return $path;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    private function scopedContainer(NamespaceScopePolicy $policy, array $register): Container
    {
        $container = new Container();
        foreach ($register as $id => $spec) {
            $container->registerDefinition(new ServiceDefinition(
                $id,
                static fn (): \stdClass => new \stdClass(),

                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                $spec['deps'] ?? [],
                null,
                ServiceLifetime::SINGLETON,
                true,
                false,
                [],
            ));
        }
        $container->configureNamespacePolicy($policy);

        return $container;
    }

    private function reflectTree(Container $container): NamespaceRadixTree
    {
        // php:S2042 decomposition moved the tree into the NamespaceLayer collaborator.
        $layer = new \ReflectionProperty(Container::class, 'namespaces');
        $tree = new \ReflectionProperty(NamespaceLayer::class, 'namespaceTree');

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        return $tree->getValue($layer->getValue($container));
    }
}

// ------------------------------------------------------------- Fixtures

interface F10PingInterface {}

final class F10PingService implements F10PingInterface {}

final class F10InvokableFactory
{
    public function __invoke(): F10PingInterface
    {
        return new F10PingService();
    }

    public function produce(): F10PingInterface
    {
        return new F10PingService();
    }
}

final class F10InterfaceConsumer
{
    public function __construct(public F10PingInterface $ping) {}
}

final class F10VariadicCollector
{
    /** @var list<F10PingInterface> */
    public array $pings;

    public function __construct(F10PingInterface ...$pings)
    {
        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        $this->pings = $pings;
    }
}

final class F10NullableDefaultConsumer
{
    public function __construct(public ?LoggerInterface $log = null) {}
}

final class F10NoConstructor
{
    public function hello(): string
    {
        return 'hi';
    }
}

final class F10IntersectionParam
{
    public function __construct(public \Countable&LoggerInterface $both) {}
}

final class F10IntParam
{
    public function __construct(public int $count) {}
}
