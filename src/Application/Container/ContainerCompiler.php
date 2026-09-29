<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * Compiles the mutable registration registry into an immutable runtime plan.
 *
 * @internal
 */
final readonly class ContainerCompiler
{
    public function __construct(private DependencyGraphValidator $graphValidator) {}

    public function compile(
        ServiceRegistry $registry,
        // ekuivalen: <= 0 berarti unlimited (validator gerbang > 0); -1 identik dengan 0
        // @infection-ignore-all DecrementInteger
        int $maxCrossModuleRefs = 0,
    ): CompiledContainerPlan {
        $definitions = $registry->definitions();
        $aliases = $registry->aliases();
        $deps = $registry->depsOf();
        $moduleOf = $registry->moduleOf();
        $lifetimes = $registry->lifetimeOf();

        $this->graphValidator->validate(
            $registry->factories(),
            $aliases,
            $deps,
            $moduleOf,
            $lifetimes,
            $maxCrossModuleRefs,
        );

        $canonicalIds = [];
        foreach (array_keys($definitions) as $id) {
            $canonicalIds[$id] = $id;
        }
        foreach (array_keys($aliases) as $alias) {
            $canonical = $this->graphValidator->resolveAlias($alias, $aliases);
            if (!isset($definitions[$canonical])) {
                $module = $moduleOf[$alias] ?? null;

                throw new ServiceNotFoundException($alias, is_string($module) ? $module : null);
            }
            $canonicalIds[$alias] = $canonical;
        }

        $compiledDependencies = [];
        foreach ($definitions as $id => $definition) {
            $compiledDependencies[$id] = [];
            foreach ($definition->dependencies as $dependency) {
                if (!is_string($dependency)) {
                    throw new \LogicException('Compiled service dependency must be a string.');
                }
                // DependencyGraphValidator::validate() already guarantees
                // every canonical dependency has a factory, so the lookup
                // below is total.
                $compiledDependencies[$id][] = $canonicalIds[$dependency];
            }
        }

        return new CompiledContainerPlan(
            definitions: $definitions,
            canonicalIds: $canonicalIds,
            dependencies: $compiledDependencies,
        );
    }
}

// @internal
