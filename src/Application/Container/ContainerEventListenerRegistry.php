<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Container event-listener bookkeeping split out of ServiceRegistry
 * (v2.10.0 resolving/resolved hooks) so each class stays under the
 * method-count budget. The registry's public surface is unchanged:
 * ServiceRegistry inherits every listener method exactly as before.
 */

namespace Zef\Framework\Container;

/**
 * @internal
 */
class ContainerEventListenerRegistry
{
    /**
     * @var list<callable>
     */
    private array $resolvingListeners = [];

    /**
     * @var list<callable>
     */
    private array $resolvedListeners = [];

    public function addResolvingListener(callable $listener): void
    {
        if (count($this->resolvingListeners) >= 32) {
            throw new \OverflowException('Container resolving-listener budget exceeded (32).');
        }
        $this->resolvingListeners[] = $listener;
    }

    public function addResolvedListener(callable $listener): void
    {
        if (count($this->resolvedListeners) >= 32) {
            throw new \OverflowException('Container resolved-listener budget exceeded (32).');
        }
        $this->resolvedListeners[] = $listener;
    }

    public function hasResolvingListeners(): bool
    {
        return $this->resolvingListeners !== [];
    }

    public function hasResolvedListeners(): bool
    {
        return $this->resolvedListeners !== [];
    }

    /** @return list<callable> */
    public function resolvingListeners(): array
    {
        return $this->resolvingListeners;
    }

    /** @return list<callable> */
    public function resolvedListeners(): array
    {
        return $this->resolvedListeners;
    }
}
