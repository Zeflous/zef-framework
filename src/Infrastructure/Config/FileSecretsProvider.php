<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Psr\Log\LoggerInterface;

/**
 * File-backed secrets provider — one plain file per secret inside a single
 * directory, the Docker / Kubernetes secret-mount convention.
 *
 * The key grammar (`^[a-z0-9][a-z0-9._-]{0,127}$`, no `..`, no slashes)
 * makes path traversal structurally impossible; a key outside the grammar
 * is simply unknown. File contents are trimmed of surrounding whitespace.
 * Empty files are valid secrets (empty string), distinct from unknown keys
 * (null).
 *
 * ZEF-DX-10 (issue #250): read-side permission hygiene. The write side has
 * long enforced 0600 on compiled configs (#172 P-20); the read side now
 * warns — never throws, so sandboxes/tests/CI never break — through an
 * optional PSR-3 sink when a secrets file is group/world-readable or
 * world-writable, or when the directory itself is world-writable. On
 * Windows the POSIX permission bits are not meaningful, so the check is
 * skipped there.
 */
final readonly class FileSecretsProvider implements SecretsProviderInterface
{
    private const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,127}$/';

    public function __construct(
        private string $directory,
        private ?LoggerInterface $logger = null,
    ) {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Secrets directory '{$directory}' does not exist.");
        }
        $this->warnOnWorldWritableDirectory($directory);
    }

    #[\Override]
    public function get(string $key): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || str_contains($key, '..')) {
            return null;
        }
        $file = $this->directory . '/' . $key;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $this->warnOnLooseFilePermissions($file, $key);
        $contents = file_get_contents($file);

        return $contents === false ? null : trim($contents);
    }

    /**
     * A world-writable secrets directory lets any local user REPLACE a
     * secret wholesale — the strongest escalation of the three conditions,
     * so it is checked once at construction.
     */
    private function warnOnWorldWritableDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !$this->logger instanceof LoggerInterface) {
            return;
        }
        $perms = fileperms($directory);
        if ($perms === false || ($perms & 0o002) === 0) {
            return;
        }
        $this->logger->warning(sprintf(
            'Secrets directory %s is world-writable (mode %s); restrict it to 0700.',
            $directory,
            $this->modeString($perms),
        ));
    }

    /**
     * Group/world-readable or world-writable secret files expose the values
     * to other local users. WARN, do not throw: a wrong chmod on a host is
     * an operational posture defect the operator must see, not a reason to
     * take the application down.
     */
    private function warnOnLooseFilePermissions(string $file, string $key): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !$this->logger instanceof LoggerInterface) {
            return;
        }
        $perms = fileperms($file);
        if ($perms === false) {
            return;
        }
        if (($perms & 0o077) === 0) {
            return; // owner-only: the expected shape
        }
        $this->logger->warning(sprintf(
            "Secret file for key '%s' is readable or writable beyond its owner (mode %s at %s); restrict it to 0600.",
            $key,
            $this->modeString($perms),
            $file,
        ));
    }

    private function modeString(int $perms): string
    {
        return '0' . decoct($perms & 0o777);
    }
}
