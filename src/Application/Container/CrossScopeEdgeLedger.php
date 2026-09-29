<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 "RadixTree Namespace Container" (Application layer).
 *
 * Cross-scope edge ledger used by RadixTreeCompilerPass during scope
 * enforcement: deduplicates (consumerPrefix => targetPrefix, dependency)
 * edges and counts distinct target services per guarded-namespace pair.
 *
 * @internal
 */

namespace Zef\Framework\Container;

/**
 * Mutable per-enforcement accumulator: {@see RadixTreeCompilerPass} walks the
 * compiled dependency graph once and feeds every candidate cross-scope edge
 * through this ledger.
 *
 * @internal
 */
final class CrossScopeEdgeLedger
{
    /** @var array<string,int> distinct target services per (consumerPrefix => targetPrefix) pair */
    private array $counts = [];

    /** @var array<string,true> edge keys already counted (pair|dep) */
    private array $edgeSeen = [];

    /**
     * Whether this (pair, dependency) edge has not been counted yet; records
     * it either way so repeated references to the same target service count
     * once per pair.
     */
    public function isNewEdge(string $pair, string $dep): bool
    {
        $edgeKey = $pair . '|' . $dep;
        if (isset($this->edgeSeen[$edgeKey])) {
            return false;
        }
        // @infection-ignore-all TrueValue — ekuivalen: edgeSeen hanya dibaca lewat isset(); nilai tidak relevan
        $this->edgeSeen[$edgeKey] = true;

        return true;
    }

    /**
     * Counts the pair and reports whether its distinct-target budget is now
     * exceeded.
     */
    public function exceedsWith(string $pair, int $budget): bool
    {
        $this->counts[$pair] = ($this->counts[$pair] ?? 0) + 1;

        return $this->counts[$pair] > $budget;
    }
}
