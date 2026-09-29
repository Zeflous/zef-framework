<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Application layer: orchestration,
 * use-case services). Added in v2.23.0 (issue #60 P4: ordered schema-version
 * migration path for configuration data — the mechanism future major
 * versions need, deliberately unused while every schema is version 1).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Registry of configuration migration steps, applied in ORDER during load.
 *
 * A step is a pure `callable(array): array` over the merged RAW value tree
 * (before secret resolution, validation and defaults): renaming keys,
 * restructuring subtrees, converting value shapes. Steps are registered per
 * TARGET version (`to(2, $step)` produces version 2 data) and run in
 * registration order within a version hop; the pipeline walks the version
 * ladder one hop at a time:
 *
 *     $migrator = new ConfigMigrator();
 *     $migrator->to(2, fn (array $v) => DottedPaths::set($v, 'database.host', $v['db_host'] ?? '127.0.0.1'));
 *
 * Guarantees:
 * - ASCENDING only — migrating to an older version is rejected loudly
 *   ({@see InvalidConfigurationException}); downgrades are lossy by nature
 *   and refuse to pretend otherwise;
 * - COMPLETENESS — a hop without a registered step fails fast instead of
 *   silently validating v(n-1) data against a v(n) schema;
 * - ORDER — multiple steps targeting the same version run in the order they
 *   were registered, matching the framework's deterministic-boot philosophy;
 * - steps see only CONFIG DATA. Secret VALUES are not yet resolved at
 *   migration time (the loader resolves `%secret:...%` afterwards), and a
 *   step must never fabricate them.
 *
 * The loader integration is opt-in: {@see ConfigLoader} only migrates when a
 * migrator AND a schema are provided, and the incoming data's version is
 * declared by the caller (see the loader's `$sourceSchemaVersion`). Until a
 * real schema-breaking release registers its first hop, this class is a
 * tested, documented no-op in every default boot.
 */
final class ConfigMigrator
{
    /** @var array<int, list<callable(array<array-key,mixed>):array<array-key,mixed>>> steps per target version */
    private array $steps = [];

    /**
     * Register a step whose output satisfies schema version `$targetVersion`.
     * Version 1 is the origin format and cannot have incoming steps.
     *
     * @param callable(array<array-key,mixed>):array<array-key,mixed> $step
     */
    public function to(int $targetVersion, callable $step): void
    {
        if ($targetVersion < 2) {
            throw new \InvalidArgumentException(
                'Config migration steps must target version 2 or higher, got ' . $targetVersion . '.'
            );
        }
        $this->steps[$targetVersion][] = $step;
    }

    /**
     * Bring `$values` from `$fromVersion` up to `$toVersion`, hop by hop.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     *
     * @throws InvalidConfigurationException when a downgrade is requested or
     *                                       a version hop has no registered
     *                                       step
     */
    public function migrate(array $values, int $fromVersion, int $toVersion): array
    {
        if ($fromVersion < 1 || $toVersion < 1) {
            throw new \InvalidArgumentException(
                'Config schema versions must be positive integers.'
            );
        }
        if ($toVersion < $fromVersion) {
            throw new InvalidConfigurationException(sprintf(
                'Config data is schema version %d, newer than the target version %d — downgrade migration is not supported.',
                $fromVersion,
                $toVersion,
            ));
        }
        for ($version = $fromVersion; $version < $toVersion; ++$version) {
            $target = $version + 1;
            if (!isset($this->steps[$target])) {
                throw new InvalidConfigurationException(sprintf(
                    'No registered config migration step to schema version %d (data is v%d, schema is v%d).',
                    $target,
                    $fromVersion,
                    $toVersion,
                ));
            }
            foreach ($this->steps[$target] as $step) {
                $values = $step($values);
                if (!is_array($values)) {
                    throw new InvalidConfigurationException(
                        "Config migration step to version {$target} must return an array, got "
                        . get_debug_type($values) . '.'
                    );
                }
            }
        }

        return $values;
    }

    /**
     * Registered step counts per target version — for diagnostics/tests.
     *
     * @return array<int,int>
     */
    public function stepCounts(): array
    {
        return array_map(count(...), $this->steps);
    }
}
