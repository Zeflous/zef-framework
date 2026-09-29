<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Event;

use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class EventDispatcher implements EventBusInterface, ListenerProviderInterface
{
    private const int DISPATCH_DEPTH_LIMIT = 64;

    /**
     * @var array<string,list<EventRegistration>>
     */
    private array $listeners = [];

    /**
     * @var array<string,list<EventRegistration>>
     */
    private array $resolved = [];
    private int $sequence = 0;
    private bool $frozen = false;
    private int $dispatchDepth = 0;

    #[\Override]
    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        if ($this->frozen) {
            throw new \LogicException('Cannot register event listeners after the event bus is frozen.');
        }
        if ($eventClass === '' || (!class_exists($eventClass) && !interface_exists($eventClass))) {
            throw new \InvalidArgumentException("Unknown event class '{$eventClass}'.");
        }
        $registrationSequence = $this->sequence;
        ++$this->sequence;
        $this->listeners[$eventClass][] = new EventRegistration(
            $eventClass,
            $listener,
            $priority,
            $registrationSequence,
        );
        $this->resolved = [];
    }

    #[\Override]
    public function subscribe(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::subscriptions() as $eventClass => $handlers) {
            foreach ($handlers as $handler) {
                $priority = 0;
                $listener = $handler;
                if (is_array($handler) && is_int($handler[0]) && is_callable($handler[1])) {
                    $priority = $handler[0];
                    $listener = $handler[1];
                }
                if (!is_callable($listener)) {
                    throw new \InvalidArgumentException('Event subscriber listener must be callable.');
                }
                $this->listen($eventClass, $listener, $priority);
            }
        }
    }

    #[\Override]
    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }
        foreach ($this->listeners as &$registrations) {
            usort(
                $registrations,
                $this->compareRegistrations(...),
            );
        }
        unset($registrations);
        $this->frozen = true;
        $this->resolved = [];
    }

    #[\Override]
    public function dispatch(object $event): object
    {
        return $this->dispatchWithContext(
            $event,
            new EventContext(
                bin2hex(random_bytes(16)),
                (int) (microtime(true) * 1_000_000_000),
            ),
        );
    }

    #[\Override]
    public function dispatchWithContext(object $event, EventContext $context): object
    {
        // Re-entrant dispatch guard: a listener dispatching the same event
        // recursed unbounded and died with an uncatchable OOM fatal.
        if ($this->dispatchDepth >= self::DISPATCH_DEPTH_LIMIT) {
            throw new \LogicException('Event dispatch depth exceeded (recursive dispatch?).');
        }
        ++$this->dispatchDepth;

        try {
            $this->doDispatchWithContext($event, $context);
        } finally {
            --$this->dispatchDepth;
        }

        return $event;
    }

    /** @return list<EventRegistration> */
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        $key = $event::class;
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $result = [];
        foreach ($this->listeners as $eventClass => $registrations) {
            if ($event instanceof $eventClass) {
                foreach ($registrations as $registration) {
                    $result[] = $registration;
                }
            }
        }
        usort(
            $result,
            $this->compareRegistrations(...),
        );
        if ($this->frozen) {
            $this->resolved[$key] = $result;
        }

        return $result;
    }

    /** @return list<EventRegistration> */
    #[\Override]
    public function registrations(): array
    {
        $out = [];
        foreach ($this->listeners as $rs) {
            foreach ($rs as $r) {
                $out[] = $r;
            }
        }

        return $out;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Deterministic dispatch order: highest priority first, ties broken by
     * ascending registration sequence (stability).
     */
    private function compareRegistrations(EventRegistration $a, EventRegistration $b): int
    {
        $byPriority = $b->priority <=> $a->priority;

        return $byPriority !== 0 ? $byPriority : ($a->sequence <=> $b->sequence);
    }

    private function doDispatchWithContext(object $event, EventContext $context): void
    {
        $errors = [];
        // PSR-14 (StoppableEventInterface): once propagation is stopped,
        // NO further listeners may be invoked. This was never checked,
        // so both pre-stopped events and mid-chain stops ran the whole
        // listener list.
        if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
            return;
        }
        foreach ($this->getListenersForEvent($event) as $registration) {
            try {
                $listener = $registration->listener;
                if (!is_callable($listener)) {
                    throw new \LogicException('Registered event listener is no longer callable.');
                }
                if ($registration->acceptsContext) {
                    $listener($event, $context);
                } else {
                    $listener($event);
                }
            } catch (\Throwable $e) {
                // Aggregate every listener failure: the exception contract
                // exposes list<Throwable>; fail-fast would make it
                // impossible to hold more than one error.
                $errors[] = $e;
            }
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }
        if ($errors !== []) {
            throw new EventDispatchException('Event listener failed for ' . $event::class . '.', $errors, $event);
        }
    }
}
