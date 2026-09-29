<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

final class ConfigAggregator
{
    /**
     * @var list<ConfigProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string,mixed>
     */
    private array $merged = [];
    private bool $mergedReady = false;
    private bool $merging = false;

    public function addProvider(ConfigProviderInterface $provider): void
    {
        if ($this->mergedReady) {
            throw new \LogicException('Cannot add provider after configuration has been merged.');
        }
        $module = $provider->getModuleName();
        if ($module === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $module) !== 1) {
            throw new InvalidConfigurationException("Invalid module name '{$module}'.");
        }
        $moduleKey = strtolower($module);
        foreach ($this->providers as $existing) {
            if (strtolower($existing->getModuleName()) === $moduleKey) {
                throw new InvalidConfigurationException(
                    "Duplicate module/provider '{$module}' (case-insensitive collision)."
                );
            }
        }
        $this->providers[] = $provider;
    }

    /** @return array<string,mixed> */
    public function merge(): array
    {
        if ($this->mergedReady) {
            return $this->merged;
        }
        // Re-entrancy guard: a provider calling get()/all()/merge() from
        // getConfig() re-entered merge() with $merged still empty and
        // recursed until memory exhaustion (OOM fatal, uncatchable).
        if ($this->merging) {
            throw new \LogicException(
                'Reentrant configuration read: a provider called ConfigAggregator::get()/all()/merge() '
                . 'while configuration is being merged.'
            );
        }
        $this->merging = true;

        try {
            $this->merged = [];
            foreach ($this->providers as $provider) {
                $cfg = $provider->getConfig();
                if (!is_array($cfg)) {
                    throw new InvalidConfigurationException(
                        "Config provider '{$provider->getModuleName()}' must return an array."
                    );
                }
                $this->merged[strtolower($provider->getModuleName())] = $cfg;
            }
        } finally {
            $this->merging = false;
        }
        $this->mergedReady = true;

        return $this->merged;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Self-sufficient: an explicit merge() call must not be required
        // before reads, otherwise get() silently returns defaults.
        $cur = $this->merge();
        foreach (explode('.', $key) as $s) {
            if (!is_array($cur) || !array_key_exists($s, $cur)) {
                return $default;
            }
            $cur = $cur[$s];
        }

        return $cur;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->merge();
    }

    /** @return list<ConfigProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** @return array<string,ModuleDefinition> */
    public function moduleDefinitions(): array
    {
        $definitions = [];
        foreach ($this->merge() as $name => $config) {
            $definitions[$name] = ModuleDefinition::fromArray($name, $config);
        }

        return $definitions;
    }
}
