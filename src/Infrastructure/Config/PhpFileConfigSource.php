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
 * Configuration source backed by a PHP file that returns an associative
 * array — the fast, opcache-friendly `config/app.php` style source.
 *
 * Parse errors and non-array returns become InvalidConfigurationException
 * with the file named, so a broken config file fails the boot loudly and
 * precisely.
 */
final readonly class PhpFileConfigSource implements ConfigSourceInterface
{
    public function __construct(
        private string $path,
        private ?string $name = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name ?? 'file:' . basename($this->path);
    }

    #[\Override]
    public function load(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new InvalidConfigurationException(
                "Config source file '{$this->path}' does not exist or is not readable."
            );
        }

        try {
            // Plain require (not require_once) is load-bearing: load() may be
            // called repeatedly for the same source file within one process
            // (config reloads, later-sources-override-earlier composition),
            // and require_once would hand back `true` after the first include,
            // silently breaking every later read. Same include-contract triage
            // as RouteCache::loadIfFresh() — see sonar-project.properties.
            $values = require $this->path;
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Config source file '{$this->path}' failed to load: {$e->getMessage()}",
                0,
                $e,
            );
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidConfigurationException(
                "Config source file '{$this->path}' must return an associative array."
            );
        }

        return $values; // @phpstan-ignore return.type (top-level associativity validated above)
    }
}
