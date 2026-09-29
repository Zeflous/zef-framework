<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.23.0 (issue #60 P3: memoization of repeated
 * pattern queries over the immutable configuration bag).
 */

namespace Zef\Framework\Config;

/**
 * Memoizes repeated {@see Config::query()} pattern results.
 *
 * `Config::query('database.connections.*.host')` fans out over the radix
 * index on every call; for hot paths that issue the SAME handful of patterns
 * per request (connection enumeration, feature-flag sweeps, per-driver
 * settings), this decorator answers every call after the first from an
 * in-process memo. Results are keyed by the exact pattern string, so the
 * cache's cardinality is bounded by the number of DISTINCT patterns an
 * application uses — never by data values.
 *
 * Invalidation is structural, not temporal: {@see Config} is immutable and
 * this cache is bound to ONE bag instance for its whole lifetime, so a
 * re-freeze/re-compile of the configuration (always a NEW Config instance)
 * simply gets a NEW cache — stale answers are impossible by construction.
 * {@see clear()} exists for long-lived workers that want the memo footprint
 * back between jobs.
 *
 * The issue #60 sketch wraps the radix tree directly; wrapping the public
 * {@see Config} surface instead keeps the tree `@internal` (it is private to
 * the bag) while providing the identical keyed memoization, and lets the
 * decorator benefit from the bag's sorted, deterministic result shape.
 * Copy-on-write makes returning the memoized array safe: callers who mutate
 * their result get a private copy, the cached series is untouched.
 *
 * Scope is deliberately ONLY the pattern query: `subtree()` and
 * `longestMatch()` take arbitrary concrete keys, and caching those would
 * grow with data (unbounded cardinality) for a microsecond hash-map walk.
 */
final class PatternQueryCache
{
    /** @var array<string,array<string,mixed>> pattern => cached result */
    private array $memo = [];

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(private readonly Config $config) {}

    /**
     * Wildcard query over the wrapped bag — identical semantics and result
     * shape to {@see Config::query()}; repeated patterns hit the memo.
     *
     * @return array<string,mixed>
     */
    public function query(string $pattern): array
    {
        if (array_key_exists($pattern, $this->memo)) {
            ++$this->hits;

            return $this->memo[$pattern];
        }
        ++$this->misses;
        $result = $this->config->query($pattern);
        $this->memo[$pattern] = $result;

        return $result;
    }

    /**
     * Drop the memo (counters too). The wrapped bag is untouched — the next
     * query() repopulates on demand.
     */
    public function clear(): void
    {
        $this->memo = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Cache efficiency snapshot for tests and diagnostics: how many queries
     * were served, how many from the memo, and how many distinct patterns
     * are currently resident.
     *
     * @return array{queries:int,hits:int,misses:int,patterns:int}
     */
    public function stats(): array
    {
        return [
            'queries' => $this->hits + $this->misses,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'patterns' => count($this->memo),
        ];
    }
}
