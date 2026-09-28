<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Generator\AppGenerator;
use Zef\Framework\Console\Generator\RoadRunnerConfigGenerator;
use Zef\Framework\Console\Inspector\Doctor;
use Zef\Framework\Console\ScaffoldWriter;
use Zef\Framework\Console\ZefMaker;
use Zef\Framework\Foundation\ZefVersion;

/**
 * v2.29.0 DX release — kurikulum edge-case adversarial untuk tiga tool baru:
 *
 *   - AppGenerator        (`bin/zef make:app <path>`)
 *   - RoadRunnerConfigGenerator (`bin/zef rr:init`)
 *   - Doctor              (`bin/zef doctor`)
 *
 * Satu test = satu kelompok mutan yang wajib dibunuh. Fokus utama:
 * keselamatan path (normalisasi `..`, penolakan target di dalam root),
 * kebenaran path-repo composer (longest common ancestor), determinisme
 * precedensi flag > env > default, dan kontrak exit-code Doctor
 * (1 hanya ketika ada FAIL).
 *
 * @internal
 */
final class EdgeMatrixDxToolsTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    /** @var null|array{resource, resource} */
    private ?array $streams = null;

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rmRecursive($root);
        }
        $this->roots = [];
    }

    // ------------------------------------------------------------------
    // AppGenerator — make:app
    // ------------------------------------------------------------------

    /** Rencana biru lengkap: 8 berkas, composer.json benar, skeleton PHP-valid. */
    public function testMakeAppScaffoldsASelfSufficientSkeleton(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $target = $ws . '/outside/demo';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))
            ->generate($target)
        ;

        self::assertSame(0, $exit, $this->streamContents(1));

        foreach ([
            'composer.json', '.env.example', '.rr.yaml', 'README.md',
            'public/index.php', 'bin/worker.php', 'bin/zef',
            'app/Bootstrap.php', 'modules/Demo/ConfigProvider.php', 'modules/Demo/HomeHandler.php',
        ] as $file) {
            self::assertFileExists("{$target}/{$file}", "{$file} wajib ada di skeleton");
        }

        $composer = $this->readJson("{$target}/composer.json");
        self::assertSame('demo/app', $composer['name'] ?? null);
        $require = $composer['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame('^' . ZefVersion::VERSION, $require['mbetixz/zef-framework'] ?? null, 'constraint framework harus mengikuti rilis');
        self::assertSame('^8.4', $require['php'] ?? null);
        $repos = $composer['repositories'] ?? null;
        self::assertIsArray($repos);
        $repo = $repos[0] ?? null;
        self::assertIsArray($repo);
        self::assertSame('path', $repo['type'] ?? null, 'framework dirujuk lewat path repository');

        // Regresi #210: path-repo tanpa pin `versions` di-resolve Composer sebagai
        // `dev-main`, sehingga constraint bertag tidak pernah cocok dan
        // `composer install` gagal pada scaffold baru.
        $options = $repo['options'] ?? null;
        self::assertIsArray($options, 'path repository wajib punya options');
        $versions = $options['versions'] ?? null;
        self::assertIsArray($versions, 'options path-repo wajib punya peta versions');
        $pinned = $versions['mbetixz/zef-framework'] ?? null;
        self::assertSame(ZefVersion::VERSION, $pinned, 'pin versions path-repo wajib = ZefVersion::VERSION (issue #210)');

        // Skeleton wajib PHP-valid — bukti "boots, serves and passes PHPStan".
        foreach (['app/Bootstrap.php', 'modules/Demo/ConfigProvider.php', 'modules/Demo/HomeHandler.php', 'public/index.php', 'bin/worker.php', 'bin/zef'] as $file) {
            exec('php -l ' . escapeshellarg("{$target}/{$file}") . ' 2>&1', $out, $code); // nosemgrep: exec-use
            self::assertSame(0, $code, "{$file} harus lolos php -l: " . implode("\n", $out));
        }
    }

    /** Path-repo sibling: target di folder induk yang sama → '../<framework>'. */
    public function testMakeAppComputesSiblingFrameworkRef(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $target = $ws . '/sibling-app';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))->generate($target);

        self::assertSame(0, $exit, $this->streamContents(1));
        self::assertSame('../framework', $this->pathRepoUrl($target));
    }

    /**
     * Regresi mutan basename: framework bersarang di dalam root sendiri
     * (common ancestor BUKAN parent-nya) wajib menghasilkan tanjakan penuh
     * dari leluhur bersama — `../../vendor/lib/zef` — bukan sekadar basename
     * `../../zef` yang resolve ke direktori salah.
     */
    public function testMakeAppComputesDeepNestedFrameworkRefFromCommonAncestor(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/vendor/lib/zef';
        self::assertTrue(mkdir($root, 0o777, true));
        $target = $ws . '/apps/demo';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))->generate($target);

        self::assertSame(0, $exit, $this->streamContents(1));
        self::assertSame(
            '../../vendor/lib/zef',
            $this->pathRepoUrl($target),
            'path-repo harus menuruni seluruh komponen dari leluhur bersama',
        );
    }

    /** Regresi containment: `../sib` di luar root sah — tidak boleh salah ditolak. */
    public function testMakeAppAllowsEscapingRelativeTargets(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))->generate('../sib');

        self::assertSame(0, $exit, $this->streamContents(1));
        self::assertFileExists($ws . '/sib/composer.json');
    }

    /**
     * POSIX mutation pin (issue #110): a backslash is a legal FILENAME
     * character on Unix, never a separator — the scaffold target `../we\ird`
     * must create a directory literally named `we\ird`, not `we/ird`. Kills
     * the platform-gate mutant that would swap separators on POSIX.
     */
    public function testMakeAppKeepsBackslashLiteralOnPosix(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows treats backslash as the path separator.');
        }
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))->generate('../we\ird', ['--name=demo']);

        self::assertSame(0, $exit, $this->streamContents(1));
        self::assertFileExists($ws . '/we\ird/composer.json');
        self::assertFileDoesNotExist($ws . '/we/ird/composer.json');
    }

    /** `sub/../modules/x` yang setelah normalisasi TETAP di dalam root → ditolak. */
    public function testMakeAppRejectsDisguisedInsideRootTargets(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))
            ->generate('sub/../modules/hidden')
        ;

        self::assertSame(1, $exit);
        self::assertStringContainsString('INSIDE the framework root', $this->streamContents(1));
        self::assertFileDoesNotExist($root . '/modules/hidden/composer.json');
    }

    /** Target absolut di dalam root → ditolak, pesan eksak. */
    public function testMakeAppRejectsAbsoluteInsideRootTargets(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))
            ->generate($root . '/modules/evil')
        ;

        self::assertSame(1, $exit);
        self::assertStringContainsString('INSIDE the framework root', $this->streamContents(1));
        self::assertFileDoesNotExist($root . '/modules/evil/composer.json');
    }

    /** Direktori non-kosong / berkas biasa → ditolak tanpa menulis apa pun. */
    public function testMakeAppRejectsNonEmptyAndFileTargets(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        mkdir($ws . '/full', 0o777, true);
        file_put_contents($ws . '/full/occupied.txt', 'x');
        file_put_contents($ws . '/plain-file.txt', 'x');
        $io = $this->io();
        $generator = new AppGenerator($root, $io, new ScaffoldWriter($io));

        self::assertSame(1, $generator->generate($ws . '/full'));
        self::assertStringContainsString('exists and is not empty', $this->streamContents(1));

        self::assertSame(1, $generator->generate($ws . '/plain-file.txt'));
        self::assertStringContainsString('is a file, not a directory', $this->streamContents(1));
    }

    /** Direktori kosong yang sudah ada → boleh dipakai sebagai target. */
    public function testMakeAppAcceptsAnEmptyExistingDirectory(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        mkdir($ws . '/empty-target', 0o777, true);
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))->generate($ws . '/empty-target');

        self::assertSame(0, $exit, $this->streamContents(1));
        self::assertFileExists($ws . '/empty-target/composer.json');
    }

    /** Argumen path hilang / kosong → usage error exit 1, tanpa efek samping. */
    public function testMakeAppRequiresAPathArgument(): void
    {
        $root = $this->workspace() . '/framework';
        $io = $this->io();
        $generator = new AppGenerator($root, $io, new ScaffoldWriter($io));

        self::assertSame(1, $generator->generate(null));
        self::assertStringContainsString('Usage: bin/zef make:app', $this->streamContents(1));

        self::assertSame(1, $generator->generate('   '));
        self::assertSame(1, $generator->generate(null, ['--path=']));
    }

    /** --name invalid dan --address invalid → exit 1 dengan pesan eksak. */
    public function testMakeAppValidatesProjectNameAndAddress(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();
        $generator = new AppGenerator($root, $io, new ScaffoldWriter($io));

        self::assertSame(1, $generator->generate($ws . '/a1', ['--name=9bad']));
        self::assertStringContainsString("Invalid project name '9bad'", $this->streamContents(1));

        self::assertSame(1, $generator->generate($ws . '/a2', ['--address=nonsense']));
        self::assertStringContainsString("Invalid --address 'nonsense'", $this->streamContents(1));

        self::assertFileDoesNotExist($ws . '/a1/composer.json');
        self::assertFileDoesNotExist($ws . '/a2/composer.json');
    }

    /** --address valid (IPv4 + IPv6) diteruskan ke .rr.yaml, composer, dan .env. */
    public function testMakeAppPropagatesTheAddressIntoArtifacts(): void
    {
        $ws = $this->workspace();
        $root = $ws . '/framework';
        $io = $this->io();

        $exit = new AppGenerator($root, $io, new ScaffoldWriter($io))
            ->generate($ws . '/addr-app', ['--address=[::1]:9000', '--name=my_app'])
        ;

        self::assertSame(0, $exit, $this->streamContents(1));
        $target = $ws . '/addr-app';
        self::assertStringContainsString('address: [::1]:9000', (string) file_get_contents("{$target}/.rr.yaml"));
        self::assertStringContainsString('ZEF_HTTP_ADDRESS=[::1]:9000', (string) file_get_contents("{$target}/.env.example"));
        self::assertStringContainsString('-S [::1]:9000', (string) file_get_contents("{$target}/composer.json"));

        // --name=my_app dinormalisasi menjadi kebab-case untuk nama proyek.
        $composer = $this->readJson("{$target}/composer.json");
        self::assertSame('my-app/app', $composer['name'] ?? null);
    }

    /** make:app masuk katalog maker dan dispatch-nya terhubung. */
    public function testMakeAppJoinsTheMakerCatalog(): void
    {
        $catalog = new ZefMaker($this->sandbox(), $this->io())->catalog();

        self::assertArrayHasKey('make:app', $catalog);
        self::assertSame('make:app <path> [--name=<project>] [--address=host:port]', $catalog['make:app']['usage']);
        self::assertStringContainsString('standalone ZEF app', $catalog['make:app']['desc']);
    }

    // ------------------------------------------------------------------
    // RoadRunnerConfigGenerator — rr:init
    // ------------------------------------------------------------------

    /** Tulis pertama: berkas utuh dengan nilai default 4/0/512. */
    public function testRrInitWritesTheFullConfigWithDefaults(): void
    {
        $root = $this->sandbox();
        $io = $this->io();

        $exit = new RoadRunnerConfigGenerator($root, $io, new ScaffoldWriter($io))->generate(null);

        self::assertSame(0, $exit, $this->streamContents(1));
        $yaml = (string) file_get_contents("{$root}/.rr.yaml");
        self::assertStringContainsString('version: "2025.1"', $yaml);
        self::assertStringContainsString('command: "php bin/worker.php"', $yaml);
        self::assertStringContainsString('address: 0.0.0.0:8080', $yaml);
        self::assertStringContainsString('num_workers: 4', $yaml);
        self::assertStringContainsString('max_jobs: 0', $yaml);
        self::assertStringContainsString('max_worker_memory: 512', $yaml);
        self::assertStringContainsString('Generated by: bin/zef rr:init', $yaml);
    }

    /** Tanpa --force, berkas eksisting tidak pernah tertimpa (collision-safe). */
    public function testRrInitRefusesToOverwriteWithoutForce(): void
    {
        $root = $this->sandbox();
        file_put_contents("{$root}/.rr.yaml", 'custom: hand-edited');
        $io = $this->io();
        $generator = new RoadRunnerConfigGenerator($root, $io, new ScaffoldWriter($io));

        self::assertSame(1, $generator->generate(null));
        self::assertStringContainsString('Use --force to regenerate', $this->streamContents(1));
        self::assertSame('custom: hand-edited', (string) file_get_contents("{$root}/.rr.yaml"), 'isi manual wajib utuh');
    }

    /** --force menimpa, dan flag menang atas env yang menang atas default. */
    public function testRrInitForceOverwritesAndFlagBeatsEnvBeatsDefault(): void
    {
        $root = $this->sandbox();
        file_put_contents("{$root}/.rr.yaml", 'custom: hand-edited');
        $io = $this->io();
        $generator = new RoadRunnerConfigGenerator($root, $io, new ScaffoldWriter($io));

        putenv('ZEF_RR_NUM_WORKERS=5');
        putenv('ZEF_HTTP_ADDRESS=127.0.0.1:7001');

        try {
            // Tanpa flag: env menang atas default (workers=5, address dari env).
            self::assertSame(0, $generator->generate(null, ['--force']));
            $yaml = (string) file_get_contents("{$root}/.rr.yaml");
            self::assertStringContainsString('num_workers: 5', $yaml);
            self::assertStringContainsString('address: 127.0.0.1:7001', $yaml);

            // Dengan flag: flag menang atas env (workers=8, address dari flag).
            self::assertSame(0, $generator->generate(null, ['--force', '--workers=8', '--address=127.0.0.1:7002', '--max-jobs=200', '--memory=256']));
            $yaml = (string) file_get_contents("{$root}/.rr.yaml");
            self::assertStringContainsString('num_workers: 8', $yaml);
            self::assertStringContainsString('address: 127.0.0.1:7002', $yaml);
            self::assertStringContainsString('max_jobs: 200', $yaml);
            self::assertStringContainsString('max_worker_memory: 256', $yaml);
        } finally {
            putenv('ZEF_RR_NUM_WORKERS');
            putenv('ZEF_HTTP_ADDRESS');
        }
    }

    /** Validasi input: address busuk, workers di luar rentang → exit 1. */
    public function testRrInitValidatesInputs(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $generator = new RoadRunnerConfigGenerator($root, $io, new ScaffoldWriter($io));

        self::assertSame(1, $generator->generate(null, ['--address=ghost']));
        self::assertStringContainsString("Invalid --address 'ghost'", $this->streamContents(1));

        self::assertSame(1, $generator->generate(null, ['--workers=0']));
        self::assertStringContainsString("Invalid --workers '0'", $this->streamContents(1));

        self::assertSame(1, $generator->generate(null, ['--workers=2000']));
        self::assertStringContainsString("Invalid --workers '2000'", $this->streamContents(1));

        self::assertFileDoesNotExist("{$root}/.rr.yaml", 'tidak ada berkas yang tertulis saat validasi gagal');
    }

    // ------------------------------------------------------------------
    // Doctor — bin/zef doctor
    // ------------------------------------------------------------------

    /** Root sehat + probe boot sukses → exit 0 tanpa FAIL. */
    public function testDoctorReportsOkAndExitsZeroOnAHealthyRoot(): void
    {
        $root = $this->healthySandbox();
        $io = $this->io();

        $exit = new Doctor($root, $io, static fn (): string => 'Application booted (ZEF v2.29.0)')->run();

        $output = $this->streamContents(0);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('[OK] autoloader', $output);
        self::assertStringContainsString('[OK] app boot', $output);
        self::assertStringContainsString('Application booted (ZEF v2.29.0)', $output);
        self::assertStringContainsString('0 fail', $output);
    }

    /** Autoloader hilang → FAIL, exit 1 (kontrak: exit 1 hanya karena FAIL). */
    public function testDoctorFailsWhenTheAutoloaderIsMissing(): void
    {
        $root = $this->sandbox();
        $io = $this->io();

        $exit = new Doctor($root, $io)->run();

        $output = $this->streamContents(0);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('[FAIL] autoloader', $output);
        self::assertStringContainsString('neither vendor/autoload.php', $output);
    }

    /** Probe boot melempar → FAIL app boot, exit 1, detail kelas+pesan. */
    public function testDoctorFailsWhenTheBootProbeThrows(): void
    {
        $root = $this->healthySandbox();
        $io = $this->io();

        $exit = new Doctor($root, $io, static function (): string {
            throw new \RuntimeException('container exploded');
        })->run();

        $output = $this->streamContents(0);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('[FAIL] app boot', $output);
        self::assertStringContainsString('RuntimeException: container exploded', $output);
    }

    /** Tanpa probe (atau tanpa autoloader) → skip terdokumentasi, bukan error. */
    public function testDoctorSkipsTheBootCheckDocumented(): void
    {
        $root = $this->healthySandbox();
        $io = $this->io();

        // Root sehat tapi tanpa probe → skip eksplisit, exit 0.
        $exit = new Doctor($root, $io)->run();
        $output = $this->streamContents(0);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('skipped (no autoloader in inspected root or no boot probe)', $output);
    }

    /** .rr.yaml malformed (tanpa version/server) → WARN, bukan FAIL. */
    public function testDoctorWarnsOnAMalformedRrYaml(): void
    {
        $root = $this->healthySandbox();
        file_put_contents("{$root}/.rr.yaml", 'logs: {mode: production}');
        $io = $this->io();

        $exit = new Doctor($root, $io)->run();

        $output = $this->streamContents(0);
        self::assertSame(0, $exit, $output . ' — WARN tidak boleh mengubah exit code');
        self::assertStringContainsString('[WARN] .rr.yaml', $output);
        self::assertStringContainsString('re-run bin/zef rr:init --force', $output);
    }

    /** .rr.yaml hilang → WARN dengan ajakan rr:init (bukan FAIL). */
    public function testDoctorWarnsWhenRrYamlIsMissing(): void
    {
        $root = $this->healthySandbox();
        @unlink("{$root}/.rr.yaml"); // nosemgrep: php.lang.security.unlink-use
        $io = $this->io();

        $exit = new Doctor($root, $io)->run();

        $output = $this->streamContents(0);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('run bin/zef rr:init to generate one', $output);
    }

    /** Entry point RR/web hilang → WARN terpisah, exit tetap 0. */
    public function testDoctorWarnsOnMissingEntrypoints(): void
    {
        $root = $this->healthySandbox();
        @unlink("{$root}/bin/worker.php"); // nosemgrep: php.lang.security.unlink-use
        @unlink("{$root}/public/index.php"); // nosemgrep: php.lang.security.unlink-use
        $io = $this->io();

        $exit = new Doctor($root, $io)->run();

        $output = $this->streamContents(0);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('[WARN] RR worker entrypoint', $output);
        self::assertStringContainsString('[WARN] web entrypoint', $output);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** Extract repositories[0].url dari composer.json hasil scaffold. */
    private function pathRepoUrl(string $target): string
    {
        $composer = $this->readJson($target . '/composer.json');
        $repos = $composer['repositories'] ?? null;
        self::assertIsArray($repos);
        $first = $repos[0] ?? null;
        self::assertIsArray($first);
        $url = $first['url'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    private function io(): ConsoleIO
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $this->streams = [$out, $err];

        return new ConsoleIO($out, $err);
    }

    private function streamContents(int $index): string
    {
        self::assertNotNull($this->streams, 'io() must be called first');
        $stream = $this->streams[$index];
        rewind($stream);
        $content = stream_get_contents($stream);
        self::assertIsString($content);

        return $content;
    }

    private function sandbox(): string
    {
        $root = sys_get_temp_dir() . '/zef-dx-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root, 0o777, true));
        $this->roots[] = $root;

        return $root;
    }

    /**
     * Workspace untuk make:app: framework root berada di `<ws>/framework`,
     * sehingga target scaffold sah dapat berada di `<ws>/...` (di luar root).
     */
    private function workspace(): string
    {
        $ws = sys_get_temp_dir() . '/zef-dx-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($ws . '/framework', 0o777, true));
        $this->roots[] = $ws;

        return $ws;
    }

    /** Root "sehat" minimal untuk Doctor: autoloader, entrypoints, .rr.yaml. */
    private function healthySandbox(): string
    {
        $root = $this->sandbox();
        mkdir("{$root}/vendor", 0o777, true);
        touch("{$root}/vendor/autoload.php");
        mkdir("{$root}/bin", 0o777, true);
        touch("{$root}/bin/worker.php");
        mkdir("{$root}/public", 0o777, true);
        touch("{$root}/public/index.php");
        file_put_contents("{$root}/.rr.yaml", "version: \"2025.1\"\nserver:\n  command: \"php bin/worker.php\"\n");

        return $root;
    }

    private function rmRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rmRecursive($full);
            } else {
                unlink($full); // nosemgrep: php.lang.security.unlink-use
            }
        }
        rmdir($path);
    }
}
