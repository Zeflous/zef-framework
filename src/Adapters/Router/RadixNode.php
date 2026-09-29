<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapters)
 * Added in the sonar-zero hardening pass: one node of the immutable-after-
 * freeze route radix index (static edges, constraint-keyed dynamic edges
 * and the route indices terminating at this node).
 */

namespace Zef\Framework\Router;

final class RadixNode
{
    /** @var array<string,int> static edge value => child node index */
    public array $static = [];

    /** @var array<string,int> constraint key ('' when none) => child node index */
    public array $dynamic = [];

    /** @var list<int> route indices whose pattern terminates at this node */
    public array $routes = [];
}
