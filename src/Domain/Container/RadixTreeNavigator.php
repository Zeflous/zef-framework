<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 "RadixTree Namespace Container" (Domain layer).
 *
 * Segment-wise descent across the compressed edges of a NamespaceRadixTree.
 * Pure data structure helper: no state, no framework dependency.
 */

namespace Zef\Framework\Container;

/**
 * @internal descent machinery shared by NamespaceRadixTree lookups.
 *
 * Both query flavours (exact-id lookup and prefix enumeration) walk the
 * same compressed edges; they only disagree on how a query that ends
 * MID-EDGE is treated, so the shared hop lives here once.
 *
 * @phpstan-type RadixNode = array{ids: array<string, true>, children: array<string, mixed>, label?: string}
 */
final class RadixTreeNavigator
{
    /**
     * Descends along $segments for an exact-id lookup.
     *
     * A query that ends mid-edge or hits a missing/mismatching edge stops
     * at the deepest fully-consumed node with $descended reflecting
     * whether any edge was taken (the exact-id check then runs against
     * that node's ids, byte-identical to the previous inline loop).
     *
     * @param RadixNode    $root
     * @param list<string> $segments
     *
     * @return array{node: RadixNode, descended: bool}
     */
    public static function descendForLookup(array $root, array $segments): array
    {
        $node = $root;
        $remaining = $segments;
        $descended = false;
        while ($remaining !== []) {
            [$kind, $child, $remaining] = self::hop($node, $remaining);
            if ($kind !== 'descend') {
                // 'miss' or 'mid': the original loop broke with the node
                // left at its parent — nothing left to consume.
                break;
            }
            $node = $child;
            $descended = true;
        }

        return ['node' => $node, 'descended' => $descended];
    }

    /**
     * Descends to the node owning everything under a normalized prefix.
     *
     * @param RadixNode    $root
     * @param list<string> $segments
     *
     * @return array{node: null|RadixNode, found: bool}
     */
    public static function descendToPrefix(array $root, array $segments): array
    {
        $node = $root;
        $remaining = $segments;
        while ($remaining !== []) {
            [$kind, $child, $remaining, $prefixMatches] = self::hop($node, $remaining);
            if ($kind === 'miss') {
                return ['node' => null, 'found' => false];
            }
            if ($kind === 'mid') {
                // Prefix ends mid-edge: the whole edge subtree is under the
                // prefix iff the query segments match the label so far.
                return ['node' => $child, 'found' => $prefixMatches];
            }
            $node = $child;
        }

        return ['node' => $node, 'found' => true];
    }

    /**
     * One descent hop: resolves the child under the head segment and how
     * much of its (possibly compound) edge label the remaining query can
     * consume.
     *
     * @param RadixNode             $node
     * @param non-empty-list<string> $remaining
     *
     * @return array{0: string, 1: RadixNode, 2: list<string>, 3: bool}
     *         ['descend', child, unconsumed tail, true]
     *         ['mid', child, tail, partial label match]
     *         ['miss', node, tail, false]
     */
    private static function hop(array $node, array $remaining): array
    {
        $head = $remaining[0];

        /**
         * Children are homogeneous with their parent (the same node layout
         * documented on NamespaceRadixTree — built by RadixTreeCompilerPass
         * and insert()); the storage type widens them to mixed, so re-assert
         * the node shape here — the is_array guard still rejects anything
         * that is not an array at all.
         *
         * @var null|RadixNode $child
         */
        $child = $node['children'][$head] ?? null;
        if (is_array($child)) {
            $label = self::labelOf($child, $head);
            $labelCount = count($label);
            if (count($remaining) < $labelCount) {
                return ['mid', $child, $remaining, self::segmentsMatch($remaining, $label, count($remaining))];
            }
            if (self::segmentsMatch($remaining, $label, $labelCount)) {
                return ['descend', $child, array_slice($remaining, $labelCount), true];
            }
        }

        // No edge at all, or a full-length label mismatch: the shared miss
        // shape (no edge taken).
        return ['miss', $node, $remaining, false];
    }

    /**
     * Edge label of $child, split back into segments ('' labels fall back
     * to the child key itself).
     *
     * @param RadixNode $child
     *
     * @return list<string>
     */
    private static function labelOf(array $child, string $head): array
    {
        $label = $child['label'] ?? '';

        return $label !== '' ? explode('\\', $label) : [$head];
    }

    /**
     * Compares the first $count segments of $remaining against $label.
     *
     * @param list<string> $remaining
     * @param list<string> $label
     */
    private static function segmentsMatch(array $remaining, array $label, int $count): bool
    {
        for ($i = 0; $i < $count; ++$i) {
            if ($remaining[$i] !== $label[$i]) {
                return false;
            }
        }

        return true;
    }
}
