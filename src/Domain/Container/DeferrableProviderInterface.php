<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Marker for deferred providers: register() is postponed until one of the
 * provides() IDs is actually requested via the container's get().
 */
interface DeferrableProviderInterface extends ServiceProviderInterface {}
