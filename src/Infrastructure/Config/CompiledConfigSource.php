<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Loads a configuration file produced by {@see ConfigCompiler}: a pure-PHP
 * file returning the validated value tree. Production boots then skip all
 * source parsing, environment reads and secret lookups.
 */
final readonly class CompiledConfigSource implements ConfigSourceInterface
{
    public function __construct(
        private string $path,
        private ?string $name = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name ?? 'compiled:' . basename($this->path);
    }

    #[\Override]
    public function load(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' does not exist or is not readable."
            );
        }

        try {
            // Plain require (not require_once): load() may run repeatedly for the
            // same file within one process (reload / re-merge). require_once would
            // return `true` after the first include and break every later read.
            $values = require $this->path;
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' failed to load: {$e->getMessage()}",
                0,
                $e,
            );
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' must return an associative array."
            );
        }

        return $values; // @phpstan-ignore return.type (top-level associativity validated above)
    }
}
