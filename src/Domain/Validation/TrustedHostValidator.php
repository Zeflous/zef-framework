<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

final readonly class TrustedHostValidator
{
    public function __construct(
        private array $trustedHosts = [],
        /** Audit #331: opt-in fail-closed posture for compositions that REQUIRE pinning. */
        private bool $failClosedOnEmptyList = false,
    ) {
        // Reject malformed allow-list entries up front: a non-string element
        // would otherwise reach the `(string) $allowed` cast below, emitting
        // an "Array to string conversion" warning (fatal under
        // failOnWarning=true) or an uncaught \Error for objects (issues
        // #278 / #281). Failing loudly here keeps the cast total.
        foreach ($trustedHosts as $allowed) {
            if (!is_string($allowed) && !$allowed instanceof \Stringable) {
                throw new \InvalidArgumentException(
                    'Trusted host entries must be strings, got ' . get_debug_type($allowed) . '.',
                );
            }
        }
    }

    /**
     * Reject a host that is not in the trusted allow-list.
     *
     * Audit #331: the two early-outs are DELIBERATE defaults, now explicit:
     * an empty allow-list means pinning is not configured (Uri relies on
     * this for every relative reference) — compositions that require
     * pinning opt in via failClosedOnEmptyList and fail loudly instead of
     * silently trusting every Host header; an empty host likewise stays
     * accepted here because the ingress path (RequestFactory) rejects an
     * empty request Host on its own before this validator ever runs.
     *
     * Named `assertTrusted()` rather than `assert()` on purpose: a bare
     * `assert()` call reads as the PHP built-in assertion function, which
     * static analysers (Snyk Code, and any rule keyed on the `assert`
     * symbol) treat as code execution. This method never executes code —
     * it only compares strings and throws — so the misleading name was
     * both a readability hazard and a false-positive generator.
     */
    public function assertTrusted(string $host): void
    {
        if ($this->trustedHosts === []) {
            if ($this->failClosedOnEmptyList) {
                throw new \InvalidArgumentException(
                    'Trusted host allow-list is empty — host-header pinning is misconfigured.',
                );
            }

            return;
        }
        if ($host === '') {
            return;
        }
        $normalize = static function (string $value): string {
            $value = strtolower(trim($value));
            if (strlen($value) >= 2 && $value[0] === '[' && $value[strlen($value) - 1] === ']') {
                return substr($value, 1, -1);
            }

            return $value;
        };
        $normalized = $normalize($host);
        foreach ($this->trustedHosts as $allowed) {
            if ($normalized === $normalize((string) $allowed)) {
                return;
            }
        }

        throw new \InvalidArgumentException("Untrusted host: {$host}.");
    }
}
