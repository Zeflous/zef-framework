<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Application layer (autowiring compile phase).
 *
 * The ONLY place where constructor reflection happens. It runs during the
 * compile pass — never at service resolution time — and emits pure data
 * (AutowireClassSpec) that is safe to cache.
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireClassSpec;
use Zef\Framework\Autowiring\AutowireParameterSpec;
use Zef\Framework\Autowiring\Inject;
use Zef\Framework\Autowiring\Target;
use Zef\Framework\Autowiring\Value;
use Zef\Framework\Exception\InvalidConfigurationException;

final class ReflectionMetadataExtractor
{
    /**
     * Extract a constructor plan for the given class.
     *
     * @param class-string $className
     */
    public function extract(string $className): AutowireClassSpec
    {
        if (!class_exists($className)) {
            throw new InvalidConfigurationException("Cannot autowire '{$className}': class does not exist.");
        }
        $ref = new \ReflectionClass($className);
        $constructor = $ref->getConstructor();
        if ($constructor === null) {
            return new AutowireClassSpec($className, false, []);
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = $this->extractParameter($className, $parameter);
        }

        return new AutowireClassSpec($className, true, $parameters);
    }

    private function extractParameter(string $owner, \ReflectionParameter $p): AutowireParameterSpec
    {
        $type = $p->getType();

        [$className, $isBuiltinScalar, $unsupported] = $this->resolveType($owner, $type);
        [$hasDefault, $default, $defaultConstant] = $this->resolveDefault($p);

        $inject = $this->attributeArg($owner, $p, Inject::class);
        $value = $this->attributeArg($owner, $p, Value::class);
        $target = $this->attributeArg($owner, $p, Target::class);

        return new AutowireParameterSpec(
            name: $p->getName(),
            position: $p->getPosition(),
            className: $className,
            isBuiltinScalar: $isBuiltinScalar,
            allowsNull: ($type === null) || $p->allowsNull(),
            isVariadic: $p->isVariadic(),
            isOptional: $p->isOptional(),
            unsupportedTypeReason: $unsupported,
            injectId: $inject,
            valueKey: $value,
            targetClass: $target,
            hasDefaultValue: $hasDefault,
            defaultValue: $default,
            defaultValueConstant: $defaultConstant,
        );
    }

    /**
     * Resolves the parameter's type declaration into the autowiring facts:
     * [class name (null for builtins/untyped), is-builtin-scalar flag,
     * unsupported-type reason].
     *
     * @return array{null|string, bool, null|string}
     */
    private function resolveType(string $owner, ?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionType) {
            // Untyped parameter: only default/null fallback applies.
            return [null, true, null];
        }
        if ($type instanceof \ReflectionNamedType) {
            return $this->resolveNamedType($owner, $type);
        }
        if ($type instanceof \ReflectionUnionType) {
            return [null, false, $this->unionReason($type)];
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return [null, false, 'intersection type is not autowireable'];
        }

        return [null, false, 'unsupported type declaration'];
    }

    /**
     * @return array{null|string, bool, null|string}
     */
    private function resolveNamedType(string $owner, \ReflectionNamedType $type): array
    {
        if ($type->isBuiltin()) {
            return [null, true, null];
        }
        $className = $type->getName();
        if ($className === 'static') {
            $className = $owner;
        }
        if (!class_exists($className) && !interface_exists($className) && !enum_exists($className)) {
            return [$className, false, "type '{$className}' does not exist"];
        }

        return [$className, false, null];
    }

    private function unionReason(\ReflectionUnionType $type): string
    {
        $names = [];
        foreach ($type->getTypes() as $t) {
            if ($t instanceof \ReflectionNamedType) {
                $names[] = $t->getName();
            }
        }

        return 'union type (' . implode('|', $names) . ') is not autowireable';
    }

    /**
     * Resolves the default value facts: [has-default, default value,
     * default constant name] — see the fallback chain below.
     *
     * @return array{bool, mixed, null|string}
     */
    private function resolveDefault(\ReflectionParameter $p): array
    {
        // Variadic parameters are always "optional" from a call perspective
        // but never carry a usable default value.
        // @infection-ignore-all LogicalAnd — ekuivalen: getDefaultValue dan
        // getDefaultValueConstantName sama-sama melempar ReflectionException
        // tanpa default; catch dalam mengembalikan hasDefault=false
        if (!$p->isDefaultValueAvailable() || $p->isVariadic()) {
            return [false, null, null];
        }

        try {
            return [true, $p->getDefaultValue(), null];
        } catch (\ReflectionException) {
            // e.g. `new`-expression initializers: fall back to the
            // constant name so code generation can reference it.
            return $this->defaultConstantOrNull($p);
        }
    }

    /**
     * @return array{bool, null, null|string}
     */
    private function defaultConstantOrNull(\ReflectionParameter $p): array
    {
        try {
            $defaultConstant = $p->getDefaultValueConstantName();

            return [$defaultConstant !== null, null, $defaultConstant];
        } catch (\ReflectionException) {
            return [false, null, null];
        }
    }

    private function attributeArg(string $owner, \ReflectionParameter $p, string $attributeClass): ?string
    {
        $attributes = $p->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) > 1) {
            throw new InvalidConfigurationException(
                "Cannot autowire {$owner}::\${$p->getName()}: duplicate '{$attributeClass}' attributes.",
            );
        }

        try {
            $instance = $attributes[0]->newInstance();
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Cannot autowire {$owner}::\${$p->getName()}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        // @infection-ignore-all Coalesce — ekuivalen: Inject/Value/Target hanya
        // punya satu properti; ?? menekan baca properti tak terdefinisi sehingga
        // semua urutan berimpit
        return $instance->id ?? $instance->key ?? $instance->class;
    }
}
