<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Adapters layer (inbound adapters)
 * Added by the router feature-expansion pass as the validated value object
 * carried by RouteGroupStack (keeps the stack merge signature small).
 */

namespace Zef\Framework\Router;

/**
 * Effective attributes of a (possibly nested) route group, already merged
 * with every enclosing group. Immutable value object.
 */
final readonly class GroupAttributes
{
    /**
     * @param list<string>         $middleware service IDs
     * @param array<string,string> $bindings   param => binder service ID
     * @param list<string>         $accepts    media types
     */
    public function __construct(
        public string $prefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public ?int $priority = null,
        public string $host = '',
        public array $bindings = [],
        public array $accepts = [],
    ) {}

    /** The identity attributes for an empty (root) stack. */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Merges this group (parent) with a child group: prefixes, name prefixes,
     * middleware, bindings and accept lists concatenate; priority adds up.
     * The effective host is resolved by the caller (it can fail closed) and
     * passed in.
     */
    public function mergedWith(self $child, string $host): self
    {
        return new self(
            prefix: $this->prefix . $child->prefix,
            namePrefix: $this->namePrefix . $child->namePrefix,
            middleware: array_merge($this->middleware, $child->middleware),
            priority: $child->priority === null ? $this->priority : ($this->priority ?? 0) + $child->priority,
            host: $host,
            bindings: array_merge($this->bindings, $child->bindings),
            accepts: array_merge($this->accepts, $child->accepts),
        );
    }
}
