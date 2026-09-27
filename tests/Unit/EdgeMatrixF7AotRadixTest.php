<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7f — Container Autowiring (AOT) + namespace radix
 * scope enforcement (ronde 3).
 *
 * Kurikulum chunk f7-rest-c2 zona autowiring: entry AOT exact (shared per
 * lifetime, module null vs var_export, ltrim backslash), export atomik
 * (tmp+rename, indentasi, write-fail guard), loadDefinitions (missing file,
 * non-array, malformed entry LO-cluster, factory callable, deps list via
 * array_values, shared default per lifetime, module guard), radix scope
 * enforcement (internal violation exact, module budget 0/1/2 dengan pesan
 * persis, same-subtree exemption, public skip, synthetic decoration
 * exemption).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Autowiring\AutowireAotCompiler;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Policy\NamespaceScopePolicy;

/**
 * @internal
 */
final class EdgeMatrixF7AotRadixTest extends TestCase
{
    // --------------------------------------------- AOT: entry generation

    public function testAotServiceEntryShapeIsExact(): void
    {
        $entry = AutowireAotCompiler::generateServiceEntry(
            new AutowireMetadata('svc.a', '\App\Foo', ['dep.b'], [], 'mod.x', ServiceLifetime::SINGLETON),
            'static fn ($ctx) => new \App\Foo()',
        );
        $expected = <<<'PHP'
            'svc.a' => [
                        'factory' => static fn ($ctx) => new \App\Foo(),
                        'dependencies' => ['dep.b'],
                        'lifetime' => 'singleton',
                        'shared' => true,
                        'module' => 'mod.x',
                    ],
            PHP;
        self::assertSame($expected . "\n", $entry, 'singleton entries are shared=true');

        $transient = AutowireAotCompiler::generateServiceEntry(
            new AutowireMetadata('svc.t', 'stdClass', [], [], null, ServiceLifetime::TRANSIENT),
            'static fn ($ctx) => new stdClass()',
        );
        self::assertStringContainsString("'shared' => false,", $transient, 'transient entries are shared=false');
        self::assertStringContainsString("'module' => null,", $transient);
        // generateFactory: leading backslash pada className dinormalisasi ke satu.
        $factory = AutowireAotCompiler::generateFactory(
            new AutowireMetadata('svc.f', '\App\Foo', ['dep.b'], [['dep', 0]]),
        );
        self::assertSame('static fn ($ctx, $d0) => new \App\Foo($d0)', $factory);
    }

    public function testAotExportIsAtomicAndLoadable(): void
    {
        $result = new AutowireResult(
            [
                'svc.a' => new AutowireMetadata('svc.a', 'stdClass', ['dep.b'], [0 => ['dep', 0]]),
            ],
            [
                'svc.a' => 'static fn ($ctx, $d0) => new stdClass($d0)',
            ],
            [],
            [],
            []
        );
        $path = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
        AutowireAotCompiler::export($result, $path);

        try {
            $code = (string) file_get_contents($path);
            self::assertStringContainsString("        'svc.a' => [", $code, 'entries are indented by exactly 8 spaces');
            self::assertStringContainsString('generated file, do not edit', $code);
            $definitions = AutowireAotCompiler::loadDefinitions($path);
            self::assertArrayHasKey('svc.a', $definitions);
            $definition = $definitions['svc.a'];
            self::assertInstanceOf(ServiceDefinition::class, $definition);
            self::assertSame(['dep.b'], $definition->dependencies, 'deps must be a list');
            self::assertTrue(is_callable($definition->factory));
            self::assertTrue($definition->shared, 'singleton default shared');
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testAotExportTransientDefaultSharedFalse(): void
    {
        $result = new AutowireResult(
            [
                'svc.t' => new AutowireMetadata('svc.t', 'stdClass', [], [], null, ServiceLifetime::TRANSIENT),
            ],
            ['svc.t' => 'static fn ($ctx) => new stdClass()'],
            [],
            [],
            []
        );
        $path = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
        AutowireAotCompiler::export($result, $path);

        try {
            $definitions = AutowireAotCompiler::loadDefinitions($path);
            self::assertFalse($definitions['svc.t']->shared, 'transient default shared=false');
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testAotGuards(): void
    {
        $result = new AutowireResult([], [], []);

        try {
            @AutowireAotCompiler::export($result, '/nonexistent-dir-zef/aot.php');
            self::fail('Expected InvalidConfigurationException for an unwritable export path.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Cannot write AOT export file '/nonexistent-dir-zef/aot.php'.", $e->getMessage());
        }

        try {
            AutowireAotCompiler::loadDefinitions('/nonexistent-dir-zef/aot.php');
            self::fail('Expected InvalidConfigurationException for a missing AOT file.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("AOT file '/nonexistent-dir-zef/aot.php' does not exist.", $e->getMessage());
        }

        $bad = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($bad, "<?php return 'not-an-array';\n");

        try {
            AutowireAotCompiler::loadDefinitions($bad);
            self::fail('Expected InvalidConfigurationException for a non-array AOT file.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("AOT file '{$bad}' must return an array.", $e->getMessage());
        } finally {
            @unlink($bad); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testAotLoadRejectsMalformedEntries(): void
    {
        $codes = [
            "<?php return [123 => ['factory' => 'strlen', 'dependencies' => [], 'lifetime' => 'singleton']];",
            "<?php return ['svc.a' => ['dependencies' => [], 'lifetime' => 'singleton']];",
            "<?php return ['svc.a' => ['factory' => 'not-callable-at-all', 'dependencies' => [], 'lifetime' => 'singleton']];",
            "<?php return ['svc.a' => ['factory' => 'strlen', 'dependencies' => 'not-array', 'lifetime' => 'singleton']];",
            "<?php return ['svc.a' => ['factory' => 'strlen', 'dependencies' => [], 'lifetime' => 123]];",
        ];
        foreach ($codes as $code) {
            $path = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($path, $code);

            try {
                AutowireAotCompiler::loadDefinitions($path);
                self::fail('Expected InvalidConfigurationException for a malformed AOT entry.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('has a malformed entry for service', $e->getMessage());
                self::assertStringStartsWith("AOT file '", $e->getMessage());
            } finally {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use
            }
        }
    }

    public function testAotModuleGuardAndSharedOverride(): void
    {
        $path = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php return ['svc.a' => ['factory' => 'strlen', 'dependencies' => [], 'lifetime' => 'singleton', 'module' => 123]];");

        try {
            AutowireAotCompiler::loadDefinitions($path);
            $definitions = AutowireAotCompiler::loadDefinitions($path);
            self::assertNull($definitions['svc.a']->module, 'non-string module falls back to null');
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }

        $path2 = sys_get_temp_dir() . '/zef-aot-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path2, "<?php return ['svc.a' => ['factory' => 'strlen', 'dependencies' => [], 'lifetime' => 'singleton', 'shared' => false]];");

        try {
            $definitions = AutowireAotCompiler::loadDefinitions($path2);
            self::assertFalse($definitions['svc.a']->shared, 'explicit shared=false wins over the lifetime default');
        } finally {
            @unlink($path2); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testInternalScopeViolationIsExact(): void
    {
        $policy = new NamespaceScopePolicy(['app\internal' => NamespaceRadixTree::SCOPE_INTERNAL]);
        $container = $this->scopedContainer($policy, [
            'app\internal\Secret' => [],
            'outside.Client' => ['deps' => ['app\internal\Secret']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected a namespace scope violation.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertSame(
                "Namespace scope violation: service 'outside.Client' references internal service"
                . " 'app\\internal\\Secret' from outside the guarded namespace 'app\\internal\\'.",
                $e->getMessage(),
            );
        }
    }

    public function testSameSubtreeConsumerMayUseInternalService(): void
    {
        $policy = new NamespaceScopePolicy(['app\internal' => NamespaceRadixTree::SCOPE_INTERNAL]);
        $container = $this->scopedContainer($policy, [
            'app\internal\Secret' => [],
            'app\internal\Client' => ['deps' => ['app\internal\Secret']],
        ]);
        $container->validateAndFreeze();
        self::assertTrue($container->isFrozen(), 'consumers inside the subtree are always allowed');
    }

    public function testModuleScopeBudgetZeroIsRejectedWithExactMessage(): void
    {
        $policy = new NamespaceScopePolicy(['mod\core' => NamespaceRadixTree::SCOPE_MODULE], 0);
        $container = $this->scopedContainer($policy, [
            'mod\core\Service' => [],
            'outside.Client' => ['deps' => ['mod\core\Service']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected a module-scope violation for budget 0.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertSame(
                "Namespace scope violation: service 'outside.Client' references module-scoped service"
                . " 'mod\\core\\Service' while maxCrossScopeRefs is 0.",
                $e->getMessage(),
            );
        }
    }

    public function testModuleScopeBudgetCountsDistinctTargets(): void
    {
        $policy = new NamespaceScopePolicy(['mod\core' => NamespaceRadixTree::SCOPE_MODULE], 1);
        $container = $this->scopedContainer($policy, [
            'mod\core\A' => [],
            'mod\core\B' => [],
            'outside.Client' => ['deps' => ['mod\core\A', 'mod\core\B']],
        ]);

        try {
            $container->validateAndFreeze();
            self::fail('Expected a cross-scope budget violation on the second distinct target.');
        } catch (ModuleDependencyViolationException $e) {
            // Budget pairs are keyed consumer=>target (ZEF-DEEP-11); an
            // unscoped consumer shares the anonymous '' bucket.
            self::assertSame(
                "Namespace scope violation: cross-scope references into '=>mod\\core' exceed the limit (1).",
                $e->getMessage(),
            );
        }
    }

    public function testModuleScopeBudgetOneAcceptsSingleDistinctTarget(): void
    {
        $policy = new NamespaceScopePolicy(['mod\core' => NamespaceRadixTree::SCOPE_MODULE], 1);
        $container = $this->scopedContainer($policy, [
            'mod\core\A' => [],
            'outside.Client' => ['deps' => ['mod\core\A']],
        ]);
        $container->validateAndFreeze();
        self::assertTrue($container->isFrozen());
    }

    public function testPublicScopeReferencesAreAlwaysAllowed(): void
    {
        $policy = new NamespaceScopePolicy(['pub' => NamespaceRadixTree::SCOPE_PUBLIC]);
        $container = $this->scopedContainer($policy, [
            'pub\Service' => [],
            'outside.Client' => ['deps' => ['pub\Service']],
        ]);
        $container->validateAndFreeze();
        self::assertTrue($container->isFrozen());
    }

    public function testDecoratedMachineryIsExemptFromScopeChecks(): void
    {
        $policy = new NamespaceScopePolicy(['app\internal' => NamespaceRadixTree::SCOPE_INTERNAL]);
        $container = new Container();
        $container->registerDefinition(new ServiceDefinition(
            'app\internal\Secret',
            static fn (): \stdClass => new \stdClass(),
        ));
        $container->register('outside.Client', static fn (): \stdClass => new \stdClass(), ['app\internal\Secret']);
        $container->decorate('outside.Client', static fn (object $inner): object => $inner);
        $container->configureNamespacePolicy($policy);
        $container->validateAndFreeze(); // @inner:* machinery exempt, consumer allowed via alias rewiring
        self::assertTrue($container->isFrozen());
    }

    // --------------------------------------------- Radix tree scope enforcement

    private function scopedContainer(NamespaceScopePolicy $policy, array $register): Container // @phpstan-ignore-line
    {
        $container = new Container();
        foreach ($register as $id => $spec) {
            $container->registerDefinition(new ServiceDefinition(
                $id,
                static fn (): \stdClass => new \stdClass(),
                $spec['deps'] ?? [], // @phpstan-ignore-line
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
}
