<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Behaviour knobs of the OpenAPI runtime gate.
 *
 * - strictQuery (default false): reject query parameters the matched
 *   operation does not declare (boundary B6). Off by default because the
 *   zero-attribute document declares no query parameters at all — the
 *   extractor derives path parameters from route constraints, query/header
 *   parameters only appear via #[Parameter] attributes — so a strict
 *   default would reject every query string on every documented route.
 * - validateResponses (default false): enforce the operation's response
 *   contract (boundary B11) — undocumented status, undeclared media type
 *   and off-schema JSON bodies become a fail-closed 500 problem+json.
 *   Opt-in: it buffers and validates every JSON response body.
 *
 * Headers and cookies are transport surfaces, not API contract, and are
 * therefore never rejected for being undeclared (non-goal #2 of the parity
 * matrix) — there is no strictHeaders knob by design.
 */
final readonly class OpenApiGateOptions
{
    public function __construct(
        public bool $strictQuery = false,
        public bool $validateResponses = false,
    ) {}
}
