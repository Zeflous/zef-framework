<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;

/**
 * @internal
 */
final class ResolutionContext implements ContainerInterface
{
    private array $loading = [];

    public function __construct(
        private readonly ContainerResolver $resolver,
        private readonly ?RequestScope $scope,
    ) {}

    #[\Override]
    public function get(string $id): mixed
    {
        return $this->resolver->resolveInContext($id, $this, $this->scope);
    }

    #[\Override]
    public function has(string $id): bool
    {
        return $this->resolver->hasInContext($id);
    }

    public function push(string $id): void
    {
        if (count($this->loading) >= $this->resolver->maxResolutionDepth()) {
            throw new InvalidConfigurationException('Dependency resolution depth exceeds configured safety budget.');
        }
        if (isset($this->loading[$id])) {
            $chain = array_keys($this->loading);
            $chain[] = $id;

            throw new ServiceCircularDependencyException($chain);
        }
        // @infection-ignore-all TrueValue — ekuivalen: isset() hanya membaca kunci; nilai tak dibaca
        $this->loading[$id] = true;
    }

    public function pop(string $id): void
    {
        unset($this->loading[$id]);
    }

    public function reset(): void
    {
        $this->loading = [];
    }
}

// Typed, immutable service configuration.
