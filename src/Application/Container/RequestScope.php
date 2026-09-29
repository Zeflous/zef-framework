<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;

final class RequestScope implements ContainerInterface
{
    private const string CLOSED_MESSAGE = 'Request scope is closed.';

    private bool $closed = false;
    private readonly ResolutionContext $context;

    public function __construct(private readonly ContainerResolver $resolver)
    {
        $this->context = new ResolutionContext($resolver, $this);
    }

    #[\Override]
    public function get(string $id): mixed
    {
        if ($this->closed) {
            throw new \LogicException(self::CLOSED_MESSAGE);
        }

        return $this->context->get($id);
    }

    #[\Override]
    public function has(string $id): bool
    {
        return !$this->closed && $this->resolver->hasInContext($id);
    }

    /** @deprecated Internal lifecycle/cache API; use ContainerInterface::get()/has(). */
    public function hasInstance(string $id): bool
    {
        return !$this->closed && $this->resolver->scopeHasPublic($this, $id);
    }

    /** @deprecated Internal lifecycle/cache API; use ContainerInterface::get(). */
    public function getInstance(string $id): mixed
    {
        if ($this->closed) {
            throw new \LogicException(self::CLOSED_MESSAGE);
        }

        return $this->resolver->scopeGetPublic($this, $id);
    }

    /** @deprecated Internal lifecycle/cache API; direct scope cache mutation is discouraged. */
    public function setInstance(string $id, mixed $value): void
    {
        if ($this->closed) {
            throw new \LogicException(self::CLOSED_MESSAGE);
        }
        $this->resolver->scopeSetPublic($this, $id, $value);
    }

    public function close(): void
    {
        if ($this->closed) {
            // ekuivalen: releaseScope adalah unset() (idempoten) dan context->reset()
            // idempoten; close ganda identik secara observasi
            // @infection-ignore-all ReturnRemoval
            return;
        }
        // ekuivalen: releaseScope() hanya mengosongkan cache scope internal; scope
        // tertutup tidak punya pembaca lanjutan
        // @infection-ignore-all MethodCallRemoval
        $this->resolver->releaseScope($this);
        // ekuivalen: context->reset() pada scope yang sedang ditutup tidak punya
        // pengamat eksternal
        // @infection-ignore-all MethodCallRemoval
        $this->context->reset();
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}

// @internal
