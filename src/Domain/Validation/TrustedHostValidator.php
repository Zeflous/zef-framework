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
    public function __construct(private array $trustedHosts = []) {}

    /**
     * Reject a host that is not in the trusted allow-list.
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
        if ($host === '' || $this->trustedHosts === []) {
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
