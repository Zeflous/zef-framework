<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring value objects).

namespace Zef\Framework\Autowiring;

/**
 * Reflection-extracted plan for a single constructor parameter.
 *
 * Pure data — produced by ReflectionMetadataExtractor (compile phase only),
 * consumed by AutowireCompilerPass. No Reflection objects are retained so a
 * spec can be cached/serialised safely.
 */
final readonly class AutowireParameterSpec
{
    /**
     * @param null|string $className              class/interface/enum type name, null for builtins
     * @param bool        $isBuiltinScalar        int/string/float/bool/array/iterable/mixed/callable
     * @param null|string $unsupportedTypeReason  union/intersection/other non-autowireable type
     * @param null|string $injectId               value of #[Inject], if present
     * @param null|string $valueKey               value of #[Value], if present
     * @param null|string $targetClass            value of #[Target], if present
     * @param bool        $hasDefaultValue        default value available for baking
     * @param mixed       $defaultValue           baked default (exportable scalars/arrays/null only)
     * @param null|string $defaultValueConstant   constant-name fallback when value cannot be var_export'ed
     */
    public function __construct(
        public string $name,
        public int $position,
        public ?string $className,
        public bool $isBuiltinScalar,
        public bool $allowsNull,
        public bool $isVariadic,
        public bool $isOptional,
        public ?string $unsupportedTypeReason,
        public ?string $injectId,
        public ?string $valueKey,
        public ?string $targetClass,
        public bool $hasDefaultValue,
        public mixed $defaultValue = null,
        public ?string $defaultValueConstant = null,
    ) {
        if ($this->injectId !== null && $this->valueKey !== null) {
            throw new \InvalidArgumentException("Parameter '\${$this->name}' cannot combine #[Inject] and #[Value].");
        }
        if ($this->injectId !== null && $this->isVariadic) {
            throw new \InvalidArgumentException(
                "Parameter '\${$this->name}': #[Inject] on a variadic parameter is not supported.",
            );
        }
    }

    public function hasTargetedBinding(): bool
    {
        return $this->injectId !== null || $this->targetClass !== null || $this->valueKey !== null;
    }
}
