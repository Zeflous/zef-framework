<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * `bin/zef doctor`: read-only environment preflight. Every check reports
 * OK / WARN / FAIL with an actionable detail line; the command exits 1 only
 * when at least one FAIL is present, so warnings can be triaged without
 * breaking scripts.
 *
 * The app-boot smoke check is the heart of the tool: it answers "can this
 * checkout actually boot?" before the developer wastes time on a hang or a
 * 500. The boot itself is injected as a closure by the bin/zef composition
 * root — Infrastructure must not depend on the App layer — so the Doctor
 * stays layer-clean (Deptrac: Infrastructure never depends on App) and the
 * check degrades to a documented skip in sandbox roots (tests).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;

final readonly class Doctor
{
    /** Boot smoke check only runs when one of these exists under $root. */
    private const array AUTOLOAD_CANDIDATES = ['vendor/autoload.php', 'autoload/zef_autoload.php'];

    /**
     * RoadRunner bridge class, probed by name: a string constant keeps the
     * vendor package out of the source-level dependency graph (see the
     * roadRunnerChecks() note).
     */
    private const string RR_WORKER_CLASS = 'Spiral\RoadRunner\Http\PSR7Worker';

    /** RoadRunner configuration file checked by roadRunnerChecks(). */
    private const string RR_CONFIG_FILE = '.rr.yaml';

    /** Label of the app-boot smoke check row. */
    private const string APP_BOOT_LABEL = 'app boot';

    /**
     * Boot probe injected by the bin/zef composition root; returns a detail
     * string or throws on failure.
     *
     * @var null|callable(): string
     */
    private mixed $bootSmoke;

    public function __construct(
        private string $root,
        private ConsoleIO $io,
        ?callable $bootSmoke = null,
    ) {
        $this->bootSmoke = $bootSmoke;
    }

    /** @return int 0 when no FAIL-level finding, 1 otherwise. */
    public function run(): int
    {
        $findings = [
            ...$this->phpChecks(),
            ...$this->filesystemChecks(),
            ...$this->roadRunnerChecks(),
            ...$this->bootCheck(),
        ];

        $this->io->out('ZEF doctor — environment preflight');
        $this->io->out(sprintf('Root: %s', $this->root));
        $this->io->out('');
        foreach ($findings as [$status, $label, $detail]) {
            $this->io->out(sprintf('  [%s] %-24s %s', $status, $label, $detail));
        }

        $fail = count(array_filter($findings, static fn (array $f): bool => $f[0] === 'FAIL'));
        $warn = count(array_filter($findings, static fn (array $f): bool => $f[0] === 'WARN'));
        $ok = count($findings) - $fail - $warn;
        $this->io->out('');
        $this->io->out(sprintf('%d ok, %d warn, %d fail', $ok, $warn, $fail));

        return $fail === 0 ? 0 : 1;
    }

    /** @return list<array{string,string,string}> */
    private function phpChecks(): array
    {
        $phpVersion = version_compare(PHP_VERSION, '8.4.0', '>=')
            ? ['OK', 'PHP', PHP_VERSION]
            : ['FAIL', 'PHP', sprintf('%s < 8.4 — ZEF requires PHP >= 8.4', PHP_VERSION)];

        $checks = [$phpVersion];

        $checks[] = extension_loaded('mbstring')
            ? ['OK', 'ext-mbstring', 'loaded']
            : ['FAIL', 'ext-mbstring', 'missing — composer require implies mbstring (apt/yum install php-mbstring)'];

        $checks[] = extension_loaded('posix')
            ? ['OK', 'ext-posix', 'loaded (tinker TTY detection works)']
            : ['WARN', 'ext-posix', 'missing — tinker falls back to TERM/stream checks'];

        $checks[] = extension_loaded('pcov')
            ? ['OK', 'ext-pcov', 'loaded (coverage + mutation gates runnable)']
            : ['WARN', 'ext-pcov', 'missing — PHPUnit coverage / Infection will not work'];

        $checks[] = extension_loaded('redis')
            ? ['OK', 'ext-redis', 'loaded (Redis-backed stores reachable)']
            : ['WARN', 'ext-redis', 'missing — Redis-backed stores/tests will skip themselves'];

        return $checks;
    }

    /** @return list<array{string,string,string}> */
    private function filesystemChecks(): array
    {
        $autoload = array_find(
            self::AUTOLOAD_CANDIDATES,
            fn ($candidate): bool => is_file("{$this->root}/{$candidate}"),
        );
        $checks = [];
        $checks[] = $autoload !== null
            ? ['OK', 'autoloader', $autoload]
            : ['FAIL', 'autoloader', 'neither vendor/autoload.php nor autoload/zef_autoload.php found'];

        $entrypoints = ['bin/worker.php' => 'RR worker entrypoint', 'public/index.php' => 'web entrypoint'];
        foreach ($entrypoints as $file => $label) {
            $checks[] = is_file("{$this->root}/{$file}")
                ? ['OK', $label, $file]
                : ['WARN', $label, "{$file} not found — RR/dev-server entry limited"];
        }

        return $checks;
    }

    /** @return list<array{string,string,string}> */
    private function roadRunnerChecks(): array
    {
        $checks = [];

        // Vendor presence probe — deliberately a string, not a class token:
        // every other Spiral reference in the tree lives inside scaffold
        // templates (strings), so this must not become the first real
        // vendor import in src/ either; deptrac keeps the source tree at
        // zero uncovered vendor edges (composer deptrac fails on them).
        $checks[] = class_exists(self::RR_WORKER_CLASS)
            ? ['OK', 'RR bridge', 'spiral/roadrunner-http installed']
            : ['WARN', 'RR bridge', 'missing — composer require spiral/roadrunner-http nyholm/psr7'];

        $rrBinary = $this->locateRrBinary();
        $checks[] = $rrBinary !== null
            ? ['OK', 'RR binary', $rrBinary]
            : ['WARN', 'RR binary', 'not found — download from https://docs.roadrunner.dev (docs/general/install)'];

        $rrYaml = $this->root . '/' . self::RR_CONFIG_FILE;
        if (!is_file($rrYaml)) {
            $checks[] = ['WARN', self::RR_CONFIG_FILE, 'missing — run bin/zef rr:init to generate one'];
        } else {
            $contents = (string) file_get_contents($rrYaml);
            $hasSections = str_contains($contents, 'version:') && str_contains($contents, 'server:');
            $invalidDetail = 'present but missing version/server sections — re-run bin/zef rr:init --force';
            $checks[] = $hasSections
                ? ['OK', self::RR_CONFIG_FILE, 'present with version + server sections']
                : ['WARN', self::RR_CONFIG_FILE, $invalidDetail];
        }

        return $checks;
    }

    private function locateRrBinary(): ?string
    {
        foreach (['vendor/bin/rr', 'bin/rr'] as $candidate) {
            $path = "{$this->root}/{$candidate}";
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $path = rtrim($dir, '/') . '/rr';
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Boot smoke: run the injected boot probe; must not throw. Skipped when
     * the inspected root has no autoloader (test sandboxes) or when the
     * composition root injected no probe, reported as a documented skip.
     *
     * @return list<array{string,string,string}>
     */
    private function bootCheck(): array
    {
        $hasAutoload = array_any(
            self::AUTOLOAD_CANDIDATES,
            fn (string $candidate): bool => is_file("{$this->root}/{$candidate}"),
        );
        if (!$hasAutoload || $this->bootSmoke === null) {
            return [['OK', self::APP_BOOT_LABEL, 'skipped (no autoloader in inspected root or no boot probe)']];
        }

        try {
            $detail = ($this->bootSmoke)();

            return [[
                'OK',
                self::APP_BOOT_LABEL,
                is_string($detail) ? $detail : 'boot probe completed',
            ]];
        } catch (\Throwable $e) {
            return [['FAIL', self::APP_BOOT_LABEL, sprintf('%s: %s', $e::class, $e->getMessage())]];
        }
    }
}
