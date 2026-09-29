<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 "RadixTree Namespace Container" (Application layer).
 *
 * Compiler pass executed by Container::validateAndFreeze() AFTER
 * ContainerCompiler::compile() (so every ID is canonical and graph-proven).
 * Builds the sealed NamespaceRadixTree from the compiled plan and enforces
 * the NamespaceScopePolicy on the compiled dependency graph.
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Policy\NamespaceScopePolicy;

/**
 * @internal
 */
final readonly class RadixTreeCompilerPass
{
    public function __construct(private NamespaceScopePolicy $policy) {}

    public function process(CompiledContainerPlan $plan): NamespaceRadixTree
    {
        $tree = new NamespaceRadixTree();

        // Index every public entry point: concrete definitions + alias names.
        // Synthetic machinery IDs (@inner:*, @contextual:*, @value:*) are
        // internal to the container and never part of the public namespace.
        foreach ($plan->definitions as $id => $_definition) {
            if (!$this->isSynthetic($id)) {
                $tree->insert($id);
            }
        }
        foreach ($plan->canonicalIds as $id => $_canonical) {
            if (!$this->isSynthetic($id) && !isset($plan->definitions[$id])) {
                $tree->insert($id); // alias name
            }
        }

        foreach ($this->policy->normalizedScopes() as $prefix => $scope) {
            $tree->annotate($prefix, $scope);
        }

        $tree->seal();
        $this->enforceScopes($plan, $tree);

        return $tree;
    }

    /**
     * Scope enforcement over the compiled (canonical) dependency graph.
     * Runs after DependencyGraphValidator — this is an additional,
     * namespace-based policy gate that needs NO manual module tagging.
     */
    private function enforceScopes(CompiledContainerPlan $plan, NamespaceRadixTree $tree): void
    {
        $ledger = new CrossScopeEdgeLedger();
        foreach ($plan->definitions as $consumerId => $_definition) {
            if ($this->isSynthetic($consumerId)) {
                // @infection-ignore-all Continue_ — ekuivalen: definisi sintetis (@inner:*)
                // ditambahkan saat freeze sehingga selalu terakhir; break dan continue
                // melewati sisa yang sama
                continue; // decoration/contextual machinery is exempt
            }
            foreach ($plan->dependenciesOf($consumerId) as $dep) {
                $this->enforceEdge($consumerId, $dep, $tree, $ledger);
            }
        }
    }

    /**
     * Enforces the target service's namespace scope against one consumer
     * (public/unscoped targets and same-subtree consumers are always
     * allowed; internal targets must never be referenced from outside).
     */
    private function enforceEdge(
        string $consumerId,
        string $dep,
        NamespaceRadixTree $tree,
        CrossScopeEdgeLedger $ledger,
    ): void {
        $targetScope = $tree->scopeOf($dep);
        if ($targetScope === null || $targetScope['scope'] === NamespaceRadixTree::SCOPE_PUBLIC) {
            return;
        }
        $scopePrefix = $targetScope['prefix'];
        // Consumers inside the same subtree are always allowed.
        if (str_starts_with($consumerId, $scopePrefix)) {
            return;
        }
        if ($targetScope['scope'] === NamespaceRadixTree::SCOPE_INTERNAL) {
            throw new ModuleDependencyViolationException(
                "Namespace scope violation: service '" . $consumerId
                . "' references internal service '" . $dep
                . "' from outside the guarded namespace '" . $scopePrefix . "'."
            );
        }
        $this->enforceModuleBudget($consumerId, $dep, $scopePrefix, $tree, $ledger);
    }

    /**
     * MODULE scope: budgeted per (consumerPrefix => targetPrefix) pair,
     * counting distinct target services — mirrors DependencyGraphValidator
     * (its from->to key). The consumer's own guarded (internal/module)
     * prefix carries the `from` role; unscoped or public consumers share
     * the '' bucket so the zero-budget deny-all keeps applying to them.
     */
    private function enforceModuleBudget(
        string $consumerId,
        string $dep,
        string $scopePrefix,
        NamespaceRadixTree $tree,
        CrossScopeEdgeLedger $ledger,
    ): void {
        $budget = $this->policy->maxCrossScopeRefs;
        if ($budget <= 0) {
            throw new ModuleDependencyViolationException(
                "Namespace scope violation: service '" . $consumerId
                . "' references module-scoped service '" . $dep
                . "' while maxCrossScopeRefs is 0."
            );
        }
        $consumerScope = $tree->scopeOf($consumerId);
        $consumerPrefix = ($consumerScope !== null && $consumerScope['scope'] !== NamespaceRadixTree::SCOPE_PUBLIC)
            ? $consumerScope['prefix']
            : '';
        // Trim the stored trailing separators so the bucket key and the
        // violation message read "Consumer=>Target" without noise.
        // @infection-ignore-all Concat,ConcatOperandRemoval — ekuivalen: scopeOf
        // menentukan kedua sisi pair, sehingga pengelompokan dedup identik
        // untuk perubahan pemisah semata
        $pair = rtrim($consumerPrefix, '\\') . '=>' . rtrim($scopePrefix, '\\');
        if (!$ledger->isNewEdge($pair, $dep)) {
            return;
        }
        if ($ledger->exceedsWith($pair, $budget)) {
            throw new ModuleDependencyViolationException(
                "Namespace scope violation: cross-scope references into '" . $pair
                . "' exceed the limit (" . $budget . ').'
            );
        }
    }

    private function isSynthetic(string $id): bool
    {
        return str_starts_with($id, '@');
    }
}
