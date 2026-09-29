<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class NoopSpan implements SpanInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    #[\Override]
    public function getContext(): SpanContext
    {
        return SpanContext::invalid();
    }

    #[\Override]
    public function setAttribute(string $key, mixed $value): self
    {
        return $this;
    }

    #[\Override]
    public function setAttributes(array $attributes): self
    {
        return $this;
    }

    #[\Override]
    public function addEvent(string $name, array $attributes = []): self
    {
        return $this;
    }

    #[\Override]
    public function setStatus(string $status, ?string $description = null): self
    {
        // Status transitions are discarded exactly like every other
        // attribute write on a no-op span; delegate to the no-op writer.
        return $this->setAttribute($status, $description);
    }

    #[\Override]
    public function end(?int $endNs = null): void
    {
        // Intentionally empty: a no-op span records nothing, so ending it
        // has no observable effect. The parameter is kept by the interface.
    }

    #[\Override]
    public function isEnded(): bool
    {
        return true;
    }
}
