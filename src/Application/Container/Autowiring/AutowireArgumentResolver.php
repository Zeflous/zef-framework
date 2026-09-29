<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (autowiring compile pass).
 *
 * Per-parameter planning collaborator of AutowireClassPlanner: resolves one
 * constructor parameter into dependency entries plus the generated argument
 * plan (variadic collections, #[Inject] / #[Target] references, layered
 * class bindings, and scalar #[Value] / default fallbacks). Extracted from
 * AutowireCompilerPass in the sonar-zero campaign (behavior-preserving split).
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireParameterSpec;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\InvalidConfigurationException;

final class AutowireArgumentResolver
{
    public function __construct(
        private readonly AutowireClassPlanner $planner,
        private readonly AutowireValueServices $valueServices,
        private readonly AutowireImplementationCollector $implementations,
    ) {}

    /**
     * Resolve one constructor parameter into dependency entries + argument plan.
     *
     * @param list<string>                                 $deps       out: ordered dependency IDs
     * @param list<array{0:'dep'|'literal',1:int|string}>  $args       out: argument plan
     * @param list<string>                                 $classStack
     */
    public function planParameter(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        array &$deps,
        array &$args,
        array $classStack,
    ): void {
        if ($p->unsupportedTypeReason !== null) {
            $this->assertSupportedType($ownerClass, $p);
        } elseif ($p->isVariadic) {
            $this->planVariadic($container, $ownerClass, $p, $deps, $args);
        } elseif ($p->injectId !== null) {
            // ---- explicit service reference --------------------------------
            $serviceId = $this->planner->ensureService($container, $p->injectId, $classStack);
            $args[] = ['dep', $this->appendDependency($deps, $serviceId)];
        } elseif ($p->targetClass !== null) {
            // ---- explicit concrete target for interface/abstract types -----
            $serviceId = $this->autowireTarget($container, $ownerClass, $p, $p->targetClass, $classStack);
            $args[] = ['dep', $this->appendDependency($deps, $serviceId)];
        } elseif ($p->className !== null) {
            // ---- class/interface/enum type: layered binding resolution -----
            $args[] = $this->resolveClassArgument($container, $ownerClass, $p, $p->className, $classStack, $deps);
        } else {
            // ---- scalar/primitive parameter --------------------------------
            $args[] = $this->resolveScalarArgument($container, $ownerClass, $p, $deps);
        }
    }

    /** Unsupported type: optional parameters fall back to the declared default. */
    private function assertSupportedType(string $ownerClass, AutowireParameterSpec $p): void
    {
        if ($p->isOptional) {
            return; // rely on the declared default
        }

        $message = "Cannot autowire {$ownerClass}::\${$p->name}: {$p->unsupportedTypeReason}.";

        throw new InvalidConfigurationException($message);
    }

    /**
     * @param list<string>                                 $deps
     * @param list<array{0:'dep'|'literal',1:int|string}>  $args
     */
    private function planVariadic(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        array &$deps,
        array &$args,
    ): void {
        // ---- variadic service collection ------------------------------------
        if ($p->className !== null) {
            foreach ($this->implementations->collect($container, $p->className) as $serviceId) {
                $args[] = ['dep', $this->appendDependency($deps, $serviceId)];
            }

            return;
        }

        // ---- variadic scalar: optional #[Value] list baked as literals ------
        if ($p->valueKey !== null) {
            $this->appendVariadicValueLiterals($ownerClass, $p, $p->valueKey, $args);
        }
    }

    /**
     * @param list<array{0:'dep'|'literal',1:int|string}> $args
     */
    private function appendVariadicValueLiterals(
        string $ownerClass,
        AutowireParameterSpec $p,
        string $valueKey,
        array &$args,
    ): void {
        $raw = $this->valueServices->configLookup($ownerClass, $p, $valueKey);
        if (!is_array($raw)) {
            $message = "Cannot autowire {$ownerClass}::\${$p->name}: config '{$valueKey}' must be an array ";
            $message .= 'for a variadic parameter.';

            throw new InvalidConfigurationException($message);
        }
        foreach ($raw as $element) {
            $args[] = ['literal', $this->valueServices->renderLiteral($element, "config '{$valueKey}' element")];
        }
    }

    /** Resolves a #[Target]-pinned concrete class into its service ID. */
    private function autowireTarget(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        string $targetClass,
        array $classStack,
    ): string {
        if (!new \ReflectionClass($targetClass)->isInstantiable()) {
            $message = "Cannot autowire {$ownerClass}::\${$p->name}: #[Target({$targetClass}::class)] ";
            $message .= 'is not an instantiable class.';

            throw new InvalidConfigurationException($message);
        }

        return $this->planner->autowireClass($container, $targetClass, $classStack);
    }

    /**
     * Layered binding resolution for a class/interface/enum-typed parameter.
     *
     * @param list<string> $deps
     *
     * @return array{0:'dep'|'literal',1:int|string}
     */
    private function resolveClassArgument(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        string $type,
        array $classStack,
        array &$deps,
    ): array {
        if ($container->has($type)) {
            // Exact FQCN factory or a registered alias for the FQCN.
            return ['dep', $this->appendDependency($deps, $type)];
        }
        if (class_exists($type) && new \ReflectionClass($type)->isInstantiable()) {
            // Concrete class: autowire it under its own FQCN id.
            $serviceId = $this->planner->autowireClass($container, $type, $classStack);

            return ['dep', $this->appendDependency($deps, $serviceId)];
        }

        return $this->unboundClassArgument($ownerClass, $p, $type);
    }

    /**
     * Unbound class-typed parameter: the constructor default, a baked null,
     * or a descriptive failure.
     *
     * @return array{0:'dep'|'literal',1:int|string}
     */
    private function unboundClassArgument(string $ownerClass, AutowireParameterSpec $p, string $type): array
    {
        if ($p->hasDefaultValue) {
            // Unbound optional dependency: fall back to the constructor default.
            return ['literal', $this->valueServices->renderDefault($ownerClass, $p)];
        }
        if ($p->allowsNull && $p->isOptional) {
            return ['literal', 'null'];
        }

        $message = "Cannot autowire {$ownerClass}::\${$p->name}: no binding for '{$type}'. ";
        $message .= 'Register the service, add an alias with that FQCN, use #[Target] / #[Inject], ';
        $message .= 'or give the parameter a default value.';

        throw new InvalidConfigurationException($message);
    }

    /**
     * @param list<string> $deps
     *
     * @return array{0:'dep'|'literal',1:int|string}
     */
    private function resolveScalarArgument(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        array &$deps,
    ): array {
        $valueKey = $p->valueKey;
        if ($valueKey !== null) {
            $this->valueServices->configLookup($ownerClass, $p, $valueKey); // validates presence
            $valueId = $this->valueServices->ensureValueService($container, $valueKey);

            return ['dep', $this->appendDependency($deps, $valueId)];
        }

        return $this->unboundScalarArgument($ownerClass, $p);
    }

    /**
     * @return array{0:'dep'|'literal',1:int|string}
     */
    private function unboundScalarArgument(string $ownerClass, AutowireParameterSpec $p): array
    {
        if ($p->hasDefaultValue) {
            return ['literal', $this->valueServices->renderDefault($ownerClass, $p)];
        }
        if ($p->allowsNull) {
            // Nullable without default (e.g. `mixed $x`): bake a safe null.
            return ['literal', 'null'];
        }

        $message = "Cannot autowire {$ownerClass}::\${$p->name}: required scalar parameter without ";
        $message .= '#[Value] and without a default value.';

        throw new InvalidConfigurationException($message);
    }

    /** @param list<string> $deps */
    private function appendDependency(array &$deps, string $id): int
    {
        $index = array_search($id, $deps, true);
        if ($index !== false) {
            return (int) $index;
        }
        $deps[] = $id;

        return count($deps) - 1;
    }
}
