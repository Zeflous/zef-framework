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

    /** @var list<string> stack of singleton ids currently being instantiated (innermost last) */
    private array $singletonStack = [];

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

    /**
     * v2.35.0: lifetime-aware push. Pass the definition's lifetime so the
     * context can track the enclosing singleton subtree — the runtime
     * implicit-capture guard relies on it. Callers without a definition
     * (legacy/internal) default to SINGLETON, preserving previous behaviour.
     */
    public function push(string $id, ?string $lifetime = null): void
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
        if (($lifetime ?? ServiceLifetime::SINGLETON) === ServiceLifetime::SINGLETON) {
            $this->singletonStack[] = $id;
        }
    }

    public function pop(string $id, ?string $lifetime = null): void
    {
        unset($this->loading[$id]);
        if (($lifetime ?? ServiceLifetime::SINGLETON) === ServiceLifetime::SINGLETON
            && $this->singletonStack !== []
            && end($this->singletonStack) === $id
        ) {
            array_pop($this->singletonStack);
        }
    }

    /** True while a singleton instantiation subtree is open on this context. */
    public function insideSingleton(): bool
    {
        return $this->singletonStack !== [];
    }

    /** The innermost singleton currently being instantiated (null otherwise). */
    public function currentSingleton(): ?string
    {
        return $this->singletonStack === [] ? null : (string) $this->singletonStack[count($this->singletonStack) - 1];
    }

    public function reset(): void
    {
        $this->loading = [];
        $this->singletonStack = [];
    }
}

// Typed, immutable service configuration.
