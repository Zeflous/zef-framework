<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef plugin:list` — scans plugins/ on disk. Plugins
 * are plain ConfigProviders (the runtime does not tag them), so the
 * filesystem is the single source of truth for this listing.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;

final readonly class PluginLister
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
    ) {}

    public function run(): int
    {
        $base = "{$this->root}/plugins";
        $plugins = $this->scanPlugins($base);
        if ($plugins === []) {
            $this->io->out("No plugins found ({$base}).");

            return 0;
        }
        $this->renderPlugins($plugins);

        return 0;
    }

    /**
     * Scans plugins/ for plugin directories and their PHP files.
     *
     * @param string $base absolute plugins directory path
     *
     * @return array<string, list<string>> plugin name => contained .php files
     */
    private function scanPlugins(string $base): array
    {
        $entries = [];
        if (is_dir($base)) {
            $scanned = scandir($base);
            $entries = $scanned !== false ? $scanned : [];
        }

        $plugins = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = "{$base}/{$entry}";
            if (!is_dir($dir)) {
                continue;
            }
            // scandir() already returns entries sorted ascending (SCANDIR_SORT_ASCENDING).
            $plugins[$entry] = $this->phpFilesIn($dir);
        }

        return $plugins;
    }

    /** @return list<string> the .php files directly inside $dir */
    private function phpFilesIn(string $dir): array
    {
        $scanned = scandir($dir);
        if ($scanned === false) {
            return [];
        }
        $files = [];
        foreach ($scanned as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @param array<string, list<string>> $plugins */
    private function renderPlugins(array $plugins): void
    {
        $this->io->out(sprintf('%-16s %s', 'PLUGIN', 'FILES'));
        foreach ($plugins as $name => $files) {
            $listing = $files === [] ? '-' : implode(', ', $files);
            $this->io->out(sprintf('%-16s %s', $name, $listing));
        }
        $this->io->out('');
        $this->io->out(sprintf('%d plugin(s)', count($plugins)));
    }
}
