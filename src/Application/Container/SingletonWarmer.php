<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Singleton pre-instantiation extracted from Container (php:S2042): warms
 * every eager shared singleton definition right after freeze. Byte-identical
 * move of the Container logic — no behavioural changes.
 */

namespace Zef\Framework\Container;

final readonly class SingletonWarmer
{
    public function __construct(private Container $container) {}

    public function warm(ServiceRegistry $registry): void
    {
        if (!$this->container->isFrozen()) {
            throw new \LogicException('Container must be frozen before warming singletons.');
        }
        foreach ($registry->definitions() as $id => $definition) {
            if ($definition->lifetime === ServiceLifetime::SINGLETON && $definition->shared && !$definition->lazy) {
                $this->container->get($id);
            }
        }
    }
}
