<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 *
 * Issue #55: instance-based read surface for the environment port.
 *
 * The interface carries ONLY the read API — typed accessors over process
 * environment state, no side effects, no cross-layer references — so it is
 * safe to place in the Domain layer next to the other ports
 * (SecurityPolicy and friends already read the process environment through
 * this surface).
 *
 * Method names carry the read- prefix because PHP forbids a class from
 * holding both a static method and an instance method with the same name:
 * Env keeps its historic static API (int/bool/string/csv, 38 call sites at
 * the time the issue was opened) AND implements this port with the
 * read-prefixed instance surface — one class, two calling conventions,
 * zero behavioural change to existing call sites.
 *
 * `Env` (same namespace) is the concrete implementing class; the kernel
 * composition root binds this port as an ordinary container service
 * (Adapters/Kernel/Application.php), next to
 * OtlpExporterFactoryInterface and ServiceRegistrarInterface. New
 * production code depends on the port, never on the concrete class.
 */

namespace Zef\Framework\Foundation;

/**
 * Typed, instance-based read surface for environment variables.
 *
 * Replaces inline getenv()/filter_var() parsing scattered across the
 * codebase; injectable at the composition root so tests and alternative
 * runtimes can substitute their own source of truth.
 */
interface EnvInterface
{
    /**
     * Read an integer env var, clamping to [$min, $max].
     * When $strict=true, throws if the value is set but invalid (like envIntRequired).
     */
    public function readInt(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int;

    public function readBool(string $name, bool $default = false): bool;

    /**
     * Read a boolean env var with STRICT, fail-closed parsing (audit #304):
     * recognized words map plainly, any other non-empty value throws instead
     * of silently mapping to false — one typo must never disable a security
     * control or flip a secure default.
     *
     * Recognized: 1/0, true/false, yes/no, on/off, enabled/disabled
     * (case-insensitive). Unset or blank falls back to $default.
     *
     * @throws \InvalidArgumentException when the value is set but unrecognized
     */
    public function readBoolStrict(string $name, bool $default = false): bool;

    public function readString(string $name, string $default = ''): string;

    /** @return list<string> */
    public function readCsv(string $name): array;
}
