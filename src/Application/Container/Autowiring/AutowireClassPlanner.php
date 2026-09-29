<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (autowiring compile pass).
 *
 * Class-level walking collaborator of AutowireCompilerPass: walks the
 * requested classes, reuses existing registrations, detects dependency
 * cycles, and registers a generated definition for every missing service.
 * Per-parameter planning is delegated to AutowireArgumentResolver, and
 * implementation collection to AutowireImplementationCollector. Extracted
 * from AutowireCompilerPass in the sonar-zero campaign (behavior-preserving
 * split).
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;

final class AutowireClassPlanner
{
    private ?AutowireArgumentResolver $argumentResolver = null;

    public function __construct(
        private readonly ReflectionMetadataExtractor $extractor,
        private readonly AutowireCompilationState $state,
        private readonly AutowireValueServices $valueServices,
        private readonly AutowireImplementationCollector $implementations,
        private readonly ?string $module,
        private readonly string $lifetime,
    ) {}

    /**
     * @param list<class-string> $classes
     */
    public function planAll(Container $container, array $classes): void
    {
        foreach ($classes as $index => $class) {
            if (!is_string($class) || $class === '') {
                $message = "Cannot autowire classes[{$index}]: expected a class-name string.";

                throw new InvalidConfigurationException($message);
            }
            $this->autowireClass($container, $class, []);
        }
    }

    /**
     * Ensure a service exists for $class and return its service ID.
     * Existing registrations/aliases are reused untouched.
     *
     * @param list<string> $classStack in-progress chain for cycle diagnostics
     */
    public function autowireClass(Container $container, string $class, array $classStack): string
    {
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
            $this->state->recordReused($class);

            return $class;
        }
        if (in_array($class, $classStack, true)) {
            $chain = array_slice($classStack, (int) array_search($class, $classStack, true));
            $chain[] = $class;

            throw new ServiceCircularDependencyException($chain);
        }

        $spec = $this->extractor->extract($class);
        $deps = [];
        $args = [];
        foreach ($spec->parameters as $parameter) {
            $this->argumentResolver()->planParameter(
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

        $this->state->recordGenerated($class, $metadata, $code);

        return $class;
    }

    /**
     * Explicit ID reference: must be an existing service/alias or an
     * instantiable, autowireable class.
     *
     * @param list<string> $classStack
     */
    public function ensureService(Container $container, string $id, array $classStack): string
    {
        if ($container->has($id)) {
            return $id;
        }
        if (class_exists($id) && new \ReflectionClass($id)->isInstantiable()) {
            return $this->autowireClass($container, $id, $classStack);
        }

        throw new ServiceNotFoundException($id, $this->module);
    }

    private function argumentResolver(): AutowireArgumentResolver
    {
        return $this->argumentResolver ??= new AutowireArgumentResolver(
            $this,
            $this->valueServices,
            $this->implementations,
        );
    }
}
