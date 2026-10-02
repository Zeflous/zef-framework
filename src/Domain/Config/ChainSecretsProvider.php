<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). ChainSecretsProvider added by the C-6 hardening
 * (issue #355): a first-hit composer over the canonical secrets port.
 */

namespace Zef\Framework\Config;

/**
 * First-hit composer over {@see SecretsProviderInterface}: providers are
 * consulted in construction order and the first NON-NULL answer wins; when
 * every provider misses, the key is unknown (`get()` returns null) so the
 * loader's unknown-secret violation still fires.
 *
 * This is the composition primitive the dual secrets ports (issue #355 C-6)
 * were missing: a deployment can now layer sources without custom glue —
 * typically environment-first, file fallback, then a network vault — and
 * wrap the WHOLE chain in {@see ResilientSecretsProvider} for retries +
 * stale fallback, which decorates the canonical port.
 *
 * Semantics per provider stay untouched: key grammar, unknown-vs-empty
 * distinction and the "never log secret material" contract are delegated.
 * Note the asymmetry with the loader's C-2 rule: a chain member returning
 * `''` is a HIT (the chain does not know deployment intent), and the loader
 * then rejects the empty resolution — empty secrets never reach the app.
 */
final readonly class ChainSecretsProvider implements SecretsProviderInterface
{
    /**
     * @param list<SecretsProviderInterface> $providers consultation order —
     *                                                     first hit wins
     */
    public function __construct(private array $providers)
    {
        if ($providers === []) {
            throw new \InvalidArgumentException('A secrets chain needs at least one provider.');
        }
        foreach (array_values($providers) as $provider) {
            if (!$provider instanceof SecretsProviderInterface) {
                throw new \InvalidArgumentException(
                    'Chain secrets providers must implement SecretsProviderInterface, got '
                    . get_debug_type($provider) . '.'
                );
            }
        }
    }

    #[\Override]
    public function get(string $key): ?string
    {
        foreach ($this->providers as $provider) {
            $value = $provider->get($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
