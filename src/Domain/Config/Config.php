<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * The typed, immutable configuration bag handed to application code.
 *
 * Built by the loader after merge/secret-resolution/validation/defaults, and
 * registered as a container singleton — handlers and services type-hint it
 * directly. Accessors apply the same strict grammar as the schema validator
 * ({@see ConfigValueType}); a missing or mistyped key is a configuration
 * error and throws {@see InvalidConfigurationException}.
 *
 * Typed accessors declare no runtime defaults by design: defaults live in
 * the schema ({@see ConfigKey::$default}) so a missing key is a startup
 * violation, never a silent fallback. Empty-string values only satisfy
 * string keys; every other type rejects them with a dedicated hint
 * ({@see ConfigValueType::rejectionHint()}).
 *
 * Since v2.21.1 the bag also carries a radix index ({@see ConfigRadixTree},
 * built once at construction) powering the pattern queries `query()`,
 * `subtree()` and `longestMatch()`; exact lookups keep their hash-map path.
 * Since v2.23.0 (issue #60 P2) the constructor accepts an optional PREBUILT
 * index — a cached {@see RadixTreeCache} rehydrates the compiled boot path
 * without rebuilding the tree from values.
 */
final readonly class Config
{
    private ConfigRadixTree $index;

    /**
     * @param array<array-key,mixed> $values
     * @param null|ConfigRadixTree $cachedIndex prebuilt radix index for the
     *                                          SAME value tree (the caller —
     *                                          typically {@see RadixTreeCache} —
     *                                          is responsible for the
     *                                          fingerprint match); null builds
     *                                          the index eagerly as before
     */
    public function __construct(private array $values, ?ConfigRadixTree $cachedIndex = null)
    {
        // php:S2830: the eager fallback goes through the named factory so the
        // constructor only picks between injected and derived state.
        $this->index = $cachedIndex ?? self::buildIndex($values);
    }

    /**
     * Full raw tree. Treat as read-only; PHP copy-on-write keeps the bag safe.
     *
     * @return array<array-key,mixed>
     *
     * Top-level keys are string-keyed by source contract; nested levels may
     * contain lists
     */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return DottedPaths::has($this->values, $key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return DottedPaths::has($this->values, $key)
            ? DottedPaths::valueAt($this->values, $key)
            : $default;
    }

    public function string(string $key): string
    {
        $value = $this->typed($key, ConfigValueType::String);
        assert(is_string($value));

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->typed($key, ConfigValueType::Int);
        assert(is_int($value));

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->typed($key, ConfigValueType::Float);
        assert(is_float($value));

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->typed($key, ConfigValueType::Bool);
        assert(is_bool($value));

        return $value;
    }

    /**
     * @return array<array-key,mixed>
     */
    public function array(string $key): array
    {
        $value = $this->typed($key, ConfigValueType::Array);
        assert(is_array($value));

        return $value;
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function enum(string $key, string $enumClass): \BackedEnum
    {
        if (!enum_exists($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class)) {
            throw new \InvalidArgumentException(
                "Config enum accessor for '{$key}' requires a backed enum class."
            );
        }

        $value = $this->typed($key, ConfigValueType::Enum, $enumClass);
        assert($value instanceof \BackedEnum);

        return $value;
    }

    /**
     * Sorted dotted paths of every leaf in the tree.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return DottedPaths::leafPaths($this->values);
    }

    /**
     * Wildcard query over the configuration tree, full dotted path => value
     * at the matched path. A `*` segment matches exactly one stored segment:
     * `query('database.connections.*.host')` returns every connection's host,
     * `query('cache.*.driver')` every cache driver. Sorted by path.
     *
     * @return array<string,mixed>
     */
    public function query(string $pattern): array
    {
        return $this->index->match($pattern);
    }

    /**
     * Every leaf under a literal prefix as relative path => value —
     * `subtree('database.connections.mysql')` returns the mysql block's
     * leaves without the prefix. An empty prefix returns the whole tree.
     *
     * @return array<string,mixed>
     */
    public function subtree(string $prefix): array
    {
        return $this->index->subtree($prefix);
    }

    /**
     * Resolve a concrete key through stored `*` template keys (most specific
     * template wins): with `services.*.timeout = 30` and
     * `services.payment.*.timeout = 60` configured,
     * `longestMatch('services.paypal.timeout')` yields 30 while
     * `longestMatch('services.payment.paypal.timeout')` yields 60. Returns
     * null when nothing matches.
     */
    public function longestMatch(string $key): mixed
    {
        return $this->index->longestMatch($key);
    }

    /**
     * @param array<array-key,mixed> $values
     */
    private static function buildIndex(array $values): ConfigRadixTree
    {
        return new ConfigRadixTree($values);
    }

    private function typed(string $key, ConfigValueType $type, ?string $enumClass = null): mixed
    {
        if (!DottedPaths::has($this->values, $key)) {
            throw new InvalidConfigurationException("Configuration key '{$key}' is not configured.");
        }
        $raw = DottedPaths::valueAt($this->values, $key);
        if (!$type->accepts($raw, $enumClass)) {
            throw new InvalidConfigurationException(
                "Configuration key '{$key}' expects {$type->value}, got " . ConfigValueType::describe($raw)
                . $type->rejectionHint($raw)
            );
        }

        return $type->coerce($raw, $enumClass);
    }
}
