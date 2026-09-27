<?php

declare(strict_types=1);

/*
 * ZEF Framework — regression suite for ZEF-DEEP-11 (issue #165).
 *
 * The cross-scope budget in RadixTreeCompilerPass was keyed by the TARGET
 * prefix only, so unrelated consumer namespaces ate each other's budget
 * (audit repro: one ref from Zef\Orders + one from Zef\Crm into Zef\Billing
 * with maxCrossScopeRefs=1 falsely violated, while DependencyGraphValidator
 * allows the same graph — its budget is keyed from->to).
 *
 * The budget is now keyed per (consumerPrefix => targetPrefix) pair:
 * consumers inside their own guarded (internal/module) namespace carry
 * that prefix; unscoped or public consumers share the '' bucket, keeping
 * the zero-budget deny-all and the single-bucket counting for them.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Policy\NamespaceScopePolicy;

/**
 * @internal
 */
final class RadixCrossScopeBudgetPairTest extends TestCase
{
    /** The audit repro: two unrelated guarded consumers, budget 1 each side — must pass. */
    public function testUnrelatedGuardedConsumersGetSeparateBudgets(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: [
                'Zef\Orders' => NamespaceRadixTree::SCOPE_MODULE,
                'Zef\Crm' => NamespaceRadixTree::SCOPE_MODULE,
                'Zef\Billing' => NamespaceRadixTree::SCOPE_MODULE,
            ],
            maxCrossScopeRefs: 1,
        ));
        $container->register('Zef\Orders\Checkout', fn (): \stdClass => new \stdClass(), ['Zef\Billing\Invoice']);
        $container->register('Zef\Crm\Sync', fn (): \stdClass => new \stdClass(), ['Zef\Billing\CreditNote']);
        $container->register('Zef\Billing\Invoice', fn (): \stdClass => new \stdClass());
        $container->register('Zef\Billing\CreditNote', fn (): \stdClass => new \stdClass());

        $container->validateAndFreeze();

        self::assertInstanceOf(\stdClass::class, $container->get('Zef\Orders\Checkout'));
        self::assertInstanceOf(\stdClass::class, $container->get('Zef\Crm\Sync'));
    }

    /** The budget still bites within ONE pair: 2 distinct targets, budget 1. */
    public function testBudgetStillEnforcedPerPair(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: [
                'Zef\Orders' => NamespaceRadixTree::SCOPE_MODULE,
                'Zef\Billing' => NamespaceRadixTree::SCOPE_MODULE,
            ],
            maxCrossScopeRefs: 1,
        ));
        $container->register('Zef\Orders\Checkout', fn (): \stdClass => new \stdClass(), ['Zef\Billing\Invoice', 'Zef\Billing\Dunning']);
        $container->register('Zef\Billing\Invoice', fn (): \stdClass => new \stdClass());
        $container->register('Zef\Billing\Dunning', fn (): \stdClass => new \stdClass());

        $this->expectException(ModuleDependencyViolationException::class);
        $this->expectExceptionMessage('Zef\Orders=>Zef\Billing');
        $container->validateAndFreeze();
    }

    /** Repeated same-target edges from the same pair dedup (distinct services count). */
    public function testSameTargetEdgeDedupsWithinPair(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: [
                'Zef\Orders' => NamespaceRadixTree::SCOPE_MODULE,
                'Zef\Billing' => NamespaceRadixTree::SCOPE_MODULE,
            ],
            maxCrossScopeRefs: 1,
        ));
        $container->register('Zef\Orders\Checkout', fn (): \stdClass => new \stdClass(), ['Zef\Billing\Invoice']);
        $container->register('Zef\Orders\Return', fn (): \stdClass => new \stdClass(), ['Zef\Billing\Invoice']);
        $container->register('Zef\Billing\Invoice', fn (): \stdClass => new \stdClass());

        $container->validateAndFreeze();

        self::assertInstanceOf(\stdClass::class, $container->get('Zef\Orders\Return'));
    }

    /** A guarded consumer's budget is NOT eaten by a sibling guarded consumer. */
    public function testGuardedConsumerKeepsBudgetWhenSiblingAlsoReferences(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: [
                'Zef\Orders' => NamespaceRadixTree::SCOPE_INTERNAL,
                'Zef\Crm' => NamespaceRadixTree::SCOPE_MODULE,
                'Zef\Billing' => NamespaceRadixTree::SCOPE_MODULE,
            ],
            maxCrossScopeRefs: 1,
        ));
        // Internal-scoped consumer still carries its prefix as the `from` key.
        $container->register('Zef\Orders\Checkout', fn (): \stdClass => new \stdClass(), ['Zef\Billing\Invoice']);
        $container->register('Zef\Crm\Sync', fn (): \stdClass => new \stdClass(), ['Zef\Billing\CreditNote']);
        $container->register('Zef\Billing\Invoice', fn (): \stdClass => new \stdClass());
        $container->register('Zef\Billing\CreditNote', fn (): \stdClass => new \stdClass());

        $container->validateAndFreeze();

        self::assertInstanceOf(\stdClass::class, $container->get('Zef\Orders\Checkout'));
    }

    /** Unscoped consumers keep sharing ONE bucket per target (legacy semantics). */
    public function testUnscopedConsumersShareTheAnonymousBucket(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['App\ModuleB' => NamespaceRadixTree::SCOPE_MODULE],
            maxCrossScopeRefs: 1,
        ));
        $container->register('App\ModuleA\One', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB']);
        $container->register('App\ModuleA\Two', fn (): \stdClass => new \stdClass(), ['App\ModuleB\OtherB']);
        $container->register('App\ModuleB\TargetB', fn (): \stdClass => new \stdClass());
        $container->register('App\ModuleB\OtherB', fn (): \stdClass => new \stdClass());

        $this->expectException(ModuleDependencyViolationException::class);
        $this->expectExceptionMessage('=>App\ModuleB');
        $container->validateAndFreeze();
    }

    /** Public-scoped consumer prefixes join the anonymous bucket, not their own. */
    public function testPublicScopedConsumersJoinTheAnonymousBucket(): void
    {
        $container = new Container();
        $container->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: [
                'App\Pub' => NamespaceRadixTree::SCOPE_PUBLIC,
                'App\ModuleB' => NamespaceRadixTree::SCOPE_MODULE,
            ],
            maxCrossScopeRefs: 1,
        ));
        $container->register('App\Pub\One', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB']);
        $container->register('App\Pub\Two', fn (): \stdClass => new \stdClass(), ['App\ModuleB\OtherB']);
        $container->register('App\ModuleB\TargetB', fn (): \stdClass => new \stdClass());
        $container->register('App\ModuleB\OtherB', fn (): \stdClass => new \stdClass());

        $this->expectException(ModuleDependencyViolationException::class);
        $container->validateAndFreeze();
    }
}
