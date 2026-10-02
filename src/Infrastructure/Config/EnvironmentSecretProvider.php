<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

/**
 * Environment-backed legacy secrets port: reads a secret from getenv().
 *
 * @deprecated since v2.36.0 (issue #355 C-6) — the legacy
 *             {@see SecretProviderInterface} port is superseded by
 *             {@see SecretsProviderInterface}. Wrap this provider in
 *             {@see SecretProviderAdapter} to use it
 *             through the canonical port (ConfigLoader, chains, resilience);
 *             removal of the legacy port is scheduled for v3.0.
 */
final class EnvironmentSecretProvider implements SecretProviderInterface
{
    #[\Override]
    public function get(string $name): ?SecretValue
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid secret name.');
        }
        $value = getenv($name);

        return $value === false ? null : new SecretValue($value);
    }
}
