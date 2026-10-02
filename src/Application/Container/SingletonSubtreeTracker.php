<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Application layer (in-process orchestration)
 * Container-wide "singleton construction in flight" signal (audit #343/#345).
 *
 * The per-context singleton stack (ResolutionContext) only sees pulls that
 * travel through the SAME context. Root-container pulls create a fresh
 * context and were invisible to the v2.35.0 implicit-capture guard. This
 * tracker is shared by every instantiation path of ONE container (registry
 * singletons via ServiceInstantiator, namespace-fallback singletons via
 * NamespaceFallbackResolver) and records the innermost singleton id per
 * FIBER, so concurrent fibers never observe each other's construction.
 */

namespace Zef\Framework\Container;

/**
 * @internal
 */
final class SingletonSubtreeTracker
{
    /** @var array<int,list<string>> fiber key => stack of singleton ids under construction */
    private array $stacks = [];

    /** Records that a singleton construction opened on the current fiber. */
    public function enter(string $id): void
    {
        $this->stacks[self::fiberKey()][] = $id;
    }

    /**
     * Closes a finished singleton construction (LIFO — mirrors
     * ResolutionContext::pop() semantics: an id not on top is ignored, so a
     * partially unwound stack keeps its enclosing entries intact).
     */
    public function exit(string $id): void
    {
        $key = self::fiberKey();
        $stack = $this->stacks[$key] ?? [];
        if ($stack === [] || end($stack) !== $id) {
            return;
        }
        array_pop($this->stacks[$key]);
        if ($this->stacks[$key] === []) {
            unset($this->stacks[$key]);
        }
    }

    /** True while a singleton construction subtree is open on the current fiber. */
    public function isOpen(): bool
    {
        return ($this->stacks[self::fiberKey()] ?? []) !== [];
    }

    /** The innermost singleton under construction on the current fiber (null otherwise). */
    public function current(): ?string
    {
        $stack = $this->stacks[self::fiberKey()] ?? [];

        return $stack === [] ? null : (string) $stack[count($stack) - 1];
    }

    /**
     * Fiber identity used as the tracking key (0 = no active fiber). Public
     * so collaborators that keep their own fiber-scoped state (fallback
     * resolution depth) key their counters identically.
     */
    public static function fiberKey(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber instanceof \Fiber ? spl_object_id($fiber) : 0;
    }
}
