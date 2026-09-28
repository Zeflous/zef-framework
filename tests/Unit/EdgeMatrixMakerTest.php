<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Inspector\ConfigShower;
use Zef\Framework\Console\Inspector\ModuleLister;
use Zef\Framework\Console\Inspector\PluginLister;
use Zef\Framework\Console\InvalidNameException;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldCollisionException;
use Zef\Framework\Console\ScaffoldWriteException;
use Zef\Framework\Console\ScaffoldWriter;
use Zef\Framework\Console\ZefMaker;

/**
 * v2.16.0 — ZEF Maker: kurikulum edge-case adversarial untuk fondasi CLI
 * (naming rules, IO port, transactional writer, dispatcher, inspector).
 * Satu test = satu kelompok mutan yang wajib dibunuh.
 *
 * @internal
 */
final class EdgeMatrixMakerTest extends TestCase
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
    // NamingRules — validasi + konversi identifier
    // ------------------------------------------------------------------

    /** NamingRules::className — identifier valid diterima apa adanya. */
    public function testClassNameAcceptsValidIdentifiers(): void
    {
        self::assertSame('HandleOrder', NamingRules::className('HandleOrder'));
        self::assertSame('_private', NamingRules::className('_private'));
        self::assertSame('A', NamingRules::className('A'));
        self::assertSame('n4m3_x', NamingRules::className('n4m3_x'));
    }

    /** NamingRules::className:22-27 — null/kosong/malformed wajib ditolak, label ikut di pesan. */
    public function testClassNameRejectsNullEmptyAndMalformed(): void
    {
        $reject = ['9lead', 'a-b', 'a b', "a\tb", ''];
        foreach ($reject as $raw) {
            try {
                NamingRules::className($raw, 'entity name');
                self::fail("Expected rejection for '{$raw}'");
            } catch (InvalidNameException $e) {
                self::assertSame(
                    "Invalid entity name '{$raw}'. Expected [A-Za-z_][A-Za-z0-9_]*.",
                    $e->getMessage(),
                    'pesan wajib eksak: label + raw + petunjuk regex',
                );
            }
        }

        try {
            NamingRules::className(null, 'entity name');
            self::fail('null must be rejected');
        } catch (InvalidNameException $e) {
            self::assertSame(
                "Invalid entity name ''. Expected [A-Za-z_][A-Za-z0-9_]*.",
                $e->getMessage(),
                'null dirender sebagai string kosong',
            );
        }
    }

    /** NamingRules::className:29-33 — reserved word PHP ditolak (case-insensitive). */
    public function testClassNameRejectsReservedWordsCaseInsensitively(): void
    {
        foreach (['List', 'CLASS', 'enum', 'Trait', 'MATCH', 'null', 'self', 'parent', 'SELF', 'Parent'] as $raw) {
            try {
                NamingRules::className($raw);
                self::fail("Reserved word '{$raw}' must be rejected");
            } catch (InvalidNameException $e) {
                self::assertStringContainsString('reserved word', $e->getMessage());
            }
        }
        // Bukan reserved: mirip tapi sah — jangan over-reject.
        self::assertSame('Lists', NamingRules::className('Lists'));
        self::assertSame('Classroom', NamingRules::className('Classroom'));
        self::assertSame('Selfie', NamingRules::className('Selfie'));
        self::assertSame('Parents', NamingRules::className('Parents'));
    }

    /** NamingRules::moduleName — normalisasi trim+lowercase, batas 32 char. */
    public function testModuleNameNormalisesAndValidates(): void
    {
        self::assertSame('core', NamingRules::moduleName('CORE'));
        self::assertSame('my_mod', NamingRules::moduleName('  My_Mod '));
        self::assertSame('a', NamingRules::moduleName('a'));
        self::assertSame(str_repeat('x', 32), NamingRules::moduleName(str_repeat('x', 32)), 'tepat 32 char sah');
    }

    /** NamingRules::moduleName:35-45 — digit/strip awal, 33 char, null, kosong post-trim ditolak. */
    public function testModuleNameRejectsEdgeCases(): void
    {
        $reject = ['9lead', '-lead', '_lead', str_repeat('x', 33), '', '   ', null, 'sp ace'];
        foreach ($reject as $raw) {
            try {
                NamingRules::moduleName($raw);
                self::fail('Expected rejection for ' . var_export($raw, true));
            } catch (InvalidNameException $e) {
                self::assertSame(
                    "Invalid module name '" . ($raw ?? '') . "'. Expected [a-z][a-z0-9_-]{0,31}.",
                    $e->getMessage(),
                    'pesan wajib eksak termasuk batas {0,31}',
                );
            }
        }
    }

    /** NamingRules::pascal — strip dihilangkan; underscore dipertahankan (perilaku warisan v2.8.0). */
    public function testPascalConversionMergesBothSeparators(): void
    {
        self::assertSame('My_Widget', NamingRules::pascal('my_widget'), 'underscore dipertahankan — warisan zef_pascal v2.8.0');
        self::assertSame('CoolPlugin', NamingRules::pascal('cool-plugin'), 'strip dihilangkan');
        self::assertSame('Mixed_FormName', NamingRules::pascal('mixed_form-name'));
        self::assertSame('Already', NamingRules::pascal('already'));
        self::assertSame('', NamingRules::pascal(''));
        self::assertSame('_A', NamingRules::pascal('_a'), 'underscore awal dipertahankan (tidak terjangkau via moduleName)');
    }

    /** NamingRules::snake/kebab — huruf kapital non-awal yang dipisah, huruf awal aman. */
    public function testSnakeAndKebabConversions(): void
    {
        self::assertSame('order_item', NamingRules::snake('OrderItem'));
        self::assertSame('order-item', NamingRules::kebab('OrderItem'));
        self::assertSame('a', NamingRules::snake('A'), 'huruf awal tidak boleh dapat underscore');
        self::assertSame('a', NamingRules::kebab('A'));
        self::assertSame('already_snake', NamingRules::snake('already_snake'));
        self::assertSame('x_m_l_parser', NamingRules::snake('XMLParser'), 'quirk terdokumentasi: inisial beruntun dipisah per huruf');
    }

    // ------------------------------------------------------------------
    // ConsoleIO — log dual-channel
    // ------------------------------------------------------------------

    /** ConsoleIO — stdout dan stderr tercatat terpisah, urutan dipertahankan. */
    public function testIOLogsOutAndErrSeparately(): void
    {
        $io = $this->io();
        $io->out('a');
        $io->err('e1');
        $io->out('b');
        $io->err('e2');
        self::assertSame(['a', 'b'], $io->outLog());
        self::assertSame(['e1', 'e2'], $io->errLog());
        $io->out();
        self::assertSame(['a', 'b', ''], $io->outLog(), 'out tanpa argumen = baris kosong sah');
        // Byte-level: log dan stream wajib sinkron (fwrite + "\n" persis).
        self::assertSame("a\nb\n\n", $this->streamContents(0), 'stdout: tiap pesan diakhiri newline');
        self::assertSame("e1\ne2\n", $this->streamContents(1), 'stderr: terpisah dari stdout');
    }

    // ------------------------------------------------------------------
    // ScaffoldWriter — transaksionalitas
    // ------------------------------------------------------------------

    /** ScaffoldWriter:44-56 — nested dir dibuat, urutan 'Created' sesuai urutan tulis. */
    public function testWriterCreatesNestedDirectories(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $writer = new ScaffoldWriter($io);
        $writer->writeFiles([
            $root . '/a/b/c/First.php' => '<?php // 1',
            $root . '/a/b/Second.php' => '<?php // 2',
        ]);
        self::assertFileExists($root . '/a/b/c/First.php');
        self::assertFileExists($root . '/a/b/Second.php');
        self::assertSame(
            ['Created ' . $root . '/a/b/c/First.php', 'Created ' . $root . '/a/b/Second.php'],
            $io->outLog(),
        );
    }

    /** ScaffoldWriter:44-56 — SATU collision membatalkan SEMUA file (tidak ada tulisan parsial). */
    public function testWriterRefusesAnyExistingFileAtomically(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $writer = new ScaffoldWriter($io);
        self::assertTrue(mkdir($root . '/pre', 0o777, true));
        file_put_contents($root . '/pre/exists.php', 'original');

        try {
            $writer->writeFiles([
                $root . '/pre/fresh.php' => 'NEW',
                $root . '/pre/exists.php' => 'OVERWRITE-ATTEMPT',
            ]);
            self::fail('Collision must throw');
        } catch (ScaffoldCollisionException $e) {
            self::assertSame(
                'Refusing to overwrite existing file: ' . $root . '/pre/exists.php',
                $e->getMessage(),
                'pesan wajib persis: prefix + path yang menabrak',
            );
        }
        self::assertSame('original', file_get_contents($root . '/pre/exists.php'), 'isi lama utuh');
        self::assertFileDoesNotExist($root . '/pre/fresh.php', 'file fresh TIDAK ikut tertulis');
        self::assertSame([], $io->outLog(), 'tidak ada klaim Created sebelum kegagalan');
    }

    /** ScaffoldWriter:58-62 — mkdir gagal (parent berupa FILE) → ScaffoldWriteException. */
    public function testWriterThrowsWhenDirectoryCannotBeCreated(): void
    {
        $root = $this->sandbox();
        file_put_contents($root . '/blocker', 'x');
        $writer = new ScaffoldWriter($this->io());

        try {
            $writer->writeFile($root . '/blocker/sub/New.php', 'x');
            self::fail('mkdir under a file must fail');
        } catch (ScaffoldWriteException $e) {
            self::assertStringContainsString('Cannot create directory', $e->getMessage());
        }
    }

    /** ScaffoldWriter:63-66 — target berupa DIREKTORI (bukan file) lolos cek is_file → gagal saat tulis. */
    public function testWriterThrowsWhenTargetIsADirectory(): void
    {
        $root = $this->sandbox();
        self::assertTrue(mkdir($root . '/target-dir', 0o777, true));
        $writer = new ScaffoldWriter($this->io());

        try {
            $writer->writeFile($root . '/target-dir', 'x');
            self::fail('writing over a directory must fail');
        } catch (ScaffoldWriteException $e) {
            self::assertStringContainsString('Cannot write file', $e->getMessage());
        }
    }

    /** ScaffoldWriter:47 — mode mkdir 0o777 dipatuhi persis saat umask 0 (bunuh int mutant). */
    public function testWriterHonoursDirectoryModeWithUmaskZero(): void
    {
        $root = $this->sandbox();
        $oldUmask = umask(0);

        try {
            new ScaffoldWriter(new ConsoleIO())->writeFile($root . '/mode/deep/File.php', 'x');
        } finally {
            umask($oldUmask);
        }
        self::assertSame(0o777, fileperms($root . '/mode') & 0o777, 'mode direktori = 0o777 saat umask 0');
        self::assertSame(0o777, fileperms($root . '/mode/deep') & 0o777);
    }

    /** ScaffoldWriter:39-41 — writeFile single juga kena cek collision. */
    public function testWriterSingleFileRefusal(): void
    {
        $root = $this->sandbox();
        file_put_contents($root . '/dupe.php', 'old');
        $writer = new ScaffoldWriter($this->io());
        $this->expectException(ScaffoldCollisionException::class);
        $writer->writeFile($root . '/dupe.php', 'new');
    }

    /** ZefMaker::catalog — tepat 11 command (v2.29.0 += make:app), metadata lengkap. */
    public function testCatalogHasExactlyElevenCommandsWithMetadata(): void
    {
        $catalog = $this->maker($this->sandbox())->catalog();
        self::assertSame([
            'make:app', 'make:module', 'make:plugin', 'make:handler',
            'make:middleware', 'make:config', 'make:command', 'make:query',
            'make:entity', 'make:valueobject', 'make:service',
        ], array_keys($catalog));
        foreach ($catalog as $name => $spec) {
            self::assertTrue(str_starts_with($spec['usage'], $name), "usage {$name} wajib diawali nama command");
            self::assertNotSame('', $spec['desc']);
        }
    }

    /** ZefMaker::listCommands — teks: header, tiap usage, footer count. */
    public function testListCommandsPlainOutput(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $maker = new ZefMaker($root, $io);
        self::assertSame(0, $maker->run(['zef', 'list']), 'list plain wajib exit 0');
        // Struktur eksak: header + 11 baris command + baris kosong + footer.
        self::assertCount(14, $io->outLog());
        self::assertSame('ZEF maker commands:', $io->outLog()[0]);
        self::assertSame('', $io->outLog()[12], 'baris kosong pemisah wajib ada');
        self::assertSame('11 command(s)', $io->outLog()[13]);
        self::assertStringContainsString('make:app <path>', $io->outLog()[1]);
        self::assertStringContainsString('make:module <name>', $io->outLog()[2]);
        self::assertStringContainsString('make:valueobject <Name> [--module=<name>]', $io->outLog()[10]);
    }

    /** ZefMaker::listCommands — JSON: dekode == katalog, satu baris. */
    public function testListCommandsJsonOutput(): void
    {
        $io = $this->io();
        new ZefMaker($this->sandbox(), $io)->listCommands(true);
        self::assertCount(1, $io->outLog());
        self::assertStringContainsString("\n    \"", $io->outLog()[0], 'JSON_PRETTY_PRINT wajib aktif (indentasi 4)');
        self::assertSame($this->maker($this->sandbox())->catalog(), json_decode($io->outLog()[0], true, 512, JSON_THROW_ON_ERROR));
    }

    /** ZefMaker::run — dispatch sah membuat file di root + collision kedua exit 1. */
    public function testMakerDispatchesIntoRootAndRefusesRepeat(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $maker = new ZefMaker($root, $io);
        self::assertSame(0, $maker->run(['zef', 'make:module', 'gadget']));
        self::assertFileExists($root . '/modules/Gadget/ConfigProvider.php');
        self::assertFileExists($root . '/modules/Gadget/HomeHandler.php');
        self::assertSame(1, $maker->run(['zef', 'make:module', 'gadget']), 'repeat wajib gagal');
        self::assertStringContainsString('already exists', $io->errLog()[0]);
    }

    /** ZefMaker::dispatch — generator tak dikenal: pesan memuat SEMUA kandidat. */
    public function testMakerUnknownGeneratorListsSupported(): void
    {
        $io = $this->io();
        $code = new ZefMaker($this->sandbox(), $io)->run(['zef', 'make:widget']);
        self::assertSame(1, $code);
        self::assertSame(
            "Unknown generator 'make:widget'. Supported: make:app, make:module, make:plugin, make:handler,"
            . ' make:middleware, make:config, make:command, make:query, make:entity, make:valueobject,'
            . ' make:service.',
            $io->errLog()[0],
            'pesan wajib eksak: nama + seluruh kandidat berurutan',
        );
    }

    /**
     * @dataProvider providerAllCommands
     *
     * @param list<string> $args
     */
    public function testEveryCatalogCommandDispatchesThroughRun(string $command, array $args, string $expectedFile): void
    {
        $root = $this->sandbox(true);
        $io = $this->io();
        $maker = new ZefMaker($root, $io);
        self::assertSame(0, $maker->run(['zef', $command, ...$args]), "{$command} wajib exit 0");
        self::assertFileExists($root . '/' . $expectedFile);
    }

    /** ZefMaker::run — SEMUA generator in-root ter-dispatch (make:app punya kurikulum sendiri di EdgeMatrixDxToolsTest — target-nya wajib di luar root). */
    /**
     * @return list<array{0:string,1:list<string>,2:string}>
     */
    public static function providerAllCommands(): array
    {
        return [
            ['make:module', ['widget16'], 'modules/Widget16/ConfigProvider.php'],
            ['make:plugin', ['Catalog16'], 'plugins/Catalog16/ConfigProvider.php'],
            ['make:handler', ['HandleOrder'], 'modules/Core/HandleOrderHandler.php'],
            ['make:middleware', ['Compression16'], 'src/Middleware/Compression16Middleware.php'],
            ['make:config', ['Payment16'], 'modules/Core/Payment16ConfigProvider.php'],
            ['make:command', ['PlaceOrder'], 'modules/Core/Command/PlaceOrderCommand.php'],
            ['make:query', ['FindOrder'], 'modules/Core/Query/FindOrderQuery.php'],
            ['make:entity', ['Invoice'], 'modules/Core/Domain/Invoice.php'],
            ['make:valueobject', ['Email'], 'modules/Core/Domain/Email.php'],
            ['make:service', ['Inventory'], 'modules/Core/InventoryService.php'],
        ];
    }

    /** ZefMaker::run — command asing (bukan make:*) ditolak dengan arahan ke list. */
    public function testMakerUnknownCommandFallsBackToError(): void
    {
        $io = $this->io();
        $code = new ZefMaker($this->sandbox(), $io)->run(['zef', 'frobnicate']);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown command 'frobnicate'", $io->errLog()[0]);
        self::assertStringContainsString('bin/zef list', $io->errLog()[0]);
    }

    /** ZefMaker::run(null command) — argv terlalu pendek ditolak, bukan crash. */
    public function testMakerRejectsMissingCommandWord(): void
    {
        $io = $this->io();
        self::assertSame(1, new ZefMaker($this->sandbox(), $io)->run(['zef']));
        self::assertStringContainsString("Unknown command ''", $io->errLog()[0]);
    }

    /** ZefMaker::dispatch catch — InvalidNameException jadi exit 1 + pesan stderr. */
    public function testMakerConvertsInvalidNameToExitOne(): void
    {
        $io = $this->io();
        $maker = new ZefMaker($this->sandbox(), $io);
        self::assertSame(1, $maker->run(['zef', 'make:module', '9bad']));
        self::assertStringContainsString('Invalid module name', $io->errLog()[0]);
        self::assertSame(1, $maker->run(['zef', 'make:entity', 'List']));
        self::assertStringContainsString('reserved word', $io->errLog()[1]);
    }

    /** ZefMaker::run list --json via argv — flag --json dikenali di posisi mana pun. */
    public function testMakerRunListWithJsonFlag(): void
    {
        $io = $this->io();
        self::assertSame(0, new ZefMaker($this->sandbox(), $io)->run(['zef', 'list', '--json']));
        $decoded = json_decode($io->outLog()[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('make:module', $decoded);
    }

    /** ZefMaker → ServiceGenerator — argumen ikut diteruskan (anti double-suffix). */
    public function testMakerForwardsArgumentsToGenerator(): void
    {
        $root = $this->sandbox(true);
        $io = $this->io();
        $maker = new ZefMaker($root, $io);
        self::assertSame(0, $maker->run(['zef', 'make:service', 'AuditService', '--module=core']));
        self::assertFileExists($root . '/modules/Core/AuditService.php', 'nama berakhiran Service tidak boleh dobel');
        self::assertFileDoesNotExist($root . '/modules/Core/AuditServiceService.php');
    }

    /** ModuleLister — registry nyata: header, baris per modul, footer count. */
    public function testModuleListerPrintsRegisteredModules(): void
    {
        $registry = new ModuleRegistry();
        $registry->addProvider($this->provider('Billing', ['x' => 1]));
        $registry->addProvider($this->provider('Audit', ['y' => 2]));
        $io = $this->io();
        self::assertSame(0, new ModuleLister($registry, $io)->run());
        // Struktur eksak: header + 2 baris modul + baris kosong + footer.
        self::assertCount(5, $io->outLog());
        self::assertStringContainsString('MODULE', $io->outLog()[0]);
        self::assertSame('Billing', explode(' ', trim($io->outLog()[1]))[0], 'urutan = urutan registrasi, nama asli dipertahankan');
        self::assertSame('Audit', explode(' ', trim($io->outLog()[2]))[0]);
        self::assertStringContainsString('ConfigProviderModule', $io->outLog()[1], 'class konkret dicetak');
        self::assertSame('', $io->outLog()[3], 'baris kosong pemisah wajib ada');
        self::assertSame('2 module(s)', $io->outLog()[4]);
    }

    /** ModuleLister — registry kosong: pesan ramah, tetap exit 0. */
    public function testModuleListerEmptyRegistry(): void
    {
        $io = $this->io();
        self::assertSame(0, new ModuleLister(new ModuleRegistry(), $io)->run());
        self::assertSame(['No modules registered.'], $io->outLog());
    }

    /** PluginLister — scan disk: non-dir diabaikan, struktur outLog eksak. */
    public function testPluginListerScansDiskAndSortsFiles(): void
    {
        $root = $this->sandbox();
        mkdir($root . '/plugins/Beta', 0o777, true);
        mkdir($root . '/plugins/Alpha', 0o777, true);
        file_put_contents($root . '/plugins/Beta/Zed.php', '<?php');
        file_put_contents($root . '/plugins/Beta/Alpha.php', '<?php');
        // '0stray.txt' sortir SEBELUM 'Alpha': entri non-dir pertama wajib
        // dilompati, bukan menghentikan scan (continue != break).
        file_put_contents($root . '/plugins/0stray.txt', 'ignore');
        $io = $this->io();
        self::assertSame(0, new PluginLister($root, $io)->run());
        $rows = $io->outLog();
        self::assertCount(5, $rows);
        self::assertStringContainsString('PLUGIN', $rows[0]);
        self::assertSame('Alpha', explode(' ', trim($rows[1]))[0], 'plugin terurut alfabetis (hasil scandir)');
        self::assertSame('Beta', explode(' ', trim($rows[2]))[0]);
        self::assertStringContainsString('Alpha.php, Zed.php', $rows[2], 'file plugin terurut dari scandir');
        self::assertSame('', $rows[3], 'baris kosong pemisah wajib ada');
        self::assertSame('2 plugin(s)', $rows[4], 'entri non-dir wajib diabaikan');
    }

    /** PluginLister — plugin tanpa file PHP dicetak '-'; tidak ada plugins/ → ramah. */
    public function testPluginListerHandlesEmptyAndMissing(): void
    {
        $root = $this->sandbox();
        mkdir($root . '/plugins/Empty', 0o777, true);
        $io = $this->io();
        self::assertSame(0, new PluginLister($root, $io)->run());
        self::assertStringContainsString('-', $io->outLog()[1], 'plugin kosong ditandai strip');

        $io2 = $this->io();
        $missingRoot = $this->sandbox();
        self::assertSame(0, new PluginLister($missingRoot, $io2)->run());
        self::assertCount(1, $io2->outLog(), 'kas kosong: TIDAK boleh jatuh ke cetak tabel');
        self::assertSame('No plugins found (' . $missingRoot . '/plugins).', $io2->outLog()[0]);
    }

    /** ConfigShower::run(null) — dump penuh; Closure jadi placeholder, bukan exception. */
    public function testConfigShowerDumpsAllWithClosurePlaceholder(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Billing', [
            'depth' => ['x' => 1, 'list' => [1, 'two']],
            'factory' => static fn (): int => 1,
            'obj' => new \stdClass(),
        ]));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run(null));
        $dump = $io->outLog()[0];
        self::assertStringContainsString('"<closure>"', $dump, 'closure wajib jadi placeholder');
        self::assertStringContainsString('"x": 1', $dump);
        self::assertStringContainsString('"<object stdClass>"', $dump, 'object non-serialisable aman');
        self::assertStringContainsString('"two"', $dump);
    }

    /** ConfigShower — resource dalam config dirender placeholder deterministik. */
    public function testConfigShowerRendersResourcePlaceholder(): void
    {
        $aggregator = new ConfigAggregator();
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        $aggregator->addProvider($this->provider('Billing', ['stream' => $resource]));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run('billing.stream'));
        self::assertSame('"<resource>"', $io->outLog()[0]);
    }

    /** ConfigShower dotted key — nilai nested dibaca via get() bawaan aggregator. */
    public function testConfigShowerDottedKeyLookup(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Billing', ['tier' => ['limit' => 500]]));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run('billing.tier.limit'));
        self::assertSame('500', $io->outLog()[0]);
    }

    /** ConfigShower — key hilang: exit 1; key bernilai NULL: exit 0 dengan 'null'. */
    public function testConfigShowerDistinguishesMissingFromNull(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Billing', ['nullable' => null]));
        $io = $this->io();
        self::assertSame(1, new ConfigShower($aggregator, $io)->run('billing.absent'));
        self::assertStringContainsString("Config key 'billing.absent' is not set.", $io->errLog()[0]);

        $io2 = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io2)->run('billing.nullable'));
        self::assertSame('null', $io2->outLog()[0], 'null tersimpan sah — sentinel membedakannya dari missing');
    }

    /** Regresi #214 — dump penuh: nilai ber-key sensitif ter-mask, key biasa utuh. */
    public function testConfigShowerMasksSecretKeysInFullDump(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Db', [
            'host' => '127.0.0.1',
            'password' => 'hunter2-secret-value',
            'credentials' => ['user' => 'zef', 'token' => 'tok-9'],
            'api_key' => 'ak-123',
            'timeout' => 5,
        ]));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run(null));
        $dump = $io->outLog()[0];

        self::assertStringContainsString('"host": "127.0.0.1"', $dump, 'key non-sensitif harus utuh');
        self::assertStringContainsString('"timeout": 5', $dump, 'skalar non-sensitif harus utuh');
        self::assertStringContainsString('"password": "****(20)"', $dump, 'password wajib ter-mask dengan panjang asli');
        self::assertStringContainsString('"api_key": "****(6)"', $dump, 'api_key wajib ter-mask');
        self::assertStringContainsString('"credentials": "****"', $dump, 'array di bawah key sensitif di-mask menyeluruh');
        self::assertStringNotContainsString('hunter2-secret-value', $dump, 'nilai rahasia TIDAK boleh bocor ke output');
        self::assertStringNotContainsString('tok-9', $dump, 'nilai rahasia bersarang tidak boleh bocor');
    }

    /** Regresi #214 — dotted key lookup ke slot sensitif juga ter-mask. */
    public function testConfigShowerMasksDottedSecretKeyLookup(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Db', [
            'tier' => ['password' => 'p@ssw0rd-long-enough', 'limit' => 500],
        ]));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run('db.tier.password'));
        self::assertSame('"****(20)"', $io->outLog()[0], 'lookup langsung ke slot sensitif wajib ter-mask');
        self::assertStringNotContainsString('p@ssw0rd-long-enough', $io->outLog()[0]);

        $io2 = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io2)->run('db.tier.limit'));
        self::assertSame('500', $io2->outLog()[0], 'lookup non-sensitif tidak boleh terpengaruh');
    }

    /** Regresi #214 — --reveal mencetak nilai asli (guard production diuji terpisah). */
    public function testConfigShowerRevealPrintsSecretValuesVerbatim(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->provider('Db', ['password' => 'hunter2-secret-value']));
        $io = $this->io();
        self::assertSame(0, new ConfigShower($aggregator, $io)->run(null, true));
        self::assertStringContainsString('"password": "hunter2-secret-value"', $io->outLog()[0], '--reveal harus mencetak nilai asli');
    }

    /** Regresi #214 — --reveal ditolak saat ZEF_ENV=production. */
    public function testConfigShowerRefusesRevealUnderProductionEnv(): void
    {
        $previous = getenv('ZEF_ENV');
        putenv('ZEF_ENV=production');

        try {
            $aggregator = new ConfigAggregator();
            $aggregator->addProvider($this->provider('Db', ['password' => 'hunter2-secret-value']));
            $io = $this->io();
            self::assertSame(1, new ConfigShower($aggregator, $io)->run(null, true));
            self::assertStringContainsString('Refusing --reveal', $io->errLog()[0]);
            self::assertSame([], $io->outLog(), 'tidak ada baris output saat reveal ditolak');

            // Tanpa --reveal tetap boleh jalan di production (defaultnya masked).
            $io2 = $this->io();
            self::assertSame(0, new ConfigShower($aggregator, $io2)->run(null));
            self::assertStringContainsString('"****(20)"', $io2->outLog()[0]);
        } finally {
            $previous === false ? putenv('ZEF_ENV') : putenv('ZEF_ENV=' . $previous);
        }
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

    private function sandbox(bool $withCore = false): string
    {
        $root = sys_get_temp_dir() . '/zef-maker-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root, 0o777, true));
        if ($withCore) {
            self::assertTrue(mkdir($root . '/modules/Core', 0o777, true));
        }
        $this->roots[] = $root;

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

    // ------------------------------------------------------------------
    // ZefMaker — katalog, list, dispatch
    // ------------------------------------------------------------------

    private function maker(string $root): ZefMaker
    {
        return new ZefMaker($root, $this->io());
    }

    // ------------------------------------------------------------------
    // Inspector — ModuleLister / PluginLister / ConfigShower
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $config
     */
    private function provider(string $module, array $config): ConfigProviderInterface
    {
        return new readonly class($module, $config) implements ConfigProviderInterface {
            /**
             * @param array<string,mixed> $config
             */
            public function __construct(
                private string $module,
                private array $config,
            ) {}

            #[\Override]
            public function getModuleName(): string
            {
                return $this->module;
            }

            /**
             * @return array<string,mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return $this->config;
            }
        };
    }
}
