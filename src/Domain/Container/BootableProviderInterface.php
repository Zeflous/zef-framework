<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Optional boot hook, invoked once via the container's bootProviders()
 * after all registrations are in place (typically after
 * validateAndFreeze()). The handle is the same composition port, so boot()
 * keeps composing rather than resolving.
 */
interface BootableProviderInterface
{
    public function boot(ServiceRegistrarInterface $container): void;
}
