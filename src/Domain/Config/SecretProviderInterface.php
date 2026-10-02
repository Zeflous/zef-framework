<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

/**
 * Legacy secrets port: resolves a secret name to a {@see SecretValue}.
 *
 * @deprecated since v2.36.0 (issue #355 C-6) — superseded by
 *             {@see SecretsProviderInterface}, the canonical port consumed by
 *             ConfigLoader, ResilientSecretsProvider and the first-hit
 *             ChainSecretsProvider. The two ports are NOT interchangeable:
 *             different value types (`?SecretValue` vs `?string`) and
 *             different key grammars (UPPERCASE env names vs lowercase dotted
 *             keys), which made provider implementations accidentally
 *             incompatible. Bridge legacy implementations into the canonical
 *             port with {@see SecretProviderAdapter}; scheduled for removal
 *             in v3.0.
 */
interface SecretProviderInterface
{
    public function get(string $name): ?SecretValue;
}
