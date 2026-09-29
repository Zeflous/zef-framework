<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Radix-tree namespace layer extracted from Container (php:S2042): the
 * namespace scope policy, the sealed tree built at freeze time, and the
 * prefix queries over it. Byte-identical move of the Container logic —
 * no behavioural changes.
 */

namespace Zef\Framework\Container;

use Zef\Framework\Policy\NamespaceScopePolicy;

final class NamespaceLayer
{
    private ?NamespaceScopePolicy $namespacePolicy = null;
    private ?NamespaceRadixTree $namespaceTree = null;

    /** Install a namespace scope policy enforced at build() time. */
    public function configurePolicy(NamespaceScopePolicy $policy): void
    {
        $this->namespacePolicy = $policy;
    }

    /** Seals the radix tree from the validated compile plan (freeze time). */
    public function build(CompiledContainerPlan $plan): void
    {
        $this->namespaceTree = new RadixTreeCompilerPass(
            $this->namespacePolicy ?? new NamespaceScopePolicy()
        )->process($plan);
    }

    /**
     * Batch-resolve every registered service under a namespace prefix
     * (deterministic ID-sorted order). Requires a frozen container — the
     * radix tree is built at freeze time.
     *
     * @return array<string,mixed>
     */
    public function getByPrefix(Container $container, string $prefix): array
    {
        $tree = $this->namespaceTree;
        if (!$tree instanceof NamespaceRadixTree) {
            throw new \LogicException('Namespace tree is not built yet — call validateAndFreeze() first.');
        }
        $out = [];
        foreach ($tree->idsUnderPrefix($prefix) as $id) {
            $out[$id] = $container->get($id);
        }

        return $out;
    }

    /** ID-only variant of getByPrefix() (no instantiation). @return list<string> */
    public function getIdsByPrefix(string $prefix): array
    {
        $tree = $this->namespaceTree;
        if (!$tree instanceof NamespaceRadixTree) {
            throw new \LogicException('Namespace tree is not built yet — call validateAndFreeze() first.');
        }

        return $tree->idsUnderPrefix($prefix);
    }

    /** The sealed namespace radix tree (null before freeze). */
    public function tree(): ?NamespaceRadixTree
    {
        return $this->namespaceTree;
    }

    /**
     * @return null|array{
     *     serviceIds:int, nodes:int, edges:int, maxDepth:int,
     *     rawSegments:int, compressionRatio:float, annotations:int, sealed:bool
     * }
     */
    public function stats(): ?array
    {
        return $this->namespaceTree?->stats();
    }
}
