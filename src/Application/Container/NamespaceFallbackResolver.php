<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Namespace-fallback resolution extracted from Container (php:S2042): the
 * longest-prefix fallback registry, its per-ID singleton cache, and the
 * lifetime-aware invocation of fallback factories. Byte-identical move of
 * the Container logic — no behavioural changes.
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Policy\NamespaceScopePolicy;

final class NamespaceFallbackResolver
{
    /**
     * @var array<string,array{factory:callable,lifetime:string}> normalized prefix => fallback
     */
    private array $namespaceFallbacks = [];

    /**
     * @var array<string,mixed> per-ID cache for singleton namespace fallbacks
     */
    private array $fallbackInstances = [];

    public function register(
        string $prefix,
        callable $factory,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void {
        if (count($this->namespaceFallbacks) >= 64) {
            throw new \OverflowException('Container namespace-fallback budget exceeded (64).');
        }
        if ($lifetime === ServiceLifetime::REQUEST) {
            throw new InvalidConfigurationException(
                'Namespace fallback lifetime cannot be REQUEST (fallback IDs are not scoped).',
            );
        }
        ServiceLifetime::assert($lifetime);
        $this->namespaceFallbacks[NamespaceScopePolicy::normalize($prefix)] = [
            'factory' => $factory,
            'lifetime' => $lifetime,
        ];
    }

    /** True when at least one registered fallback prefix covers $id. */
    public function appliesTo(string $id): bool
    {
        return $this->namespaceFallbacks !== [] && $this->fallbackFor($id) !== null;
    }

    /**
     * Resolves $id through its longest-prefix fallback (lazily caching
     * SINGLETON results per requested ID).
     */
    public function resolve(ContainerInterface $container, string $id): mixed
    {
        $fallback = $this->fallbackFor($id);
        if ($fallback === null) {
            // Caller guards with appliesTo(); kept defensive for direct use.
            throw new ServiceResolutionException($id, 'no namespace fallback covers the requested id.');
        }
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON && array_key_exists($id, $this->fallbackInstances)) {
            return $this->fallbackInstances[$id];
        }
        $factory = $fallback['factory'];

        try {
            $instance = $factory($container, $id);
        } catch (\Throwable $e) {
            throw new ServiceResolutionException($id, 'namespace fallback factory failed: ' . $e->getMessage(), $e);
        }
        if ($instance === null) {
            throw new ServiceResolutionException($id, 'namespace fallback factory returned null.');
        }
        if ($fallback['lifetime'] === ServiceLifetime::SINGLETON) {
            $this->fallbackInstances[$id] = $instance;
        }

        return $instance;
    }

    /** Clears cached fallback singletons (mirrors Container::reset()). */
    public function clearSingletons(): void
    {
        $this->fallbackInstances = [];
    }

    /** Longest-prefix fallback lookup. @return array{factory:callable,lifetime:string}|null */
    private function fallbackFor(string $id): ?array
    {
        $best = null;
        // @infection-ignore-all IncrementInteger,DecrementInteger — ekuivalen:
        // fallback prefix divalidasi non-kosong; strlen >= 1 selalu mengalahkan
        // init <= 0
        $bestLen = -1;
        foreach ($this->namespaceFallbacks as $prefix => $entry) {
            // @infection-ignore-all GreaterThan — ekuivalen: prefix berbeda
            // dengan panjang sama mustahil cocok pada satu id; untuk prefix
            // bersarang hasil pemenangnya sama
            if (str_starts_with($id, $prefix) && strlen($prefix) > $bestLen) {
                $best = $entry;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }
}
