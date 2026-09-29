<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class CorrelationPropagator
{
    /**
     * @param array<string, null|bool|float|int|string> $attributes
     */
    public static function extract(
        ?string $traceParent,
        ?string $traceState,
        string $operationId,
        ?string $idempotencyKey = null,
        array $attributes = [],
    ): ?CorrelationContext {
        $value = self::normalizedTraceParent($traceParent, $traceState);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $value, $m) !== 1) {
            return null;
        }

        try {
            return new CorrelationContext(
                strtolower($m[1]),
                strtolower($m[2]),
                strtolower($m[3]),
                $traceState,
                $operationId,
                $idempotencyKey,
                $attributes,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function inject(?CorrelationContext $context): ?CorrelationHeaders
    {
        if (!$context instanceof CorrelationContext) {
            return null;
        }

        return new CorrelationHeaders($context->traceParent(), $context->traceState);
    }

    public static function disabled(): null
    {
        return null;
    }

    /**
     * Applies the traceparent/tracestate size and shape guards and returns
     * the trimmed traceparent value, or null when the input is rejected.
     */
    private static function normalizedTraceParent(?string $traceParent, ?string $traceState): ?string
    {
        if ($traceParent === null || strlen($traceParent) > CorrelationContext::MAX_TRACEPARENT_BYTES) {
            return null;
        }
        if ($traceState !== null && strlen($traceState) > CorrelationContext::MAX_TRACESTATE_BYTES) {
            return null;
        }
        $value = trim($traceParent);
        if (strlen($value) !== CorrelationContext::MAX_TRACEPARENT_BYTES) {
            return null;
        }

        return $value;
    }
}
