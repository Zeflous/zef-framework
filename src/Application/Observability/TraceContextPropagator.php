<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class TraceContextPropagator
{
    /**
     * Parses a `traceparent` header into a {@see SpanContext}, or returns
     * null when the header cannot be trusted (grammar mismatch, zero ids).
     *
     * P-23 (issue #172): per W3C Trace Context, only bit 0 of the flags
     * octet is defined (sampled); vendors may emit future/flow bits in the
     * remaining 7. Dropping the whole context on any reserved bit would
     * discard a perfectly valid trace, so unknown bits are IGNORED — the
     * flags are masked down to the sampled bit and propagation continues
     * with the sanitized value (inject() re-emits `01`/`00`).
     */
    public static function extract(string $traceParent, ?string $traceState = null): ?SpanContext
    {
        $value = trim($traceParent);
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $value, $m) !== 1) {
            return null;
        }
        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }
        $flags = hexdec($m[3]);

        return new SpanContext(strtolower($m[1]), strtolower($m[2]), ($flags & 1) === 1, $traceState);
    }

    public static function inject(SpanContext $context): string
    {
        return $context->traceParent();
    }
}
