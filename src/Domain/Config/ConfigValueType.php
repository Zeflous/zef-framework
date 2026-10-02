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
 * Canonical configuration value types — the single source of truth for what
 * a raw value must look like, shared by the schema validator and the typed
 * accessors of {@see Config}.
 *
 * Acceptance grammar (strict, deterministic):
 * - String: PHP `is_string` only — strings are never coerced.
 * - Int: `is_int` OR a full numeric string `^-?\d+$` (environment values are
 *   always strings, so canonical numeric strings are accepted; partial
 *   numbers like `"12abc"` are rejected).
 * - Float: `int|float` OR a full decimal string `^-?\d+(\.\d+)?$`.
 * - Bool: `is_bool` OR one of the canonical word strings below
 *   (case-insensitive).
 * - Enum: an `int|string` backing value of the declared backed-enum class.
 * - Array: `is_array` only.
 */
enum ConfigValueType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Enum = 'enum';
    case Array = 'array';

    /** Canonical truthy words accepted for boolean values. */
    public const BOOL_TRUE = ['true', '1', 'on', 'yes'];

    /** Canonical falsy words accepted for boolean values. */
    public const BOOL_FALSE = ['false', '0', 'off', 'no'];

    public function accepts(mixed $raw, ?string $enumClass = null): bool
    {
        return match ($this) {
            self::String => is_string($raw),
            self::Int => is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1),
            self::Float => is_int($raw) || is_float($raw)
                || (is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1),
            self::Bool => is_bool($raw) || (is_string($raw)
                && in_array(strtolower($raw), [...self::BOOL_TRUE, ...self::BOOL_FALSE], true)),
            self::Enum => is_string($enumClass) && enum_exists($enumClass)
                && self::enumAccepts($enumClass, $raw),
            self::Array => is_array($raw),
        };
    }

    /**
     * Canonicalises an accepted raw value. Callers MUST check accepts() first;
     * behaviour for unaccepted values is a guarded fast failure.
     */
    public function coerce(mixed $raw, ?string $enumClass = null): mixed
    {
        return match ($this) {
            self::Int => is_int($raw) ? $raw : self::intFromNumericString($raw),
            self::Float => is_float($raw) ? $raw : self::floatFromNumeric($raw),
            self::Bool => is_bool($raw) ? $raw : self::boolFromWord($raw),
            self::Enum => self::enumFrom($enumClass, $raw),
            default => $raw,
        };
    }

    /**
     * Deterministic extra guidance appended to type-mismatch messages when a
     * rejected raw value is the empty string — the classic "environment
     * variable is set but empty" mistake. Only string keys accept `''`; every
     * other type explains how to fix the offending variable. Non-empty values
     * and the String type itself produce no hint.
     */
    public function rejectionHint(mixed $raw): string
    {
        if ($raw === '' && $this !== self::String) {
            // One glued literal: a single string would run past the 120-column limit.
            return implode('', [
                ' (empty strings only satisfy string keys; remove the empty',
                ' environment variable or set a concrete value)',
            ]);
        }

        return '';
    }

    /**
     * Human-readable rendering of a raw value for violation messages.
     * Long strings are truncated deterministically at 61 chars + `...`.
     */
    public static function describe(mixed $raw, bool $secret = false): string
    {
        return match (true) {
            $raw === null => 'null',
            is_bool($raw) => $raw ? 'bool(true)' : 'bool(false)',
            is_int($raw) => 'int(' . $raw . ')',
            is_float($raw) => 'float(' . var_export($raw, true) . ')',
            is_string($raw) => $secret
                // Secret-resolved values are masked: the raw material would
                // otherwise be echoed verbatim into violation messages that
                // end up in logs and error trackers (issue #308).
                ? "string('******')"
                : 'string(' . self::renderString($raw) . ')',
            is_array($raw) => 'array(' . count($raw) . ')',
            is_object($raw) => 'object(' . $raw::class . ')',
            default => get_debug_type($raw),
        };
    }

    private static function enumAccepts(string $enumClass, mixed $raw): bool
    {
        assert(is_subclass_of($enumClass, \BackedEnum::class));
        if (!is_int($raw) && !is_string($raw)) {
            return false;
        }
        foreach ($enumClass::cases() as $case) {
            if ($case->value === $raw) {
                return true;
            }
        }

        return false;
    }

    private static function enumFrom(?string $enumClass, mixed $raw): \BackedEnum
    {
        assert(is_string($enumClass) && enum_exists($enumClass)
            && is_subclass_of($enumClass, \BackedEnum::class));
        assert(is_int($raw) || is_string($raw));

        return $enumClass::from($raw);
    }

    private static function intFromNumericString(mixed $raw): int
    {
        assert(is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1);

        return (int) $raw;
    }

    private static function floatFromNumeric(mixed $raw): float
    {
        assert(is_int($raw) || is_float($raw)
            || (is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1));

        return (float) $raw;
    }

    private static function boolFromWord(mixed $raw): bool
    {
        assert(is_string($raw));

        return in_array(strtolower($raw), self::BOOL_TRUE, true);
    }

    private static function renderString(string $raw): string
    {
        $visible = strlen($raw) > 64 ? substr($raw, 0, 61) . '...' : $raw;

        return "'" . addcslashes($visible, "'\\") . "'";
    }
}
