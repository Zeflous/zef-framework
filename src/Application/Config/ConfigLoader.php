<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Application layer: orchestration,
 * use-case services). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Orchestrates the configuration pipeline in one deterministic order:
 *
 * 1. merge sources in registration order (LATER sources override EARLIER);
 * 1b. since v2.23.0 (issue #60 P4): when a {@see ConfigMigrator} is bound
 *     AND the incoming data is stamped with an older schema version
 *     (`$sourceSchemaVersion` < schema version), run the registered
 *     migration steps IN ORDER on the merged raw tree;
 * 2. resolve `%secret:name%` references through the secrets port;
 * 3. validate against the schema (collect ALL violations);
 * 4. apply declared defaults;
 * 5. wrap the result in an immutable {@see Config} bag.
 *
 * Any violation fails {@see load()} with a single
 * {@see ConfigValidationException} carrying the full report — startup is
 * fail-fast, diagnostics are complete.
 */
final readonly class ConfigLoader
{
    /**
     * Full-value secret reference grammar. Inline fragments are never
     * resolved — only values that are ENTIRELY a reference.
     */
    private const string SECRET_PATTERN = '/^%secret:([A-Za-z0-9._-]{1,128})%$/';

    private const string SOURCE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/';

    /**
     * @param list<ConfigSourceInterface> $sources later sources override earlier
     * @param null|ConfigMigrator $migrator         optional ordered migration
     *                                             steps (issue #60 P4); requires
     *                                             a schema
     * @param null|int $sourceSchemaVersion         schema version the RAW
     *                                             incoming data carries
     *                                             (defaults to
     *                                             {@see ConfigSchema::CURRENT_VERSION}
     *                                             — no migration)
     */
    public function __construct(
        private array $sources,
        private ?SecretsProviderInterface $secrets = null,
        private ?ConfigSchema $schema = null,
        private ?ConfigMigrator $migrator = null,
        private ?int $sourceSchemaVersion = null,
    ) {
        $seen = [];
        foreach (array_values($sources) as $source) {
            if (!$source instanceof ConfigSourceInterface) {
                throw new \InvalidArgumentException(
                    'Config sources must implement ConfigSourceInterface, got ' . get_debug_type($source) . '.'
                );
            }
            $name = $source->name();
            if (preg_match(self::SOURCE_NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException("Invalid config source name '{$name}'.");
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException("Duplicate config source name '{$name}'.");
            }
            $seen[$name] = true;
        }
        if ($this->migrator instanceof ConfigMigrator && !$this->schema instanceof ConfigSchema) {
            throw new \InvalidArgumentException(
                'A config migrator requires a schema to target (pass ConfigSchema too).'
            );
        }
        if ($this->sourceSchemaVersion !== null && !$this->migrator instanceof ConfigMigrator) {
            throw new \InvalidArgumentException(
                'A source schema version requires a migrator to be meaningful.'
            );
        }
        if ($this->sourceSchemaVersion !== null && $this->sourceSchemaVersion < 1) {
            throw new \InvalidArgumentException(
                'Source schema version must be a positive integer, got ' . $this->sourceSchemaVersion . '.'
            );
        }
    }

    /**
     * @return list<string>
     */
    public function sourceNames(): array
    {
        return array_map(static fn (ConfigSourceInterface $s): string => $s->name(), array_values($this->sources));
    }

    /**
     * Merged raw values WITHOUT secret resolution, validation or defaults —
     * for diagnostics and tooling; production code consumes load().
     *
     * @return array<array-key,mixed>
     */
    public function raw(): array
    {
        return $this->mergeSources();
    }

    /**
     * @throws ConfigValidationException when secrets are unresolvable or the
     *                                    merged values violate the schema
     */
    public function load(): Config
    {
        $values = $this->mergeSources();
        $values = $this->migrateValues($values);
        $violations = [];
        $secretPaths = [];
        if ($this->secrets instanceof SecretsProviderInterface) {
            $resolved = $this->resolveSecrets($values, '', $violations, $secretPaths);

            $values = $resolved;
        }
        if ($this->schema instanceof ConfigSchema) {
            $validator = new ConfigSchemaValidator();
            $violations = [...$violations, ...$validator->validate($values, $this->schema, $secretPaths)];
            if ($violations === []) {
                $values = $validator->applyDefaults($values, $this->schema);
            }
        }
        if ($violations !== []) {
            throw new ConfigValidationException($violations);
        }

        return new Config($values, null, $secretPaths);
    }

    /**
     * Schema-version ladder (issue #60 P4): no-op unless a migrator AND a
     * source version older than the schema's version are bound. The merged
     * RAW tree migrates in order BEFORE secrets/validation/defaults — steps
     * rename and restructure, they must not resolve secrets.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     */
    private function migrateValues(array $values): array
    {
        if (!$this->migrator instanceof ConfigMigrator || !$this->schema instanceof ConfigSchema) {
            return $values;
        }
        $from = $this->sourceSchemaVersion ?? ConfigSchema::CURRENT_VERSION;

        return $this->migrator->migrate($values, $from, $this->schema->version);
    }

    /**
     * @return array<array-key,mixed>
     */
    private function mergeSources(): array
    {
        $merged = [];
        foreach (array_values($this->sources) as $source) {
            $values = $source->load();
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new InvalidConfigurationException(
                    "Config source '{$source->name()}' must return an associative array."
                );
            }
            $merged = self::mergeTree($merged, self::expandDotted($values));
        }

        return $merged;
    }

    /**
     * Dotted keys are path shorthand — `['database.port' => 5432]` and
     * `['database' => ['port' => 5432]]` are THE SAME configuration. Every
     * source is normalised to a fully nested tree before merging, so both
     * styles combine predictably. A dotted key expands at the level where it
     * is declared (relative to its parent array); within one source, the
     * later declaration wins and expanding a dotted key replaces a scalar
     * standing in its path.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     */
    private static function expandDotted(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $value = self::expandDotted($value);
            }
            if (str_contains($key, '.')) {
                $out = DottedPaths::set($out, $key, $value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Deterministic deep merge: associative arrays merge recursively, lists
     * and scalars replace wholesale, later side wins on conflicts.
     *
     * @param array<array-key,mixed> $base
     * @param array<array-key,mixed> $override
     *
     * @return array<array-key,mixed>
     */
    private static function mergeTree(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $baseValue = $base[$key] ?? null;
            if (is_array($value) && !array_is_list($value)
                && is_array($baseValue) && !array_is_list($baseValue)) {
                $base[$key] = self::mergeTree($baseValue, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Replaces `%secret:name%` string leaves; returns one violation per
     * unresolvable reference (tree order, keys sorted per level).
     *
     * @param array<array-key,mixed> $node
     * @param list<ConfigViolation> $violations
     * @param list<string> $secretPaths out: dotted paths of successfully
     *                                   resolved secret leaves (issue #308)
     *
     * @return array<array-key,mixed>
     */
    private function resolveSecrets(array $node, string $prefix, array &$violations, array &$secretPaths): array
    {
        $out = [];
        $keys = array_keys($node);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $value = $node[$key];
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out[$key] = $this->resolveSecrets($value, $path, $violations, $secretPaths);

                continue;
            }
            if (is_string($value) && preg_match(self::SECRET_PATTERN, $value, $m) === 1) {
                $resolved = $this->secrets?->get($m[1]);
                if ($resolved === null) {
                    $violations[] = new ConfigViolation($path, "references unknown secret '{$m[1]}'");
                    $out[$key] = $value;
                } else {
                    $out[$key] = $resolved;
                    $secretPaths[] = $path;
                }

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
