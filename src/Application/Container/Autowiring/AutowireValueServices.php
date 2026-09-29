<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (autowiring compile pass).
 *
 * Configuration-value materialization for the autowiring compile pass:
 * #[Value] lookups, literal rendering for generated factory code, constructor
 * default rendering, and the synthetic `@value:*` singleton services that
 * carry one configuration value each. Extracted from AutowireCompilerPass in
 * the sonar-zero campaign (behavior-preserving split).
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireParameterSpec;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;

final class AutowireValueServices
{
    public function __construct(
        private readonly AutowireCompilationState $state,
        private readonly array $configValues,
        private readonly ?string $module,
    ) {}

    /**
     * Looks up one configuration value, failing fast when the #[Value] key
     * has no value in the AutowireCompilerPass configuration.
     */
    public function configLookup(string $ownerClass, ?AutowireParameterSpec $p, string $key): mixed
    {
        if (!array_key_exists($key, $this->configValues)) {
            $param = $p instanceof AutowireParameterSpec ? "::\${$p->name}" : '';

            $message = "Cannot autowire {$ownerClass}{$param}: #[Value('{$key}')] has no value in the ";
            $message .= 'AutowireCompilerPass configuration.';

            throw new InvalidConfigurationException($message);
        }

        return $this->configValues[$key];
    }

    /** Renders the declared constructor default of a parameter. */
    public function renderDefault(string $ownerClass, AutowireParameterSpec $p): string
    {
        if ($p->defaultValueConstant !== null) {
            return $p->defaultValueConstant;
        }

        return $this->renderLiteral($p->defaultValue, "default of {$ownerClass}::\${$p->name}");
    }

    /** Renders a PHP literal usable inside generated factory code. */
    public function renderLiteral(mixed $value, string $context): string
    {
        if ($value === null) {
            return 'null'; // var_export emits 'NULL'; prefer idiomatic lowercase
        }
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            $this->assertExportableElements($value, $context);

            return var_export($value, true);
        }

        $message = "{$context} is not exportable (" . get_debug_type($value) . ').';

        throw new InvalidConfigurationException($message);
    }

    /**
     * Synthetic singleton service holding one configuration value.
     */
    public function ensureValueService(Container $container, string $key): string
    {
        $id = AutowireCompilerPass::VALUE_SERVICE_PREFIX . $key;
        if ($container->has($id)) {
            return $id;
        }
        $raw = $this->configLookup('(value)', null, $key);
        if ($raw === null) {
            // The resolver rejects null instances at runtime — fail fast here,
            // pointing at the parameter default as the correct mechanism.
            $message = "Config value '{$key}' is null: services can never resolve to null. ";
            $message .= 'Give the parameter a default value instead of #[Value].';

            throw new InvalidConfigurationException($message);
        }
        $literal = $this->renderLiteral($raw, "config value '{$key}'");
        $code = "static fn (\$ctx) => {$literal}";
        $factory = AutowireAotCompiler::evalFactory($code);

        $container->registerDefinition(new ServiceDefinition(
            id: $id,
            factory: $factory,
            dependencies: [],
            module: $this->module,
            lifetime: ServiceLifetime::SINGLETON,
        ));

        $this->state->recordValueService($id, new AutowireMetadata(
            serviceId: $id,
            className: get_debug_type($raw),
            dependencies: [],
            argumentPlan: [],
            module: $this->module,
            lifetime: ServiceLifetime::SINGLETON,
        ), $code);

        return $id;
    }

    /**
     * @param array<mixed> $value
     */
    private function assertExportableElements(array $value, string $context): void
    {
        array_walk_recursive($value, static function ($v) use ($context): void {
            if (is_scalar($v) || $v === null) {
                return;
            }
            $message = "{$context} contains a non-exportable element (" . get_debug_type($v) . ').';

            throw new InvalidConfigurationException($message);
        });
    }
}
