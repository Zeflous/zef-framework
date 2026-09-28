<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Builds the PSR-15 middleware pipeline from the `middleware.stack` config.
 *
 * Ordering (v2.31.0, Regresi I-6 / issue #174): entries are ordered by
 * their `priority` key, HIGHEST FIRST — the convention already shared by
 * route priority (Router) and listener priority (EventDispatcher), so the
 * scaffolded `['service' => ..., 'priority' => 500]` hint from
 * make:middleware now means "outermost". Ties keep the
 * `middleware.stack` declaration order via an explicit index tiebreak
 * (never rely on engine sort stability). Entries without a `priority`
 * key default to 0 (see MiddlewareDefinition), so stacks that never set
 * priorities keep their exact config order — the previous behaviour.
 */
final readonly class PipelineFactory
{
    public function __construct(
        private Container $container,
        private ConfigAggregator $config,
        private RequestHandlerInterface $terminal,
    ) {}

    public function build(): MiddlewarePipeline
    {
        $entries = $this->config->get('middleware.stack', []);
        if (!is_array($entries)) {
            $entries = [];
        }
        $stack = [];
        $definitions = [];
        foreach ($entries as $entry) {
            try {
                $definitions[] = MiddlewareDefinition::fromLegacy($entry);
            } catch (\Throwable $e) {
                throw new InvalidConfigurationException($e->getMessage(), 0, $e);
            }
        }
        // Regresi I-6 (issue #174): the priority/group/tags keys were
        // parsed but never used — config order silently won. Priority now
        // orders the pipeline (highest first, declaration order on ties).
        $definitions = $this->sortByPriority($definitions);
        foreach ($definitions as $definition) {
            $id = $definition->serviceId;
            if (!$this->container->has($id)) {
                throw new InvalidConfigurationException("Middleware service '{$id}' is not registered.");
            }
            $mw = $this->container->get($id);
            if (!$mw instanceof MiddlewareInterface) {
                throw new InvalidConfigurationException("Service '{$id}' does not implement MiddlewareInterface.");
            }
            // Collect into a plain array and construct ONCE: each
            // withMiddleware() previously copied the whole stack (O(n²)
            // build time, 317ms at 5000 middlewares).
            $stack[] = $mw;
        }

        return new MiddlewarePipeline($stack, $this->terminal);
    }

    /**
     * Stable priority ordering (Regresi I-6, issue #174): Schwartzian
     * transform — each definition is tagged with its declaration index so
     * the comparison never sees equal elements and the sort is stable by
     * construction.
     *
     * @param list<MiddlewareDefinition> $definitions
     *
     * @return list<MiddlewareDefinition>
     */
    private function sortByPriority(array $definitions): array
    {
        $tagged = [];
        foreach ($definitions as $index => $definition) {
            $tagged[] = [$definition->priority, $index, $definition];
        }
        usort($tagged, $this->compareByPriority(...));
        $ordered = [];
        foreach ($tagged as $row) {
            $ordered[] = $row[2];
        }

        return $ordered;
    }

    /**
     * Highest priority first; ties broken by ascending declaration index.
     *
     * @param array{0:int,1:int,2:MiddlewareDefinition} $a
     * @param array{0:int,1:int,2:MiddlewareDefinition} $b
     */
    private function compareByPriority(array $a, array $b): int
    {
        $cmp = $b[0] <=> $a[0];

        return $cmp !== 0 ? $cmp : ($a[1] <=> $b[1]);
    }
}
