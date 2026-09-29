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
 * Since the sonar-zero campaign the per-parameter planning lives in
 * AutowireArgumentResolver, configuration-value materialization in
 * AutowireValueServices, and the per-run accumulator in
 * AutowireCompilationState.
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireParameterSpec;
use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;

final class AutowireCompilerPass
{
    public const string VALUE_SERVICE_PREFIX = '@value:';

    /**
     * @param array<string,mixed> $configValues source for #[Value('key')] lookups
     * @param null|string         $module       module attributed to every generated definition
     * @param string              $lifetime     lifetime for every generated definition
     */
    public function __construct(
        private ?ReflectionMetadataExtractor $extractor = null,
        private readonly array $configValues = [],
        private readonly ?string $module = null,
        private readonly string $lifetime = ServiceLifetime::SINGLETON,
    ) {
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
        $resolver = new AutowireArgumentResolver(
            $this,
            new AutowireValueServices($state, $this->configValues, $this->module),
            $state,
        );

        foreach ($classes as $index => $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException(
                    "Cannot autowire classes[{$index}]: expected a class-name string.",
                );
            }
            $this->autowireClass($container, $class, [], $resolver);
        }

        return $state->toResult();
    }

    /**
     * Ensure a service exists for $class and return its service ID.
     * Existing registrations/aliases are reused untouched.
     *
     * @param list<string> $classStack in-progress chain for cycle diagnostics
     */
    public function autowireClass(
        Container $container,
        string $class,
        array $classStack,
        AutowireArgumentResolver $resolver,
    ): string {
        if (!class_exists($class)) {
            throw new InvalidConfigurationException("Cannot autowire '{$class}': class does not exist.");
        }
        $ref = new \ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            $message = "Cannot autowire '{$class}': interface, abstract class, ";
            $message .= 'or otherwise non-instantiable.';

            throw new InvalidConfigurationException($message);
        }
        if ($container->has($class)) {
            $resolver->state()->recordReused($class);

            return $class;
        }
        if (in_array($class, $classStack, true)) {
            $chain = array_slice($classStack, (int) array_search($class, $classStack, true));
            $chain[] = $class;

            throw new ServiceCircularDependencyException($chain);
        }

        $spec = $this->extractor()->extract($class);
        $deps = [];
        $args = [];
        foreach ($spec->parameters as $parameter) {
            $resolver->planParameter(
                $container,
                $class,
                $parameter,
                $deps,
                $args,
                [...$classStack, $class],
            );
        }

        $metadata = new AutowireMetadata($class, $class, $deps, $args, $this->module, $this->lifetime);
        $code = AutowireAotCompiler::generateFactory($metadata);
        $factory = AutowireAotCompiler::evalFactory($code);

        $container->registerDefinition(new ServiceDefinition(
            id: $class,
            factory: $factory,
            dependencies: $deps,
            module: $this->module,
            lifetime: $this->lifetime,
            shared: $this->lifetime === ServiceLifetime::SINGLETON,
        ));

        $resolver->state()->recordGenerated($class, $metadata, $code);

        return $class;
    }

    /**
     * Explicit ID reference: must be an existing service/alias or an
     * instantiable, autowireable class.
     *
     * @param AutowireArgumentResolver $resolver active per-run planner
     */
    public function ensureService(
        Container $container,
        string $id,
        array $classStack,
        AutowireArgumentResolver $resolver,
    ): string {
        if ($container->has($id)) {
            return $id;
        }
        if (class_exists($id) && new \ReflectionClass($id)->isInstantiable()) {
            return $this->autowireClass($container, $id, $classStack, $resolver);
        }

        throw new ServiceNotFoundException($id, $this->module);
    }

    /**
     * #[Target]-pinned concrete class for an interface/abstract parameter:
     * verified instantiable, then autowired under its own FQCN.
     *
     * @param list<string> $classStack
     */
    public function autowireTarget(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        string $targetClass,
        array $classStack,
        AutowireArgumentResolver $resolver,
    ): string {
        if (!new \ReflectionClass($targetClass)->isInstantiable()) {
            $message = "Cannot autowire {$ownerClass}::\${$p->name}: #[Target({$targetClass}::class)] ";
            $message .= 'is not an instantiable class.';

            throw new InvalidConfigurationException($message);
        }

        return $this->autowireClass($container, $targetClass, $classStack, $resolver);
    }

    /**
     * Collect every registered service that satisfies the given type.
     * Sources: FQCN service IDs (is_a match) and factory closures with a
     * declared, compatible return type. Registration order is preserved.
     *
     * @return list<string>
     */
    public function collectImplementations(Container $container, string $type): array
    {
        $ids = [];
        foreach ($container->getRegistry()->definitions() as $id => $definition) {
            if (str_starts_with($id, self::VALUE_SERVICE_PREFIX)) {
                continue;
            }
            // @infection-ignore-all LogicalOrAllSubExprNegation
            // ekuivalen: negasi ganda identik untuk id class/interface/bukan-keduanya;
            // is_a menutup sisa kasus
            if ((class_exists($id) || interface_exists($id)) && is_a($id, $type, true)) {
                $ids[] = $id;

                continue;
            }
            $returnType = $this->factoryReturnType($definition->factory);
            if ($returnType !== null && is_a($returnType, $type, true)) {
                $ids[] = $id;
            }
        }

        // @infection-ignore-all UnwrapArrayUnique,UnwrapArrayValues
        // ekuivalen: id dari kunci map terkumpul paling sekali; kunci numerik
        // sudah berurutan
        return array_values(array_unique($ids));
    }

    private function factoryReturnType(mixed $factory): ?string
    {
        try {
            $fn = $factory instanceof \Closure ? $factory : \Closure::fromCallable($factory);
            $returnType = new \ReflectionFunction($fn)->getReturnType();
        } catch (\Throwable) {
            return null;
        }
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            return $returnType->getName();
        }

        return null; // no declared return type / builtin / union: not inferable
    }

    private function extractor(): ReflectionMetadataExtractor
    {
        // @infection-ignore-all Coalesce — ekuivalen: ReflectionMetadataExtractor final dan stateless;
        // instance injeksi perilakunya identik dengan default baru
        return $this->extractor ??= new ReflectionMetadataExtractor();
    }
}
