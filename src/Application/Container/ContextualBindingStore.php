<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Contextual-binding storage extracted from Container (php:S2042): consumer
 * validation, duplicate guard, and the synthetic-alias rewrite of consumer
 * definitions. Byte-identical move of the Container logic — no behavioural
 * changes.
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\InvalidConfigurationException;

final class ContextualBindingStore
{
    /**
     * @var list<array{consumer:string,dep:string,target:string,via:string}>
     */
    private array $contextualBindings = [];

    public function add(
        string $consumer,
        string $dep,
        string $target,
        ServiceRegistry $registry,
        ServiceRegistrar $registrar,
    ): void {
        $definitions = $registry->definitions();
        if (!isset($definitions[$consumer])) {
            throw new InvalidConfigurationException(
                "Contextual binding: consumer '{$consumer}' is not a registered service.",
            );
        }
        $definition = $definitions[$consumer];
        if (!in_array($dep, $definition->dependencies, true)) {
            throw new InvalidConfigurationException(
                "Contextual binding: consumer '{$consumer}' does not declare dependency '{$dep}'.",
            );
        }
        if ($target === '') {
            throw new InvalidConfigurationException('Contextual binding target must be a non-empty service ID.');
        }
        // @infection-ignore-all Foreach_ — ekuivalen: binding pertama
        // menulis-ulang deps konsumen (dep -> @contextual:alias) sehingga
        // guard duplikat tak terjangkau
        foreach ($this->contextualBindings as $existing) {
            if ($existing['consumer'] === $consumer && $existing['dep'] === $dep) {
                throw new InvalidConfigurationException(
                    "Contextual binding: consumer '{$consumer}' already binds '{$dep}'.",
                );
            }
        }
        $via = '@contextual:' . $consumer . '|' . $dep;
        // Synthetic alias (collision-checked by the registrar) plus a rewritten
        // consumer definition whose dependency graph now flows through $via —
        // so graph validation, cycles and cross-module budgets still apply.
        $registrar->alias($via, $target, null);
        $newDeps = array_map(
            static fn (string $d): string => $d === $dep ? $via : $d,
            $definition->dependencies,
        );
        $registry->addDefinition(new ServiceDefinition(
            $definition->id,
            $definition->factory,
            $newDeps,
            $definition->module,
            $definition->lifetime,
            $definition->shared,
            $definition->lazy,
            $definition->tags,
        ));
        $this->contextualBindings[] = [
            'consumer' => $consumer, 'dep' => $dep, 'target' => $target, 'via' => $via,
        ];
    }

    /** @return list<array{consumer:string,dep:string,target:string,via:string}> */
    public function all(): array
    {
        return $this->contextualBindings;
    }
}
