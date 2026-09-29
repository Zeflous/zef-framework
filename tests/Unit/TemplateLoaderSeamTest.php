<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — TemplateLoader seam: zero-behaviour-change proof.
 *
 * The seam routes every scaffold template through TemplateLoader::render()
 * before it reaches ScaffoldWriter. This test pins the contract two ways:
 *
 *   1. render() is the IDENTITY function and is PURE (same in, same out).
 *   2. Every generator still writes BYTE-IDENTICAL output. The GOLDEN map
 *      below was captured by running all 12 generators against the
 *      pre-seam code at 4558b0e6 and hashing every produced file; a single
 *      changed byte anywhere in any scaffold fails this test.
 *
 * If a future change intentionally alters a scaffold, update GOLDEN in the
 * same commit and say so in the message — never silently.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Generator\AppGenerator;
use Zef\Framework\Console\Generator\CommandGenerator;
use Zef\Framework\Console\Generator\ConfigGenerator;
use Zef\Framework\Console\Generator\EntityGenerator;
use Zef\Framework\Console\Generator\HandlerGenerator;
use Zef\Framework\Console\Generator\MiddlewareGenerator;
use Zef\Framework\Console\Generator\ModuleGenerator;
use Zef\Framework\Console\Generator\PluginGenerator;
use Zef\Framework\Console\Generator\QueryGenerator;
use Zef\Framework\Console\Generator\RoadRunnerConfigGenerator;
use Zef\Framework\Console\Generator\ServiceGenerator;
use Zef\Framework\Console\Generator\ValueObjectGenerator;
use Zef\Framework\Console\ScaffoldWriter;
use Zef\Framework\Console\TemplateLoader;
use Zef\Test\Unit\HermeticConsoleIo;

/**
 * @internal
 */
final class TemplateLoaderSeamTest extends TestCase
{
    /**
     * sha256 of every file produced by the 12 generators, captured on the
     * pre-seam code (4558b0e6). Key = "<Generator>::<path relative to root>".
     */
    private const array GOLDEN = [
        'AppGenerator::.env.example' => 'sha256:2c5439884794e23eb5c940ceeb357940c23914ec896823a5158269c37416fac0',
        'AppGenerator::.rr.yaml' => 'sha256:d654b1a47894985e5e71164de5305a8c11a2f640c9a84df6710ab77ee1e68fa3',
        'AppGenerator::README.md' => 'sha256:0bcf938740b7b71592985c9c9bc7749e467b552f2d06ec8eb6b4111c845a5d50',
        'AppGenerator::app/Bootstrap.php' => 'sha256:3fd75b5b5fee8cebbf2b32148cafddcec6162c01aeff07b950ce4cb50e376f15',
        'AppGenerator::bin/worker.php' => 'sha256:2e51c18a0e32baf73d80eab0a839bc7f355a4d95102a1909406d0dfd88dda55c',
        'AppGenerator::bin/zef' => 'sha256:24504729d387a9a70a33ed662871585b5f7287dcc4dd65713e47d6f52de21853',
        'AppGenerator::composer.json' => 'sha256:d93872251f6c7e22823f00844c5bbdbe70be37bf2e57dd64e130f68fe4d1a69c',
        'AppGenerator::modules/Demo/ConfigProvider.php' => 'sha256:cb5d2e8965626b444d610a0cafd87f04be7bffff14c6b79e17b794bbe55bb773',
        'AppGenerator::modules/Demo/HomeHandler.php' => 'sha256:00b61ce49bc13bafc562263c241dd16d4c75192b03d0e9d4909ff74cf913eec0',
        'AppGenerator::public/index.php' => 'sha256:582df0443311be5dc142abfec5c6c6087a55db38c5320ed7d31a99401d2d2d35',
        'CommandGenerator::modules/Core/Command/CreateCommand.php' => 'sha256:522a313210ddaf73088f7dfc2bcff95479e3983f7b41fd672fdf46ca79d26dcb',
        'CommandGenerator::modules/Core/Command/CreateCommandHandler.php' => 'sha256:df57e442faf32cc8efe0582dd64470087d1714e158673c5414df1cdaa5dd0e16',
        'ConfigGenerator::modules/Core/BillingConfigProvider.php' => 'sha256:dfb218a29238622582b27a65b38d581552c66f619f1067e0e61bc98ca7eb7374',
        'EntityGenerator::modules/Core/Domain/Invoice.php' => 'sha256:6c3d3519d5ee73e39e3eef788a9fbb165eada9be69de24147e9e21dbbd367c91',
        'HandlerGenerator::modules/Core/PingHandler.php' => 'sha256:1c83efedd7ed982bc8b9b05ba7f9d95ffcc946f9a144b3b68e91418e528837d7',
        'MiddlewareGenerator::src/Middleware/AuthMiddleware.php' => 'sha256:06a662071c113181cb022e5b259b3ba46adb3e0d9b2e5b30f05af041c5e0998b',
        'ModuleGenerator::modules/Toko/ConfigProvider.php' => 'sha256:8903c5d8a3113078aa6d4932d4eaf5711a6343f19f6f8acabea90bf0749b9c0c',
        'ModuleGenerator::modules/Toko/HomeHandler.php' => 'sha256:55f8caa2d30497435abadf15f1eac53edc555337ae196f1b37878e2d0866285d',
        'PluginGenerator::plugins/Toko/ConfigProvider.php' => 'sha256:550c5351c64c60d61c812d568f711f4b4c81ed9bb7036fa671a3403b3dd2bb8d',
        'PluginGenerator::plugins/Toko/TokoHandler.php' => 'sha256:d0e3ba51d93fd02ded2616768f6738385f746b4f3112dae399dba9ffb2abf24b',
        'PluginGenerator::plugins/Toko/TokoService.php' => 'sha256:b025a8de024819f451de2358594241e82fd9499dbac35c03a78654fba4278ba0',
        'QueryGenerator::modules/Core/Query/ListOrdersQuery.php' => 'sha256:c8a36ad241888ff6c3a4d2e11287986e74916f93db34755c2099324d5537a7ee',
        'QueryGenerator::modules/Core/Query/ListOrdersQueryHandler.php' => 'sha256:5b186f19b82259cc473faef54eb8ffef4d3deedf2fb27f85519b05359b895d06',
        'RoadRunnerConfigGenerator::.rr.yaml' => 'sha256:39b7a5197ac8d688c2ab9de91c9df15979f018fee4908848f31b6a5d6ca576b4',
        'ServiceGenerator::modules/Core/MailerService.php' => 'sha256:30e45a1ecb39b1cb24b9dbd47c8bc9a9421d930a6aa047d0fcc1e55ac6de6f23',
        'ValueObjectGenerator::modules/Core/Domain/Email.php' => 'sha256:533cf7c653d33f747f9bc07d28b03cb8296382413ac4a2943e35395012b5753b',
    ];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rmRecursive($root);
        }
        $this->roots = [];
    }

    // ------------------------------------------------------------------
    // The seam itself
    // ------------------------------------------------------------------

    /** render() returns its input unchanged — the identity contract. */
    public function testRenderIsIdentity(): void
    {
        $loader = new TemplateLoader();

        foreach ([
            '',
            'plain',
            "<?php\n\nfinal class X {}\n",
            "line1\nline2\n",
            "trailing spaces   \n",
            "\tindented\n",
            "unicode — ✓\n",
        ] as $template) {
            self::assertSame($template, $loader->render($template));
        }
    }

    /** render() is pure: repeated calls on the same input agree. */
    public function testRenderIsPure(): void
    {
        $loader = new TemplateLoader();
        $template = "<?php\n\nnamespace A\\B;\n\nfinal class C {}\n";

        self::assertSame($loader->render($template), $loader->render($template));
        self::assertSame($template, $loader->render($template));
    }

    /** render() preserves byte length exactly (no trimming, no normalising). */
    public function testRenderPreservesByteLength(): void
    {
        $loader = new TemplateLoader();
        $template = "  leading\n\n\ttab\n  trailing  \n";

        self::assertSame(\strlen($template), \strlen($loader->render($template)));
    }

    // ------------------------------------------------------------------
    // Zero behaviour change across every generator
    // ------------------------------------------------------------------

    /** Every generator still produces byte-identical output. */
    public function testGeneratorOutputIsByteIdenticalToPreSeamBaseline(): void
    {
        $cases = [
            'ModuleGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new ModuleGenerator($r, $io, $w)->generate('Toko'),
            'PluginGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new PluginGenerator($r, $io, $w)->generate('Toko'),
            'EntityGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new EntityGenerator($r, $io, $w)->generate('Invoice', ['--module=core']),
            'ValueObjectGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new ValueObjectGenerator($r, $io, $w)->generate('Email', ['--module=core']),
            'CommandGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new CommandGenerator($r, $io, $w)->generate('Create', ['--module=core']),
            'QueryGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new QueryGenerator($r, $io, $w)->generate('ListOrders', ['--module=core']),
            'HandlerGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new HandlerGenerator($r, $io, $w)->generate('Ping', ['--module=core']),
            'MiddlewareGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new MiddlewareGenerator($r, $io, $w)->generate('Auth', ['--module=core']),
            'ConfigGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new ConfigGenerator($r, $io, $w)->generate('Billing', ['--module=core']),
            'ServiceGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new ServiceGenerator($r, $io, $w)->generate('Mailer', ['--module=core']),
            'RoadRunnerConfigGenerator' => static fn (string $r, ConsoleIO $io, ScaffoldWriter $w): int => new RoadRunnerConfigGenerator($r, $io, $w)->generate(null, ['--address=0.0.0.0:8080']),
        ];

        $actual = [];
        foreach ($cases as $label => $run) {
            $root = $this->sandbox();
            $io = HermeticConsoleIo::create();
            $writer = new ScaffoldWriter($io);

            self::assertSame(0, $run($root, $io, $writer), "{$label} must succeed");

            foreach ($this->hashTree($root) as $rel => $sha) {
                $actual[$label . '::' . $rel] = $sha;
            }
        }

        // AppGenerator scaffolds a standalone project outside the framework root.
        $appRoot = \sys_get_temp_dir() . '/zef-seam-app-' . \bin2hex(\random_bytes(6));
        $this->roots[] = $appRoot;
        $io = HermeticConsoleIo::create();
        $writer = new ScaffoldWriter($io);
        self::assertSame(0, new AppGenerator(\dirname(__DIR__, 2), $io, $writer)->generate($appRoot, ['--name=demo']));
        foreach ($this->hashTree($appRoot) as $rel => $sha) {
            $actual['AppGenerator::' . $rel] = $sha;
        }

        \ksort($actual);

        self::assertSame(\array_keys(self::GOLDEN), \array_keys($actual), 'the set of produced files must not change');
        self::assertSame(self::GOLDEN, $actual, 'every scaffold must be byte-identical to the pre-seam baseline');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function sandbox(): string
    {
        $root = \sys_get_temp_dir() . '/zef-seam-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($root . '/modules/Core', 0o777, true));
        $this->roots[] = $root;

        return $root;
    }

    /** @return array<string,string> relative path => sha256 */
    private function hashTree(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            // Normalise the separator: on Windows getPathname() yields
            // backslashes, which would make the golden keys platform-specific.
            $rel = \str_replace('\\', '/', \substr($path, \strlen($root) + 1));
            $content = (string) \file_get_contents($path);
            if ($rel === 'composer.json') {
                $content = $this->normalizeComposerJson($content);
            }
            $out[$rel] = 'sha256:' . \hash('sha256', $content);
        }
        \ksort($out);

        return $out;
    }

    /**
     * The scaffolded composer.json embeds a path-repository URL computed from
     * the LONGEST COMMON ANCESTOR of the target and the framework checkout, so
     * its bytes legitimately depend on WHERE the framework lives on disk. The
     * golden hash must not: normalise that single field to a fixed placeholder
     * so the test is hermetic across checkout locations (CI, git worktrees,
     * /tmp). Every other byte of composer.json is still pinned.
     */
    private function normalizeComposerJson(string $content): string
    {
        return (string) \preg_replace(
            '/("url"\s*:\s*)"[^"]*"/',
            '$1"<framework-ref>"',
            $content,
        );
    }

    private function rmRecursive(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $entries = \scandir($path);
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (\is_dir($full)) {
                $this->rmRecursive($full);
            } else {
                @unlink($full); // nosemgrep: php.lang.security.unlink-use
            }
        }
        \rmdir($path);
    }
}
