<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Domain layer (ports, contracts, value objects)
 * Added by the router feature-expansion pass (route model binding).
 */

namespace Zef\Framework\Router;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Route model binding port (roadmap: "Route model binding otomatis").
 *
 * A binder converts a matched dynamic path parameter (string) into a richer
 * value — a domain entity, a value object, a validated record — and hands it
 * back to the dispatcher, which stores it as a request attribute under the
 * same parameter name. That keeps routing framework-decoupled: the adapter
 * only knows this port, never a concrete persistence mechanism.
 *
 * Bindings are declared per route group (`'bindings' => ['id' => 'user.binder']`)
 * so an entity factory stays a container service and the router keeps its
 * zero-outbound-coupling hexagonal shape.
 */
interface RouteModelBinderInterface
{
    /**
     * @param string                 $value   the decoded path parameter value
     * @param ServerRequestInterface $request the current request (context for the lookup)
     */
    public function resolve(string $value, ServerRequestInterface $request): mixed;
}
