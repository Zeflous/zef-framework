<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Service-provider bookkeeping extracted from Container (php:S2042): eager
 * and deferred provider registration, the deferred trigger index, and the
 * boot hook. Byte-identical move of the Container logic — no behavioural
 * changes.
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ServiceNotFoundException;

final class ProviderBroker
{
    /**
     * @var list<ServiceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string,list<int>> provides() id => pending deferred provider indexes
     */
    private array $deferredIndex = [];

    /**
     * @var array<int,bool> provider indexes whose register() has run
     */
    private array $registeredProviders = [];

    private bool $providersBooted = false;

    public function __construct(private readonly Container $container) {}

    /**
     * Register a service provider. Eager providers run register() now;
     * DeferrableProviderInterface providers run register() the first time
     * get() asks for one of their provides() IDs (composition-time deferred
     * loading — always before validateAndFreeze()).
     */
    public function register(ServiceProviderInterface $provider): void
    {
        if (count($this->providers) >= 64) {
            throw new \OverflowException('Container provider budget exceeded (64).');
        }
        $index = count($this->providers);
        $this->providers[] = $provider;
        if ($provider instanceof DeferrableProviderInterface) {
            foreach ($provider->provides() as $id) {
                $this->deferredIndex[$id][] = $index;
            }

            return;
        }
        // @infection-ignore-all TrueValue — ekuivalen: flag map hanya dibaca lewat isset(); nilai tidak relevan
        $this->registeredProviders[$index] = true;
        $provider->register($this->container);
    }

    /** Boot hook for registered BootableProviderInterface providers (once). */
    public function boot(): void
    {
        if ($this->providersBooted) {
            return;
        }
        $this->providersBooted = true;
        foreach ($this->providers as $index => $provider) {
            if ($provider instanceof BootableProviderInterface && isset($this->registeredProviders[$index])) {
                $provider->boot($this->container);
            }
        }
    }

    /** @return list<ServiceProviderInterface> all registered providers, in order */
    public function all(): array
    {
        return $this->providers;
    }

    /** Runs pending deferred providers that provide $id. */
    public function triggerFor(string $id): void
    {
        $pending = $this->deferredIndex[$id] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->deferredIndex[$id]);
        foreach ($pending as $index) {
            if (isset($this->registeredProviders[$index])) {
                continue;
            }
            if ($this->container->isFrozen()) {
                // PSR-11 (ZEF-DEEP-12): has() reports the service as absent
                // (its deferred provider never ran), so get() must surface a
                // NotFoundExceptionInterface — container-agnostic callers catch
                // that standard interface, not the framework's LogicException.
                $hint = ' — request it before validateAndFreeze() or register the provider as eager.';

                throw new ServiceNotFoundException(
                    $id,
                    null,
                    "Deferred provider service '{$id}' requested but the container is already frozen" . $hint,
                );
            }
            // @infection-ignore-all TrueValue — ekuivalen: registeredProviders
            // hanya dibaca lewat isset(); nilai tidak relevan
            $this->registeredProviders[$index] = true;
            $this->providers[$index]->register($this->container);
        }
    }

    /**
     * Deferred providers whose provides() IDs are referenced by the existing
     * graph (as a dependency or alias target) must register before compile —
     * otherwise their definitions would be invisible to graph validation.
     * Providers nobody references stay pending until a pre-freeze get().
     */
    public function triggerRequired(ServiceRegistry $registry): void
    {
        if ($this->deferredIndex === []) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: tanpa deferred
            // provider, map referenced tetap kosong; loop menjadi no-op
            return;
        }
        // Worklist semantics: triggering a deferred provider registers NEW
        // definitions whose dependencies may themselves reference other still-
        // pending deferred providers. A single pass missed such chains and left
        // them to fail at validateAndFreeze() with a misleading "missing
        // service" error (issue #311). Repeat until a pass registers nothing
        // new; triggerFor() is idempotent, and every pass that registers
        // anything consumes at least one deferred provider, so the loop is
        // bounded by the provider count.
        $passes = count($this->providers) + 1;
        do {
            $registeredBefore = count($this->registeredProviders);
            $referenced = [];
            foreach ($registry->definitions() as $definition) {
                foreach ($definition->dependencies as $dep) {
                    // @infection-ignore-all TrueValue — array_keys() hanya membaca kunci, nilai tidak relevan
                    $referenced[$dep] = true;
                }
            }
            foreach ($registry->aliases() as $target) {
                // @infection-ignore-all TrueValue — array_keys() hanya membaca kunci, nilai tidak relevan
                $referenced[$target] = true;
            }
            foreach (array_keys($referenced) as $id) {
                $this->triggerFor((string) $id);
            }
            $registeredAfter = count($this->registeredProviders);
            --$passes;
        } while ($registeredAfter > $registeredBefore && $passes > 0);
    }
}
