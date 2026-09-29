<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 "RadixTree Namespace Container" (Domain layer).
 *
 * Path-compressed radix tree keyed on namespace separator segments ("\").
 * Pure data structure: no framework dependency, no reflection, no I/O.
 * Built once at container compile time (validateAndFreeze), sealed afterwards.
 */

namespace Zef\Framework\Container;

/**
 * Radix tree over service IDs segmented by the namespace separator.
 *
 * Node layout (plain arrays, var_export-safe for AOT caching):
 *   node = ['ids' => array<string,true>, 'children' => array<string,node>, 'label' => string]
 * The 'label' key holds the (possibly compound, e.g. "Framework\Container")
 * edge label; children are keyed by the FIRST segment of their label so
 * segment-wise traversal stays O(1) per node hop.
 *
 * @internal data structure; see RadixTreeCompilerPass for construction
 * (edge-label descent lives in RadixTreeNavigator)
 */
final class NamespaceRadixTree
{
    public const string SCOPE_PUBLIC = 'public';
    public const string SCOPE_INTERNAL = 'internal';
    public const string SCOPE_MODULE = 'module';

    public const array SCOPES = [self::SCOPE_PUBLIC, self::SCOPE_INTERNAL, self::SCOPE_MODULE];

    /**
     * @var array{ids:array<string,true>,children:array<string,array>}
     */
    private array $root = ['ids' => [], 'children' => []];

    /**
     * @var array<string,string> normalized prefix => scope kind
     */
    private array $annotations = [];
    private bool $sealed = false;
    private int $serviceCount = 0;
    private int $maxDepth = 0;
    private int $rawSegments = 0;

    public function insert(string $id): void
    {
        if ($this->sealed) {
            throw new \LogicException('NamespaceRadixTree is sealed; insert() is not allowed.');
        }
        if ($id === '') {
            throw new \InvalidArgumentException('Service ID must be a non-empty string.');
        }
        if ($this->containsExact($id)) {
            return; // idempotent insert
        }
        $segments = explode('\\', $id);
        $node = &$this->root;
        foreach ($segments as $segment) {
            $node['children'][$segment] ??= ['ids' => [], 'children' => []];
            $node = &$node['children'][$segment];
        }
        $node['ids'][$id] = true;
        ++$this->serviceCount;
        $this->rawSegments += count($segments);
        $this->maxDepth = max($this->maxDepth, count($segments));
    }

    /**
     * Annotate a namespace subtree with a scope kind (nearest-ancestor wins
     * at query time). Prefix is normalized to a trailing separator.
     */
    public function annotate(string $prefix, string $scope): void
    {
        if ($this->sealed) {
            throw new \LogicException('NamespaceRadixTree is sealed; annotate() is not allowed.');
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown namespace scope '{$scope}' (expected one of: "
                . implode(', ', self::SCOPES) . ').',
            );
        }
        $this->annotations[$this->normalizePrefix($prefix)] = $scope;
    }

    /** Compress single-child chains and seal the tree (read-only afterwards). */
    public function seal(): void
    {
        if ($this->sealed) {
            return;
        }
        $this->root = $this->compressNode($this->root, '');
        ksort($this->annotations);
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    public function containsExact(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        $result = RadixTreeNavigator::descendForLookup($this->root, explode('\\', $id));
        $node = $result['node'];

        return $result['descended'] && isset($node['ids'][$id]) && $node['ids'][$id];
    }

    /**
     * All service IDs whose namespace path lies under $prefix (segment-boundary
     * semantics, trailing separator auto-normalized). Sorted, deterministic.
     *
     * @return list<string>
     */
    public function idsUnderPrefix(string $prefix): array
    {
        $normalized = $this->normalizePrefix($prefix);
        $result = RadixTreeNavigator::descendToPrefix(
            $this->root,
            explode('\\', rtrim($normalized, '\\')),
        );
        if (!$result['found'] || $result['node'] === null) {
            return [];
        }
        $out = [];
        $this->collect($result['node'], $out);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Nearest scope annotation for $id (longest matching prefix),
     * or null when unannotated (= public by convention).
     *
     * @return null|array{prefix:string,scope:string}
     */
    public function scopeOf(string $id): ?array
    {
        $best = null;
        $bestLen = -1;
        foreach ($this->annotations as $prefix => $scope) {
            if (str_starts_with($id, $prefix) && strlen($prefix) > $bestLen) {
                $best = ['prefix' => $prefix, 'scope' => $scope];
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    /** @return array<string,string> normalized prefix => scope kind */
    public function annotations(): array
    {
        return $this->annotations;
    }

    /**
     * @return array{
     *     serviceIds:int, nodes:int, edges:int, maxDepth:int,
     *     rawSegments:int, compressionRatio:float, annotations:int, sealed:bool
     * }
     */
    public function stats(): array
    {
        $nodes = 0;
        $edges = 0;
        $this->countNodes($this->root, $nodes, $edges);

        return [
            'serviceIds' => $this->serviceCount,
            'nodes' => $nodes,
            'edges' => $edges,
            'maxDepth' => $this->maxDepth,
            'rawSegments' => $this->rawSegments,
            'compressionRatio' => $edges > 0 ? round($this->rawSegments / $edges, 4) : 1.0,
            'annotations' => count($this->annotations),
            'sealed' => $this->sealed,
        ];
    }

    /** AOT export: pure nested arrays (var_export-safe, no closures/objects). */
    public function exportArray(): array
    {
        return [
            'version' => 'v2.11.0',
            'sealed' => $this->sealed,
            'serviceCount' => $this->serviceCount,
            'maxDepth' => $this->maxDepth,
            'rawSegments' => $this->rawSegments,
            'annotations' => $this->annotations,
            'root' => $this->root,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['root']) || !is_array($data['root'])) {
            throw new \InvalidArgumentException('Invalid NamespaceRadixTree payload: missing root node.');
        }
        $tree = new self();
        $tree->root = $data['root'];
        $tree->annotations = is_array($data['annotations'] ?? null) ? $data['annotations'] : [];
        $tree->serviceCount = (int) ($data['serviceCount'] ?? 0);
        $tree->maxDepth = (int) ($data['maxDepth'] ?? 0);
        $tree->rawSegments = (int) ($data['rawSegments'] ?? 0);
        $tree->sealed = (bool) ($data['sealed'] ?? true);

        return $tree;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function normalizePrefix(string $prefix): string
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Namespace prefix must be a non-empty string.');
        }
        $trimmed = rtrim($prefix, '\\');

        return $trimmed === '' ? '\\' : $trimmed . '\\';
    }

    /**
     * Path compression (bottom-up): a node is eliminated when it is not the
     * root, holds no service IDs, and has exactly one child — its edge label
     * is absorbed into the child ("Zef" + "Framework\Container" chain merges
     * into one edge). Children are keyed by the first segment of their label.
     *
     * @param array{ids:array<string,true>,children:array<string,array>} $node
     */
    private function compressNode(array $node, string $label): array
    {
        $children = [];
        foreach ($node['children'] as $key => $child) {
            $compressed = $this->compressNode($child, $key);
            $first = explode('\\', $compressed['label'])[0];
            $children[$first] = $compressed;
        }
        ksort($children, SORT_STRING);
        $node['children'] = $children;

        if ($label !== '' && $node['ids'] === [] && count($children) === 1) {
            $only = $children[array_key_first($children)];
            $only['label'] = $label . '\\' . $only['label'];

            return $only;
        }
        $node['label'] = $label;

        return $node;
    }

    /** @param list<string> $out */
    private function collect(array $node, array &$out): void
    {
        foreach ($node['ids'] as $id => $_) {
            $out[] = $id;
        }
        foreach ($node['children'] as $child) {
            $this->collect($child, $out);
        }
    }

    private function countNodes(array $node, int &$nodes, int &$edges): void
    {
        ++$nodes;
        foreach ($node['children'] as $child) {
            ++$edges;
            $this->countNodes($child, $nodes, $edges);
        }
    }
}
