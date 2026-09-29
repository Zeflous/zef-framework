<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring value objects).

namespace Zef\Framework\Autowiring;

use Zef\Framework\Container\ServiceLifetime;

/**
 * Complete instantiation plan for one autowired service.
 *
 * `$dependencies` is the authoritative, ordered list of service IDs (real
 * services and synthetic `@value:*` services alike) that lands in
 * ServiceDefinition::$dependencies — giving DependencyGraphValidator and
 * ContainerCompiler the full graph before validateAndFreeze() runs.
 *
 * `$argumentPlan` describes how to build the constructor call from the
 * resolved dependency values, one entry per emitted argument:
 *   - ['dep', int $index]            positional dependency value ($d{index})
 *   - ['literal', string $phpCode]   baked literal (var_export'ed value or constant name)
 * A variadic service parameter emits one 'dep' entry per collected service.
 */
final readonly class AutowireMetadata
{
    /**
     * @param list<string> $dependencies
     * @param list<array{0:'dep'|'literal', 1:int|string}> $argumentPlan
     */
    public function __construct(
        public string $serviceId,
        public string $className,
        public array $dependencies,
        public array $argumentPlan,
        public ?string $module = null,
        public string $lifetime = ServiceLifetime::SINGLETON,
    ) {
        $this->assertDependenciesAreNonEmptyStrings($dependencies);
        $this->assertArgumentPlanIsWellFormed($dependencies, $argumentPlan);
    }

    /**
     * @param list<string> $dependencies
     */
    private function assertDependenciesAreNonEmptyStrings(array $dependencies): void
    {
        foreach ($dependencies as $dep) {
            if (!is_string($dep) || $dep === '') {
                throw new \InvalidArgumentException(
                    "Autowire metadata for '{$this->serviceId}': dependencies must be non-empty strings."
                );
            }
        }
    }

    /**
     * @param list<string>                              $dependencies
     * @param list<array{0:'dep'|'literal', 1:int|string}> $argumentPlan
     */
    private function assertArgumentPlanIsWellFormed(array $dependencies, array $argumentPlan): void
    {
        foreach ($argumentPlan as $entry) {
            $this->assertPlanEntryIsShaped($entry);
            if ($entry[0] === 'dep') {
                $this->assertPlanEntryDep($entry, $dependencies);
            } else {
                $this->assertPlanEntryLiteral($entry);
            }
        }
    }

    private function assertPlanEntryIsShaped(mixed $entry): void
    {
        if (
            !is_array($entry)
            || !isset($entry[0], $entry[1])
            || ($entry[0] !== 'dep' && $entry[0] !== 'literal')
        ) {
            throw new \InvalidArgumentException(
                "Autowire metadata for '{$this->serviceId}': malformed argument plan entry."
            );
        }
    }

    /**
     * @param array{0:'dep'|'literal', 1:int|string} $entry
     * @param list<string>                           $dependencies
     */
    private function assertPlanEntryDep(array $entry, array $dependencies): void
    {
        if (!is_int($entry[1]) || $entry[1] < 0 || !isset($dependencies[$entry[1]])) {
            throw new \InvalidArgumentException(
                "Autowire metadata for '{$this->serviceId}': argument plan references unknown dependency index."
            );
        }
    }

    /**
     * @param array{0:'dep'|'literal', 1:int|string} $entry
     */
    private function assertPlanEntryLiteral(array $entry): void
    {
        if (!is_string($entry[1])) {
            throw new \InvalidArgumentException(
                "Autowire metadata for '{$this->serviceId}': literal argument must be PHP code."
            );
        }
    }
}
