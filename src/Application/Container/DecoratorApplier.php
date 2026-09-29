<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration).
 * Service decoration extracted from Container (php:S2042): collection of
 * decorator chains and their rewrite of the registry at freeze time.
 * Byte-identical move of the Container logic — no behavioural changes.
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;

final class DecoratorApplier
{
    /**
     * @var array<string,list<callable>> id => decorator chain (first = outermost)
     */
    private array $decorators = [];

    /**
     * Decorate a service: $decorator receives (ResolutionContext $ctx, mixed $inner)
     * and returns the decorated instance. First-registered = outermost.
     * Applied at freeze time as wrapper definitions.
     */
    public function add(string $id, callable $decorator): void
    {
        $total = array_sum(array_map(count(...), $this->decorators)) + 1;
        if ($total > 128) {
            throw new \OverflowException('Container decoration budget exceeded (128).');
        }
        $this->decorators[$id][] = $decorator;
    }

    /** Applies decorator chains by rewriting the registry (pre-compile). */
    public function apply(ServiceRegistry $registry, int $budget): void
    {
        if ($this->decorators === []) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: tanpa decorator,
            // foreach di atas map kosong adalah no-op
            return;
        }
        foreach ($this->decorators as $id => $chain) {
            $definitions = $registry->definitions();
            if (!isset($definitions[$id])) {
                throw new InvalidConfigurationException("Cannot decorate unknown service '{$id}'.");
            }
            $definition = $definitions[$id];
            $baseId = '@inner:' . $id . ':base';
            // Innermost: the original definition re-homed under a synthetic id.
            // ZEF-DEEP-08: the re-homed base is internal plumbing — it must
            // NOT keep the tags (pre-fix it did), or the tag index points
            // consumers at the synthetic id and they silently resolve the
            // UNDECORATED inner instance. The tags travel with the service
            // identity — the outermost wrapper registered under the original
            // id — mirroring the contextual-binding rewrite pattern.
            $registry->addDefinition(new ServiceDefinition(
                $baseId,
                $definition->factory,
                $definition->dependencies,
                $definition->module,
                $definition->lifetime,
                $definition->shared,
                $definition->lazy,
                [],
            ));
            // @infection-ignore-all GreaterThanOrEqualTo,Throw_ — ekuivalen:
            // redundan dengan cek budget per-wrapper di dalam loop; penegakan
            // budget tetap terjamin
            if (count($registry->definitions()) >= $budget) {
                throw new InvalidConfigurationException('Service registration budget exceeded during decoration.');
            }
            $this->wrapDecoratorChain($registry, $id, $baseId, $chain, $definition, $budget);
        }
    }

    /**
     * Registers the outermost-first decorator chain as nested wrappers (inside out).
     *
     * @param list<callable> $chain
     */
    private function wrapDecoratorChain(
        ServiceRegistry $registry,
        string $id,
        string $baseId,
        array $chain,
        ServiceDefinition $definition,
        int $budget,
    ): void {
        $previousId = $baseId;
        $count = count($chain);
        // Outermost-first chain => wrap from the inside out.
        for ($i = $count - 1; $i >= 0; --$i) {
            $decorator = $chain[$i];
            $wrapperId = $i === 0 ? $id : '@inner:' . $id . ':' . $i;
            $closureId = $previousId;
            $registry->addDefinition(new ServiceDefinition(
                $wrapperId,
                static fn (ContainerInterface $ctx, mixed $inner): mixed => $decorator($ctx, $inner),
                [$closureId],
                $definition->module,
                $definition->lifetime,
                $definition->shared,
                $definition->lazy,
                $i === 0 ? $definition->tags : [],
            ));
            if (count($registry->definitions()) >= $budget) {
                throw new InvalidConfigurationException('Service registration budget exceeded during decoration.');
            }
            $previousId = $wrapperId;
        }
    }
}
