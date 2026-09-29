<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Application layer: orchestration,
 * use-case services). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * Writes the validated configuration to a pure-PHP file for production
 * boots: {@see CompiledConfigSource} then loads it with zero source parsing,
 * zero environment reads and zero secret lookups.
 *
 * Export is atomic (temp file + rename) and refuses values that cannot be
 * represented — only scalars, nulls and arrays of those ever reach the file.
 *
 * SECURITY (since v2.21.1): the compiled file bakes RESOLVED values in —
 * every `%secret:name%` reference becomes plaintext on disk. The file is
 * therefore written with restrictive permissions (`$fileMode`, default
 * `0600`, applied to the temp file BEFORE the rename so no world-readable
 * window exists) and carries a header reminding operators to keep it out of
 * version control. The recommended target is `var/cache/config.php`, which
 * ships in `.gitignore`.
 */
final readonly class ConfigCompiler
{
    public function __construct(private int $fileMode = 0o600)
    {
        if ($fileMode < 0 || ($fileMode & ~0o777) !== 0) {
            throw new \InvalidArgumentException(
                'Config compile file mode must be a permission mask between 0 and 0777, got '
                . $fileMode . '.'
            );
        }
    }

    public function export(Config $config, string $targetFile): void
    {
        $directory = dirname($targetFile);
        $basename = basename($targetFile);
        if (in_array($basename, ['', '.', '..'], true)) {
            throw new InvalidConfigurationException("Invalid config compile target '{$targetFile}'.");
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InvalidConfigurationException(
                "Config compile target directory is not writable: '{$directory}'."
            );
        }
        $values = $config->all();
        $unexportable = self::firstUnexportable($values, '');
        if ($unexportable !== null) {
            throw new InvalidConfigurationException(
                "Config value at '{$unexportable}' cannot be compiled (only scalars, nulls and arrays are exportable)."
            );
        }
        $code = "<?php\n\ndeclare(strict_types=1);\n\n/* Compiled application configuration (ZEF Framework v"
            . ZefVersion::VERSION
            . '). Do not edit. Contains resolved secrets — keep out of version control, chmod 600. */'
            . "\n\n"
            . 'return ' . var_export($values, true) . ";\n";
        $tmp = $directory . '/.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $code) === false) {
            throw new InvalidConfigurationException("Failed to write compiled config temp file '{$tmp}'.");
        }
        // The Unix mode contract is POSIX-only: PHP's Windows emulation
        // rewrites the DACL and masks without owner-write (e.g. the boundary
        // mode 0) revoke the DELETE right, which breaks the atomic rename
        // publish itself (issue #110). On Windows the compiled file keeps
        // the process default ACL and the publish stays atomic.
        if (DIRECTORY_SEPARATOR === '/') {
            // Best-effort mode application: a failed chmod leaves the
            // restrictive process default in place and the atomic publish
            // below still runs (the historical behaviour).
            chmod($tmp, $this->fileMode);
        }
        if (!rename($tmp, $targetFile)) {
            // Cleanup of $tmp, a name this method generated itself
            // ('.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp'). No
            // request input reaches the argument; this runs only when the rename
            // immediately above failed. Registered as an accepted suppression:
            // docs/security/php-sast.md §7.
            if (is_file($tmp)) {
                unlink($tmp); // nosemgrep: php.lang.security.unlink-use
            }

            throw new InvalidConfigurationException("Failed to publish compiled config '{$targetFile}'.");
        }
    }

    /**
     * @param array<array-key,mixed> $values
     */
    private static function firstUnexportable(array $values, string $prefix): ?string
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $nested = self::firstUnexportable($value, $path);
                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                return $path;
            }
        }

        return null;
    }
}
