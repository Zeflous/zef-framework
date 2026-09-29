<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class InMemorySpanExporter implements SpanExporterInterface
{
    /**
     * @var list<SpanData>
     */
    private array $spans = [];

    #[\Override]
    public function export(array $spans): void
    {
        foreach ($spans as $span) {
            $this->spans[] = $span;
        }
    }

    #[\Override]
    public function shutdown(): void
    {
        // intentionally empty: the in-memory exporter has nothing to flush
        // or tear down — spans stay readable via spans() until reset().
    }

    /** @return list<SpanData> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function reset(): void
    {
        $this->spans = [];
    }
}
