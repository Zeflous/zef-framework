<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Boot-time failure of the OpenAPI runtime gate (boundary B12 of
 * docs/OPENAPI-GATE-PARITY.md).
 *
 * The gate refuses to start on a document that does not pass
 * {@see OpenApiSpecValidator::validate()} — a spec that is structurally
 * broken cannot be enforced per-request, so the failure is fail-closed at
 * construction time (never a per-request error).
 */
final class OpenApiGateException extends OpenApiException
{
    /**
     * @param list<string> $errors the validator's deterministic error list
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
