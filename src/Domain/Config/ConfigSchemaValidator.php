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
 * Validates a merged configuration tree against a {@see ConfigSchema}.
 *
 * The validator COLLECTS every violation instead of failing at the first
 * one, so a broken deployment reports all of its problems in a single
 * fail-fast startup exception. Violation order is deterministic:
 * declaration order of the schema keys first, then unknown-key violations
 * in sorted path order (strict mode).
 */
final class ConfigSchemaValidator
{
    /** @var list<string> dotted paths resolved from `%secret:%` references */
    private array $secretPaths = [];

    /**
     * @param array<array-key,mixed> $values
     * @param list<string> $secretPaths paths resolved from `%secret:%`
     *                                  references; their values are masked in
     *                                  violation messages instead of echoed
     *                                  verbatim (issue #308)
     *
     * @return list<ConfigViolation>
     */
    public function validate(array $values, ConfigSchema $schema, array $secretPaths = []): array
    {
        $this->secretPaths = $secretPaths;
        $violations = [];
        foreach ($schema->keys as $key) {
            $violation = $this->checkKey($values, $key);
            if ($violation instanceof ConfigViolation) {
                $violations[] = $violation;
            }
        }
        if (!$schema->allowUnknownKeys) {
            foreach (DottedPaths::leafPaths($values) as $path) {
                if (!$schema->key($path) instanceof ConfigKey) {
                    $violations[] = new ConfigViolation($path, 'unknown configuration key');
                }
            }
        }

        return $violations;
    }

    /**
     * Returns a copy of the values with every optional key's default applied.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     */
    public function applyDefaults(array $values, ConfigSchema $schema): array
    {
        foreach ($schema->keys as $key) {
            if ($key->default === null) {
                continue;
            }
            if (!DottedPaths::has($values, $key->key)) {
                $values = DottedPaths::set($values, $key->key, $key->default);
            }
        }

        return $values;
    }

    /**
     * @param array<array-key,mixed> $values
     */
    private function checkKey(array $values, ConfigKey $key): ?ConfigViolation
    {
        if (!DottedPaths::has($values, $key->key)) {
            return $key->required ? new ConfigViolation($key->key, 'is required') : null;
        }
        $raw = DottedPaths::valueAt($values, $key->key);
        if (!$key->type->accepts($raw, $key->enumClass)) {
            return new ConfigViolation(
                $key->key,
                "expects {$key->type->value}, got "
                    . ConfigValueType::describe($raw, in_array($key->key, $this->secretPaths, true))
                    . $key->type->rejectionHint($raw),
            );
        }
        $coerced = $key->type->coerce($raw, $key->enumClass);

        return $this->checkConstraints($raw, $coerced, $key);
    }

    private function checkConstraints(mixed $raw, mixed $coerced, ConfigKey $key): ?ConfigViolation
    {
        return $this->checkMin($coerced, $key)
            ?? $this->checkMax($coerced, $key)
            ?? $this->checkPattern($raw, $key);
    }

    private function checkMin(mixed $coerced, ConfigKey $key): ?ConfigViolation
    {
        if ($key->min !== null && (is_int($coerced) || is_float($coerced)) && $coerced < $key->min) {
            return new ConfigViolation($key->key, 'must be >= ' . $this->renderBound($key->min));
        }

        return null;
    }

    private function checkMax(mixed $coerced, ConfigKey $key): ?ConfigViolation
    {
        if ($key->max !== null && (is_int($coerced) || is_float($coerced)) && $coerced > $key->max) {
            return new ConfigViolation($key->key, 'must be <= ' . $this->renderBound($key->max));
        }

        return null;
    }

    private function checkPattern(mixed $raw, ConfigKey $key): ?ConfigViolation
    {
        if ($key->pattern !== null && is_string($raw) && preg_match($key->pattern, $raw) !== 1) {
            return new ConfigViolation($key->key, 'does not match pattern');
        }

        return null;
    }

    private function renderBound(float|int $bound): string
    {
        return is_int($bound) ? (string) $bound : var_export($bound, true);
    }
}
