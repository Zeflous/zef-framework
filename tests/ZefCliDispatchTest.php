<?php

declare(strict_types=1);

/*
 * ZEF Framework — CLI dispatcher contract test.
 *
 * `tests/ZefCliContractTest.php` guards the `--self-test` contract. This file
 * additionally guards the *dispatcher* itself through real subprocess runs, so
 * the CLI surface documented in `docs/CLI.md` stays true:
 *
 *   - `make:*` and `list` reach the maker (the documented generator entrypoint),
 *   - `tinker` reaches the REPL (regression guard: the helper existed but was
 *     never wired into the dispatcher, so `bin/zef tinker` used to die with
 *     "unknown command" while being documented in README and CLI.md),
 *   - the production guard on tinker keeps refusing without an explicit
 *     `--force`, because a REPL executes arbitrary code.
 */

namespace Zef\Test;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ZefCliDispatchTest extends TestCase
{
    /** `bin/zef list` — katalog generator terdokumentasi harus terdaftar. */
    public function testListPrintsTheDocumentedGenerators(): void
    {
        [$exitCode, $output] = $this->runCli('list');

        self::assertSame(0, $exitCode, "bin/zef list must exit 0:\n{$output}");

        foreach (['make:module', 'make:plugin', 'make:handler', 'make:middleware', 'make:config'] as $command) {
            self::assertStringContainsString($command, $output, "generator {$command} must be listed");
        }
    }

    /** Dispatcher `make:*` — nama invalid wajib keluar non-zero, bukan menulis berkas rusak. */
    public function testMakeRejectsInvalidIdentifier(): void
    {
        [$exitCode, $output] = $this->runCli('make:module 3Bad');

        self::assertNotSame(0, $exitCode, 'an invalid identifier must not exit 0');
        self::assertStringContainsString('Invalid module name', $output);
    }

    /** Dispatcher `tinker -e` — REPL harus benar-benar terhubung dan mengevaluasi ekspresi. */
    public function testTinkerEvaluatesExpressionThroughTheDispatchedRepl(): void
    {
        [$exitCode, $output] = $this->runCli('tinker ' . \escapeshellarg('-e') . ' ' . \escapeshellarg('echo 40 + 2;'));

        self::assertSame(0, $exitCode, "tinker -e must exit 0:\n{$output}");
        self::assertStringContainsString('42', $output);
    }

    /** `tinker` tanpa boot tetap menyediakan REPL (mode `--no-boot`). */
    public function testTinkerSupportsNoBootMode(): void
    {
        [$exitCode, $output] = $this->runCli(
            'tinker ' . \escapeshellarg('-e') . ' '
            . \escapeshellarg('echo $container instanceof \Psr\Container\ContainerInterface ? "container-ok" : "no-container";')
            . ' --no-boot'
        );

        self::assertSame(0, $exitCode, "tinker --no-boot must exit 0:\n{$output}");
        self::assertStringContainsString(
            'container-ok',
            $output,
            'docs/CLI.md §5 promises $container in --no-boot mode ("hanya container berdiri sendiri") — issue #211'
        );
    }

    /** Guard produksi: REPL menolak berjalan saat ZEF_ENV=production. */
    public function testTinkerRefusesInProductionWithoutForce(): void
    {
        [$exitCode, $output] = $this->runCli(
            'tinker ' . \escapeshellarg('-e') . ' ' . \escapeshellarg('echo 1;'),
            ['ZEF_ENV' => 'production']
        );

        self::assertSame(1, $exitCode, "production guard must exit 1:\n{$output}");
        self::assertStringContainsString('--force', $output);
    }

    /** Guard produksi dapat di-*override* secara eksplisit lewat `--force`. */
    public function testTinkerForceOverridesProductionGuard(): void
    {
        [$exitCode, $output] = $this->runCli(
            'tinker ' . \escapeshellarg('-e') . ' ' . \escapeshellarg('echo 7;') . ' --force',
            ['ZEF_ENV' => 'production']
        );

        self::assertSame(0, $exitCode, "tinker --force must exit 0:\n{$output}");
        self::assertStringContainsString('7', $output);
    }

    /** Command tak dikenal tetap keluar non-zero dan menyebut katalog. */
    public function testUnknownCommandIsRejected(): void
    {
        [$exitCode, $output] = $this->runCli('bukan-command');

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unknown command', $output);
    }

    /** `bin/zef doctor` — preflight harus exit 0 pada checkout sehat. */
    public function testDoctorRunsThroughTheDispatcher(): void
    {
        [$exitCode, $output] = $this->runCli('doctor');

        self::assertSame(0, $exitCode, "doctor must exit 0 on a healthy checkout:\n{$output}");
        self::assertStringContainsString('ZEF doctor — environment preflight', $output);
        self::assertStringContainsString('[OK] app boot', $output, 'boot probe wajib ter-inject dari composition root');
    }

    /** `bin/zef rr:init` — collision-safe: menolak menimpa .rr.yaml tanpa --force. */
    public function testRrInitRefusesCollisionThroughTheDispatcher(): void
    {
        [$exitCode, $output] = $this->runCli('rr:init');

        self::assertNotSame(0, $exitCode, 'rr:init tanpa --force harus menolak .rr.yaml eksisting');
        self::assertStringContainsString('Use --force to regenerate', $output);
    }

    /** `bin/zef make:app` — dispatcher harus meneruskan ke generator (usage error, bukan unknown command). */
    public function testMakeAppReachesTheGeneratorThroughTheDispatcher(): void
    {
        [$exitCode, $output] = $this->runCli('make:app');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Usage: bin/zef make:app', $output, 'tanpa path harus usage error dari generator');
        self::assertStringNotContainsString('Unknown command', $output);
    }

    /**
     * Run `bin/zef` as a real subprocess.
     *
     * @param array<string, string> $extraEnv
     *
     * @return array{0: int, 1: string}
     */
    private function runCli(string $arguments, array $extraEnv = []): array
    {
        $bin = __DIR__ . '/../bin/zef';

        self::assertFileExists($bin);

        // `VAR=value cmd` is a POSIX-only shell prefix — cmd.exe parses it as
        // a command NAME ("'ZEF_ENV' is not recognized..."). Inject the env
        // through putenv() instead: the child inherits the current process
        // environment, which is portable, and the previous values are
        // restored on the way out (issue #110).
        $restore = [];
        foreach ($extraEnv as $name => $value) {
            $restore[$name] = \getenv($name);
            \putenv("{$name}={$value}");
        }

        try {
            $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($bin) . ' ' . $arguments . ' 2>&1';

            $lines = [];
            $exitCode = 0;
            exec($cmd, $lines, $exitCode); // nosemgrep: exec-use

            return [$exitCode, \implode("\n", $lines)];
        } finally {
            foreach ($restore as $name => $previous) {
                \putenv($previous === false ? $name : "{$name}={$previous}");
            }
        }
    }
}
