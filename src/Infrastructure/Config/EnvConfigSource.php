<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

/**
 * Environment overlay source.
 *
 * Mapping (prefix defaults to `ZEF_`): everything after the prefix is the
 * key body; a double underscore becomes the dot separator and the whole body
 * is lowercased — `ZEF_DATABASE__HOST` becomes `database.host`,
 * `ZEF_DATABASE__POOL__MAX_SIZE` becomes `database.pool.max_size` while
 * single underscores survive as-is (`database.pool_size`).
 *
 * Environment values are always raw strings; type conversion happens at the
 * typed accessors with the documented grammar. Variables whose body does not
 * match `^[A-Z0-9_]+$` are ignored, and enumeration is sorted for a stable
 * merge order.
 *
 * A variable that IS set but carries the empty string is still included:
 * non-string schema keys then fail startup with the dedicated empty-string
 * hint — drop the variable entirely instead of leaving it empty.
 */
final readonly class EnvConfigSource implements ConfigSourceInterface
{
    private const string BODY_PATTERN = '/^[A-Z0-9_]+$/';

    public function __construct(private string $prefix = 'ZEF_')
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException("Invalid environment prefix '{$prefix}'.");
        }
    }

    #[\Override]
    public function name(): string
    {
        return 'env:' . $this->prefix;
    }

    #[\Override]
    public function load(): array
    {
        $values = [];
        $prefixLength = strlen($this->prefix);
        foreach ($this->envCandidates() as $fullKey => $raw) {
            $dotted = $this->dottedKeyFor((string) $fullKey, $prefixLength);
            if ($dotted === null) {
                continue;
            }
            if (!is_string($raw)) {
                if (!is_scalar($raw)) {
                    continue;
                }
                $raw = (string) $raw;
            }
            $values[$dotted] = $raw;
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * Union of the raw process environment and $_ENV, with $_ENV entries
     * (set by the SAPI or directly in tests) taking precedence. $_ENV is
     * this adapter's deliberate superglobal bridge — it IS the source it
     * adapts, so direct access here is by design.
     *
     * @return array<string, mixed>
     */
    private function envCandidates(): array
    {
        $candidates = getenv();
        if (!is_array($candidates)) {
            $candidates = [];
        }
        foreach ($_ENV as $envKey => $envValue) {
            if (is_string($envKey)) {
                $candidates[$envKey] = $envValue;
            }
        }

        return $candidates;
    }

    /**
     * Dotted config key for one environment variable name, or null when the
     * variable is not prefixed / does not match the body grammar / would
     * produce segments outside the schema key grammar.
     */
    private function dottedKeyFor(string $fullKey, int $prefixLength): ?string
    {
        $body = str_starts_with($fullKey, $this->prefix) ? substr($fullKey, $prefixLength) : '';
        if ($body === '' || preg_match(self::BODY_PATTERN, $body) !== 1) {
            return null;
        }
        $dotted = strtolower(str_replace('__', '.', $body));
        // Segments must match the schema key grammar (alphanumeric first
        // character) so env-produced keys can never bypass strict mode.
        if (preg_match('/^[a-z0-9][a-z0-9_-]*(\.[a-z0-9][a-z0-9_-]*)*$/', $dotted) !== 1) {
            return null;
        }

        return $dotted;
    }
}
