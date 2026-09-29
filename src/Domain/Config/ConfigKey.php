<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

/**
 * One schema entry: the declared shape of a dotted configuration key.
 *
 * Construction validates the declaration itself (key grammar, constraint
 * applicability, default/type agreement) so an invalid schema can never be
 * the reason a production boot misbehaves.
 */
final readonly class ConfigKey
{
    /**
     * Dotted key grammar: segments of `[A-Za-z0-9_-]` joined by single dots,
     * 256 characters maximum.
     */
    private const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(\.[A-Za-z0-9][A-Za-z0-9_-]*)*$/';

    public function __construct(
        public string $key,
        public ConfigValueType $type,
        public bool $required = false,
        public mixed $default = null,
        public ?string $enumClass = null,
        public float|int|null $min = null,
        public float|int|null $max = null,
        public ?string $pattern = null,
        public ?string $description = null,
    ) {
        $this->assertKeyGrammar($key);
        $this->assertEnumDeclaration($key, $type, $enumClass);
        $this->assertRangeDeclaration($key, $type, $min, $max);
        $this->assertPatternDeclaration($key, $type, $pattern);
        $this->assertDefaultDeclaration($key, $required, $default, $type, $enumClass, $pattern);
    }

    private function assertKeyGrammar(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > 256) {
            throw new \InvalidArgumentException("Invalid configuration key '{$key}'.");
        }
    }

    private function assertEnumDeclaration(string $key, ConfigValueType $type, ?string $enumClass): void
    {
        if ($type !== ConfigValueType::Enum) {
            if ($enumClass !== null) {
                throw new \InvalidArgumentException(
                    "Config key '{$key}' must be of type enum to declare an enum class."
                );
            }

            return;
        }
        $isValidEnum = $enumClass !== null && enum_exists($enumClass) && is_subclass_of($enumClass, \BackedEnum::class);
        if (!$isValidEnum) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' of type enum requires a backed enum class."
            );
        }
    }

    private function assertRangeDeclaration(
        string $key,
        ConfigValueType $type,
        float|int|null $min,
        float|int|null $max,
    ): void {
        $hasBounds = $min !== null || $max !== null;
        $isNumericType = $type === ConfigValueType::Int || $type === ConfigValueType::Float;
        if ($hasBounds && !$isNumericType) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' cannot declare min/max constraints for type '{$type->value}'."
            );
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException("Config key '{$key}' declares min greater than max.");
        }
    }

    private function assertPatternDeclaration(string $key, ConfigValueType $type, ?string $pattern): void
    {
        if ($pattern !== null && $type !== ConfigValueType::String) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' cannot declare a pattern for type '{$type->value}'."
            );
        }
        if ($pattern !== null && !$this->isCompilingPattern($pattern)) {
            throw new \InvalidArgumentException("Config key '{$key}' declares a pattern that does not compile.");
        }
    }

    private function assertDefaultDeclaration(
        string $key,
        bool $required,
        mixed $default,
        ConfigValueType $type,
        ?string $enumClass,
        ?string $pattern,
    ): void {
        $hasDefault = $default !== null;
        if ($required && $hasDefault) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' is required and cannot declare a default."
            );
        }
        if (!$required && $hasDefault && !$type->accepts($default, $enumClass)) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares a default that does not match its type: "
                . ConfigValueType::describe($default) . '.'
            );
        }
        if (!$required && $hasDefault && $this->defaultViolatesConstraints($default, $pattern)) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares a default outside its declared constraints."
            );
        }
    }

    private function defaultViolatesConstraints(mixed $default, ?string $pattern): bool
    {
        if ($this->isOutOfBounds($default)) {
            return true;
        }

        return $pattern !== null && is_string($default) && preg_match($pattern, $default) !== 1;
    }

    /**
     * A pattern compiles when preg_match() on it does not return false.
     * The scoped error handler keeps the compile diagnostics from leaking
     * as engine warnings; the verdict comes from the return value.
     */
    private function isCompilingPattern(string $pattern): bool
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $compiles = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $compiles;
    }

    private function isOutOfBounds(mixed $value): bool
    {
        $numeric = is_int($value) || is_float($value);
        if (!$numeric) {
            return false;
        }
        $belowMin = $this->min !== null && $value < $this->min;
        $aboveMax = $this->max !== null && $value > $this->max;

        return $belowMin || $aboveMax;
    }
}
