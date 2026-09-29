<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Application layer (autowiring compile pass).
 *
 * Runs BEFORE Container::validateAndFreeze(). For every requested class it
 * produces a ServiceDefinition whose $dependencies list is complete (direct
 * services, synthetic @value:* services, and — through the definitions of
 * those dependencies — the whole transitive graph), so the untouched
 * DependencyGraphValidator / ContainerCompiler can verify cycles, cross-module
 * references, and singleton-closure lifetime rules exactly as for
 * hand-written registrations.
 *
 * Reflection is used ONLY in this phase. Every registered factory is a pure
 * generated \Closure (see AutowireAotCompiler) — zero reflection at runtime.
 *
 * Since the sonar-zero campaign the pass is a thin orchestrator: the class
 * walk lives in AutowireClassPlanner, per-parameter planning in
 * AutowireArgumentResolver, implementation collection in
 * AutowireImplementationCollector, configuration-value materialization in
 * AutowireValueServices, and the per-run accumulator in
 * AutowireCompilationState.
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;

final class AutowireCompilerPass
{
    public const string VALUE_SERVICE_PREFIX = '@value:';

    private ?ReflectionMetadataExtractor $extractor;

    /**
     * @param array<string,mixed> $configValues source for #[Value('key')] lookups
     * @param null|string         $module       module attributed to every generated definition
     * @param string              $lifetime     lifetime for every generated definition
     */
    public function __construct(
        ?ReflectionMetadataExtractor $extractor = null,
        private readonly array $configValues = [],
        private readonly ?string $module = null,
        private readonly string $lifetime = ServiceLifetime::SINGLETON,
    ) {
        $this->extractor = $extractor;
        ServiceLifetime::assert($lifetime);
    }

    /**
     * Autowire the given classes (and, recursively, their dependencies) into
     * the container. Must be called before validateAndFreeze().
     *
     * @param list<class-string> $classes
     */
    public function process(Container $container, array $classes): AutowireResult
    {
        if ($container->isFrozen()) {
            throw new \LogicException('Container is frozen.');
        }

        $state = new AutowireCompilationState();
        $planner = new AutowireClassPlanner(
            $this->extractor(),
            $state,
            new AutowireValueServices($state, $this->configValues, $this->module),
            new AutowireImplementationCollector(),
            $this->module,
            $this->lifetime,
        );
        $planner->planAll($container, $classes);

        return $state->toResult();
    }

    private function extractor(): ReflectionMetadataExtractor
    {
        // @infection-ignore-all Coalesce — ekuivalen: ReflectionMetadataExtractor final dan
        // stateless; instance injeksi perilakunya identik dengan default baru
        return $this->extractor ??= new ReflectionMetadataExtractor();
    }
}
