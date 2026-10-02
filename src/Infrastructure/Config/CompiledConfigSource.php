<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * Loads a configuration file produced by {@see ConfigCompiler}: a pure-PHP
 * file returning the validated value tree. Production boots then skip all
 * source parsing, environment reads and secret lookups.
 *
 * Load-time hardening (issue #355 C-5) — the file is EXECUTED PHP that boots
 * straight into the value tree, so this source refuses to trust it blindly:
 *
 * 1. PERMISSIONS (POSIX): a group/world-WRITABLE compiled file — or one
 *    sitting in a world-writable directory — is an arbitrary-code-execution
 *    vector any local user can exploit by rewriting the file. Unlike the
 *    data-only secrets file (#250, warn-only) this check FAILS the load.
 * 2. INTEGRITY ENVELOPE: the file must carry the compiler's stamp —
 *    framework `version`, SHA-256 `fingerprint` of the value tree, and the
 *    `values`. A version mismatch means the file was produced by another
 *    framework build; a fingerprint mismatch means it was edited, corrupted
 *    or truncated; a plain (pre-envelope) array means it predates the stamp.
 *    All three refuse the load with a recompile instruction — a stale or
 *    tampered tree is never served silently.
 */
final readonly class CompiledConfigSource implements ConfigSourceInterface
{
    public function __construct(
        private string $path,
        private ?string $name = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name ?? 'compiled:' . basename($this->path);
    }

    #[\Override]
    public function load(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' does not exist or is not readable."
            );
        }
        $this->refuseLoosePermissions();
        $values = $this->includeFile();

        return $this->verifyEnvelope($values);
    }

    /**
     * POSIX-only permission gate: the compiled file is require()d, so write
     * access for other principals equals remote-free local code execution.
     * Windows keeps the process default ACL (the POSIX bits are meaningless
     * there — same carve-out as FileSecretsProvider, issue #250).
     */
    private function refuseLoosePermissions(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $filePerms = fileperms($this->path);
        if ($filePerms !== false && ($filePerms & 0o002) !== 0) {
            $mode = substr(sprintf('%o', $filePerms), -4);
            $reason = 'it is executed as PHP, a local code-injection vector — restrict it to 0600';

            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' is world-writable (mode {$mode}); {$reason}.",
            );
        }
        $directory = dirname($this->path);
        $dirPerms = is_dir($directory) ? fileperms($directory) : false;
        if ($dirPerms !== false && ($dirPerms & 0o002) !== 0) {
            $reason = 'any local user could replace the executed file — restrict the directory to 0700';

            throw new InvalidConfigurationException(
                "Compiled config directory '{$directory}' is world-writable; {$reason}.",
            );
        }
    }

    /**
     * @return mixed the raw `require` result (validated by verifyEnvelope)
     */
    private function includeFile(): mixed
    {
        try {
            // Plain require (not require_once): load() may run repeatedly for the
            // same file within one process (reload / re-merge). require_once would
            // return `true` after the first include and break every later read.
            return require $this->path;
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' failed to load: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Envelope verification — see the class docblock. Returns the inner
     * value tree after confirming the version stamp, the content fingerprint
     * and top-level associativity.
     *
     * @return array<string,mixed>
     */
    private function verifyEnvelope(mixed $values): array
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' must return an associative array."
            );
        }
        $enveloped = count($values) === 3
            && isset($values['version'], $values['fingerprint'], $values['values']);
        if (!$enveloped) {
            $reason = 'Recompile it with ConfigCompiler before use.';

            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' predates the integrity envelope (pre-v2.36 format). {$reason}",
            );
        }
        if ($values['version'] !== ZefVersion::VERSION) {
            $stamp = is_string($values['version']) ? $values['version'] : get_debug_type($values['version']);
            $detail = "was compiled by framework version '{$stamp}', but this is " . ZefVersion::VERSION;

            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' {$detail}. Recompile it with ConfigCompiler.",
            );
        }
        if (!is_array($values['values']) || ($values['values'] !== [] && array_is_list($values['values']))) {
            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' must return an associative value tree."
            );
        }
        $expected = hash('sha256', serialize($values['values']));
        if (!is_string($values['fingerprint']) || !hash_equals($expected, $values['fingerprint'])) {
            $detail = 'it was edited, corrupted or truncated';

            throw new InvalidConfigurationException(
                "Compiled config file '{$this->path}' failed its integrity fingerprint — recompile ({$detail}).",
            );
        }

        return $values['values']; // @phpstan-ignore return.type (assoc. validated above; key types undecidable)
    }
}
