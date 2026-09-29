<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Infrastructure -> Domain with the same
 * FQN and namespace (classmap + PSR-4 multi-directory both resolve it),
 * so the 38 static call sites across layers are untouched. The class is
 * a pure typed reader over process environment state — no side effects,
 * no cross-layer references — matching the getenv()/filter_var() calls
 * the Domain layer already performs directly (see SecurityPolicy).
 *
 * Issue #55: the read-prefixed instance methods below implement
 * EnvInterface (same namespace), bound by the kernel composition root
 * as an ordinary container service. PHP forbids static and instance
 * methods sharing one name, so the historic static surface
 * (`Env::int(...)`, 38 call sites at the time the issue was opened)
 * keeps its exact signatures and bodies — the instance surface delegates
 * to them — until the opportunistic per-module migration emptied src/
 * (38 -> 0 call sites). Step 4 then deprecated the static facade: every
 * static method now carries @deprecated plus an @-suppressed
 * E_USER_DEPRECATED notice (silent for PHPUnit/self-test by design,
 * captured by the guard test) and the surface is scheduled for removal
 * in v3.0 (see CHANGELOG-v2.28.0.md, issue #55 step 4).
 */

namespace Zef\Framework\Foundation;

/**
 * Typed helpers for reading environment variables.
 * Replaces inline getenv() parsing scattered across the codebase.
 *
 * Two calling conventions over one implementation: the read-prefixed
 * instance methods are the EnvInterface port — the supported surface for
 * new production code since v2.28.0 — while the static methods are the
 * historic facade, now @deprecated (runtime E_USER_DEPRECATED, removal
 * in v3.0). Zero static state either way; the instance surface is the
 * canonical implementation of the port.
 */
final class Env implements EnvInterface
{
    /**
     * Read an integer env var, clamping to [$min, $max].
     * When $strict=true, throws if the value is set but invalid (like envIntRequired).
     *
     * @deprecated since v2.28.0 — inject EnvInterface and call readInt();
     *             the static facade will be removed in v3.0.
     */
    public static function int(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int {
        @trigger_error(
            'Env::int() is deprecated since v2.28.0 and will be removed in v3.0. Inject EnvInterface and call readInt() instead.',
            E_USER_DEPRECATED,
        );

        return new self()->readInt($name, $default, $min, $max, $strict);
    }

    /**
     * @deprecated since v2.28.0 — inject EnvInterface and call readBool();
     *             the static facade will be removed in v3.0.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        @trigger_error(
            'Env::bool() is deprecated since v2.28.0 and will be removed in v3.0. Inject EnvInterface and call readBool() instead.',
            E_USER_DEPRECATED,
        );

        return new self()->readBool($name, $default);
    }

    /**
     * @deprecated since v2.28.0 — inject EnvInterface and call readString();
     *             the static facade will be removed in v3.0.
     */
    public static function string(string $name, string $default = ''): string
    {
        @trigger_error(
            'Env::string() is deprecated since v2.28.0 and will be removed in v3.0. Inject EnvInterface and call readString() instead.',
            E_USER_DEPRECATED,
        );

        return new self()->readString($name, $default);
    }

    /**
     * @deprecated since v2.28.0 — inject EnvInterface and call readCsv();
     *             the static facade will be removed in v3.0.
     *
     * @return list<string>
     */
    public static function csv(string $name): array
    {
        @trigger_error(
            'Env::csv() is deprecated since v2.28.0 and will be removed in v3.0. Inject EnvInterface and call readCsv() instead.',
            E_USER_DEPRECATED,
        );

        return new self()->readCsv($name);
    }

    // ---------------------------------------------- EnvInterface surface

    /**
     * Read an integer env var, clamping to [$min, $max].
     * When $strict=true, throws if the value is set but invalid (like envIntRequired).
     */
    #[\Override]
    public function readInt(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }
        $v = (int) $raw;
        if ($strict && ($v < $min || $v > $max)) {
            throw new \InvalidArgumentException($name . ' is outside its allowed range.');
        }

        return max($min, min($max, $v));
    }

    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        $raw = getenv($name);

        return ($raw === false || $raw === '') ? $default : $raw;
    }

    /** @return list<string> */
    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(trim(...), explode(',', $raw)),
                static fn (string $v): bool => $v !== '',
            )
        );
    }
}
