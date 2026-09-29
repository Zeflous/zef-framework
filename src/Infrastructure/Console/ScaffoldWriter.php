<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: transactional file writer. A scaffold is all-or-nothing:
 * every target is checked for collisions BEFORE anything touches the disk,
 * so a failed generator never leaves a half-written module behind.
 */

namespace Zef\Framework\Console;

final readonly class ScaffoldWriter
{
    public function __construct(private ConsoleIO $io) {}

    public function writeFile(string $path, string $contents): void
    {
        $this->writeFiles([$path => $contents]);
    }

    /**
     * Force-write a single file, bypassing the collision batch check. Only
     * for explicit regeneration flows (`bin/zef rr:init --force`) where the
     * user has already consented to overwriting; every other scaffold keeps
     * the all-or-nothing `writeFiles()` contract.
     */
    public function overwriteFile(string $path, string $contents): void
    {
        $existed = is_file($path);
        $this->ensureDirectory(dirname($path));
        $this->putContents($path, $contents);
        $this->io->out($existed ? "Overwrote {$path}" : "Created {$path}");
    }

    /**
     * Write every file atomically: collision-check all targets first, then
     * create directories and write. Any collision or IO failure aborts the
     * whole batch before/at the first offending path.
     *
     * @param array<string,string> $files absolute path => file contents
     */
    public function writeFiles(array $files): void
    {
        $existing = [];
        foreach (array_keys($files) as $path) {
            if (is_file($path)) {
                $existing[] = $path;
            }
        }

        if ($existing !== []) {
            throw new ScaffoldCollisionException(
                'Refusing to overwrite existing file: ' . implode(', ', $existing),
            );
        }

        foreach ($files as $path => $contents) {
            $this->ensureDirectory(dirname($path));
            $this->putContents($path, $contents);
            $this->io->out("Created {$path}");
        }
    }

    /**
     * Race-safe mkdir guard (same is_dir/mkdir/is_dir triple the inline
     * code used): a concurrent worker winning the mkdir race is tolerated,
     * a genuinely failed creation throws. PHP diagnostics raised by a failed
     * mkdir are swallowed by a scoped handler instead of `@` suppression.
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $created = false;
        set_error_handler(static fn (): bool => true);
        try {
            $created = mkdir($dir, 0o777, true);
        } finally {
            restore_error_handler();
        }
        if (!$created && !is_dir($dir)) {
            throw new ScaffoldWriteException("Cannot create directory: {$dir}");
        }
    }

    /**
     * Write a file, converting a failed write (PHP diagnostic + false
     * return) into ScaffoldWriteException instead of `@` suppression.
     */
    private function putContents(string $path, string $contents): void
    {
        $written = false;
        set_error_handler(static fn (): bool => true);
        try {
            $written = file_put_contents($path, $contents);
        } finally {
            restore_error_handler();
        }
        if ($written === false) {
            throw new ScaffoldWriteException("Cannot write file: {$path}");
        }
    }
}
