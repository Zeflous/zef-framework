<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;

final class ModuleRegistry
{
    /**
     * @var array<string,ModuleInterface>
     */
    private array $modules = [];

    /**
     * @var list<ModuleInterface>
     */
    private array $order = [];
    private bool $registered = false;
    private bool $booted = false;
    private bool $started = false;

    public function add(ModuleInterface $module): void
    {
        if ($this->registered) {
            throw new \LogicException('Cannot add modules after registration has started.');
        }
        $name = $module->getName();
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidConfigurationException("Invalid module name '{$name}'.");
        }
        $key = strtolower($name);
        if (isset($this->modules[$key])) {
            throw new InvalidConfigurationException("Duplicate module '{$name}'.");
        }
        if (strtolower($module->getDefinition()->name) !== $key) {
            throw new InvalidConfigurationException(
                "Module definition name '{$module->getDefinition()->name}' does not match '{$name}'."
            );
        }
        $this->modules[$key] = $module;
    }

    public function addProvider(ConfigProviderInterface $provider): void
    {
        $this->add(new ConfigProviderModule($provider));
    }

    /** @return list<ModuleInterface> */
    public function modules(): array
    {
        return array_values($this->modules);
    }

    /** @return list<ConfigProviderInterface> */
    public function providers(): array
    {
        $providers = [];
        foreach ($this->modules as $module) {
            if ($module instanceof ConfigProviderModule) {
                $providers[] = $module->provider();
            } else {
                $providers[] = new ModuleConfigProvider($module);
            }
        }

        return $providers;
    }

    /** @return list<ModuleInterface> */
    public function resolveOrder(): array
    {
        $state = [];
        $ordered = [];
        $visit = function (string $key) use (&$visit, &$state, &$ordered): void {
            $status = $state[$key] ?? 0;
            if ($status === 1) {
                throw new InvalidConfigurationException("Circular module dependency detected at '{$key}'.");
            }
            if ($status === 2) {
                return;
            }
            $module = $this->modules[$key]
                ?? throw new InvalidConfigurationException("Missing module dependency '{$key}'.");
            $state[$key] = 1;
            foreach ($module->getDefinition()->dependencies as $dependency) {
                $visit(strtolower($dependency));
            }
            $state[$key] = 2;
            $ordered[] = $module;
        };
        foreach (array_keys($this->modules) as $key) {
            $visit($key);
        }

        return $ordered;
    }

    public function registerAll(ModuleRegistrar $registrar, ContainerInterface $container): void
    {
        if ($this->registered) {
            return;
        }
        $this->order = $this->resolveOrder();
        foreach ($this->order as $module) {
            $context = new ModuleContext($module->getDefinition(), $container);
            $registrar->registerModule($module->getName(), $module->getDefinition());
            $module->register($context);
        }
        $this->registered = true;
    }

    public function bootAll(ContainerInterface $container): void
    {
        if (!$this->registered) {
            throw new \LogicException('Modules must be registered before booting.');
        }
        if ($this->booted) {
            return;
        }
        foreach ($this->order as $module) {
            $module->boot(new ModuleContext($module->getDefinition(), $container));
        }
        $this->booted = true;
    }

    public function startAll(ContainerInterface $container): void
    {
        if (!$this->booted) {
            throw new \LogicException('Modules must be booted before starting.');
        }
        if ($this->started) {
            return;
        }
        foreach ($this->order as $module) {
            $module->start(new ModuleContext($module->getDefinition(), $container));
        }
        $this->started = true;
    }

    public function shutdownAll(ContainerInterface $container): void
    {
        if (!$this->registered) {
            return;
        }
        foreach (array_reverse($this->order) as $module) {
            try {
                $module->shutdown(new ModuleContext($module->getDefinition(), $container));
            } catch (\Throwable) {
                // Intentionally empty: module shutdown is best-effort — a
                // failing module must not prevent the remaining modules from
                // being shut down during a teardown sequence.
            }
        }
        $this->started = false;
        $this->booted = false;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
