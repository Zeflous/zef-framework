<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

/**
 * Shared CQRS bus logic extracted from CommandBus and QueryBus.
 */
trait CqrsBusTrait
{
    private const int DISPATCH_DEPTH_LIMIT = 64;

    /**
     * @var array<string,callable>
     */
    private array $handlers = [];

    /**
     * @var list<CqrsMiddlewareInterface>
     */
    private array $middleware = [];
    private bool $frozen = false;
    private int $dispatchDepth = 0;

    /**
     * Re-entrant dispatch guard: a handler re-dispatching the same
     * command/query recursed unbounded and died with an uncatchable OOM
     * fatal instead of a LogicException.
     */
    private function guardDispatchDepth(callable $operation): mixed
    {
        ++$this->dispatchDepth;
        if ($this->dispatchDepth > self::DISPATCH_DEPTH_LIMIT) {
            --$this->dispatchDepth;

            throw new \LogicException(static::class . ' dispatch depth exceeded (recursive dispatch?).');
        }

        try {
            return $operation();
        } finally {
            --$this->dispatchDepth;
        }
    }

    private function buildChain(callable $handler): \Closure
    {
        $next = \Closure::fromCallable($handler);
        for ($i = count($this->middleware) - 1; $i >= 0; --$i) {
            $layer = $this->middleware[$i];
            $next = static fn (object $message, CqrsContext $ctx): mixed => $layer->process($message, $ctx, $next);
        }

        return $next;
    }

    private function resolveHandler(object $message, string $kind): callable
    {
        $class = $message::class;
        if (isset($this->handlers[$class])) {
            return $this->handlers[$class];
        }
        $matches = [];
        foreach ($this->handlers as $registered => $handler) {
            if ($message instanceof $registered) {
                $matches[] = [$registered, $handler];
            }
        }
        if ($matches === []) {
            throw new CqrsHandlerNotFoundException("No {$kind} handler registered for {$class}.");
        }
        if (count($matches) > 1) {
            throw new CqrsHandlerConflictException(
                "Ambiguous {$kind} handlers for {$class}. Register the concrete class explicitly.",
            );
        }

        return $matches[0][1];
    }

    private function validateMessageClass(string $class, string $type): void
    {
        if ($class === '' || (!class_exists($class) && !interface_exists($class))) {
            throw new \InvalidArgumentException("Invalid {$type} class '{$class}'.");
        }
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException(static::class . ' is frozen.');
        }
    }
}
