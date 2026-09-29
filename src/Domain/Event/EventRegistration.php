<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Event;

final readonly class EventRegistration
{
    public bool $acceptsContext;

    public function __construct(
        public string $eventClass,
        public mixed $listener,
        public int $priority = 0,
        public int $sequence = 0,
    ) {
        if ($eventClass === '' || (!class_exists($eventClass) && !interface_exists($eventClass))) {
            throw new \InvalidArgumentException("Invalid event class '{$eventClass}'.");
        }
        if (!is_callable($listener)) {
            throw new \InvalidArgumentException('Event listener must be callable.');
        }
        $reflection = $this->reflectListener($listener);
        $this->acceptsContext = $reflection instanceof \ReflectionFunctionAbstract
            && $reflection->getNumberOfParameters() >= 2;
    }

    /**
     * Reflection for the listener's invocation signature, or null when the
     * callable cannot be reflected (an object relying on __call() — the safe
     * default is a single, context-less argument).
     */
    private function reflectListener(mixed $listener): ?\ReflectionFunctionAbstract
    {
        if (is_array($listener)) {
            return $this->reflectArrayListener($listener);
        }
        if (!is_callable($listener)) {
            // Unreachable: the constructor already rejected non-callables.
            return null;
        }

        // Closures pass through untouched; first-class callable strings
        // ('func', 'Class::method') and invokable objects are normalized to
        // Closures so reflection stays uniform.
        return new \ReflectionFunction(\Closure::fromCallable($listener));
    }

    /**
     * Reflection for an array-shaped callable [$objectOrClass, $method], or
     * null when the pair cannot be reflected.
     *
     * @param array<mixed, mixed> $listener
     */
    private function reflectArrayListener(array $listener): ?\ReflectionFunctionAbstract
    {
        $target = $listener[0] ?? null;
        $method = $listener[1] ?? null;
        if ((is_object($target) || is_string($target)) && is_string($method)) {
            try {
                return new \ReflectionMethod($target, $method);
            } catch (\ReflectionException) {
                // Object relying on __call(): is_callable() passes but no
                // real method exists. Invoke single-argument (context-less)
                // — the safe default for magic callables.
            }
        }

        // Unreachable after the constructor's is_callable() verification.
        return null;
    }
}
