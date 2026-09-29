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
        $reflection = self::reflectListener($listener);
        $this->acceptsContext = $reflection === null || $reflection->getNumberOfParameters() >= 2;
    }

    /**
     * Reflection for the listener's invocation signature, or null when the
     * callable relies on __call() (no real method to reflect — the safe
     * default is a single, context-less argument).
     */
    private static function reflectListener(mixed $listener): ?\ReflectionFunctionAbstract
    {
        if (is_array($listener)) {
            try {
                return new \ReflectionMethod($listener[0], (string) $listener[1]);
            } catch (\ReflectionException) {
                // Object relying on __call(): is_callable() passes but no
                // real method exists. Invoke single-argument (context-less)
                // — the safe default for magic callables.
                return null;
            }
        }
        if ($listener instanceof \Closure) {
            return new \ReflectionFunction($listener);
        }

        // First-class callable strings ('func', 'Class::method') and
        // invokable objects are accepted by is_callable(); normalize
        // them to Closures so reflection stays uniform.
        return new \ReflectionFunction(\Closure::fromCallable($listener));
    }
}
