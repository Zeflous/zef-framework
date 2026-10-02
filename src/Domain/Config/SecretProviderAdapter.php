<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). SecretProviderAdapter added by the C-6 hardening
 * (issue #355): bridges the deprecated legacy secrets port into the
 * canonical one.
 */

namespace Zef\Framework\Config;

/**
 * Bridges the deprecated legacy port ({@see SecretProviderInterface},
 * `get(): ?SecretValue`, UPPERCASE names) into the canonical secrets port
 * ({@see SecretsProviderInterface}, `get(): ?string`, lowercase dotted keys)
 * so legacy providers — e.g. {@see EnvironmentSecretProvider}
 * — become usable by ConfigLoader, ResilientSecretsProvider and
 * ChainSecretsProvider (issue #355 C-6).
 *
 * Key mapping (the two grammars differ by design): the canonical key is
 * upper-cased and `.` / `-` become `_` — `db.pass` and `db-pass` both reach
 * a legacy provider as `DB_PASS`. A canonical key that cannot map (a leading
 * digit such as `2fa`) is rejected up front with a clear message instead of
 * surfacing as a cryptic provider-side grammar failure.
 *
 * The port's secrecy contract carries over: values cross the bridge as
 * plain strings exactly once (via {@see SecretValue::reveal()}), never logged;
 * a null (unknown name) passes through untouched.
 */
final readonly class SecretProviderAdapter implements SecretsProviderInterface
{
    public function __construct(private SecretProviderInterface $inner) {}

    #[\Override]
    public function get(string $key): ?string
    {
        $legacyName = strtoupper(strtr($key, ['.' => '_', '-' => '_']));
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $legacyName) !== 1) {
            $grammar = 'the legacy port needs ^[A-Z][A-Z0-9_]{0,127}$';

            throw new \InvalidArgumentException(
                "Secret key '{$key}' cannot be mapped onto the legacy secrets port ('{$legacyName}' — {$grammar}).",
            );
        }
        $value = $this->inner->get($legacyName);

        return $value instanceof SecretValue ? $value->reveal() : null;
    }
}
