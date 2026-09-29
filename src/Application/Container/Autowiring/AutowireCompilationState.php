<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (autowiring compile pass).
 *
 * Mutable accumulator shared by the autowiring collaborators during ONE
 * AutowireCompilerPass::process() run: the metadata / factory-code maps and
 * the generated / reused / value-service id lists that become the final
 * AutowireResult. Extracted from AutowireCompilerPass in the sonar-zero
 * campaign (behavior-preserving split).
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireResult;

final class AutowireCompilationState
{
    /**
     * @var array<string,AutowireMetadata>
     */
    private array $metadata = [];

    /**
     * @var array<string,string>
     */
    private array $factoryCode = [];

    /**
     * @var list<string>
     */
    private array $generatedIds = [];

    /**
     * @var list<string>
     */
    private array $reusedIds = [];

    /**
     * @var list<string>
     */
    private array $valueServiceIds = [];

    public function reset(): void
    {
        $this->metadata = [];
        $this->factoryCode = [];
        $this->generatedIds = [];
        $this->reusedIds = [];
        $this->valueServiceIds = [];
    }

    /**
     * Marks an already-registered service as reused (left untouched by the
     * pass). Ids already tracked as generated or reused are not duplicated.
     */
    public function recordReused(string $class): void
    {
        if (in_array($class, $this->reusedIds, true) || in_array($class, $this->generatedIds, true)) {
            return;
        }
        $this->reusedIds[] = $class;
    }

    /** Records a freshly generated class definition. */
    public function recordGenerated(string $class, AutowireMetadata $metadata, string $code): void
    {
        $this->metadata[$class] = $metadata;
        $this->factoryCode[$class] = $code;
        $this->generatedIds[] = $class;
    }

    /** Records a freshly generated synthetic `@value:*` service. */
    public function recordValueService(string $id, AutowireMetadata $metadata, string $code): void
    {
        $this->metadata[$id] = $metadata;
        $this->factoryCode[$id] = $code;
        $this->generatedIds[] = $id;
        $this->valueServiceIds[] = $id;
    }

    public function toResult(): AutowireResult
    {
        return new AutowireResult(
            $this->metadata,
            $this->factoryCode,
            $this->generatedIds,
            $this->reusedIds,
            $this->valueServiceIds,
        );
    }
}
