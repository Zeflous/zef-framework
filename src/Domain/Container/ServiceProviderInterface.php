<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Service provider port (composition-time module wiring).
 *
 * Semantics:
 *   - register(): push definitions/aliases into the container. Eager providers
 *     run at registerProvider() time; deferred providers run lazily the first
 *     time get() asks for one of the IDs returned by provides().
 *   - provides(): the service IDs this provider is able to register. Used to
 *     decide when a deferred provider must be triggered.
 *
 * Implementations MUST only compose definitions (no resolution inside
 * register()); runtime wiring belongs in BootableProviderInterface::boot().
 * Since the issue #36 exit ramp the composition handle is the
 * ServiceRegistrarInterface port, so that convention is now enforced at
 * the type level: the container handle exposes register()/alias() only.
 */
interface ServiceProviderInterface
{
    /** @return list<string> service IDs this provider can register */
    public function provides(): array;

    public function register(ServiceRegistrarInterface $container): void;
}
