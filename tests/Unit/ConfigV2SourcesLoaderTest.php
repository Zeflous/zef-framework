<?php

declare(strict_types=1);

// ZEF Framework v2.21.0 — Configuration System v2 (Infrastructure + Loader):
// file/env/compiled sources, secrets, deterministic merge, compile round-trip.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\CompiledConfigSource;
use Zef\Framework\Config\ConfigCompiler;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\EnvConfigSource;
use Zef\Framework\Config\FileSecretsProvider;
use Zef\Framework\Config\PhpFileConfigSource;
use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * @internal
 */
final class ConfigV2SourcesLoaderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-configv2-' . uniqid();
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        $files = glob($this->workspace . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file); // nosemgrep: php.lang.security.unlink-use
            }
        }
        @rmdir($this->workspace);
    }

    // ---- PhpFileConfigSource -------------------------------------------------

    public function testPhpFileSourceNamesAndLoads(): void
    {
        $path = $this->writeSource('<?php return ["app" => ["mode" => "prod"]];');
        $source = new PhpFileConfigSource($path);
        self::assertSame('file:source.php', $source->name());
        self::assertSame(['app' => ['mode' => 'prod']], $source->load());
        $named = new PhpFileConfigSource($path, 'base');
        self::assertSame('base', $named->name());
    }

    public function testPhpFileSourceRejectsMissingAndUnreadable(): void
    {
        $source = new PhpFileConfigSource($this->workspace . '/missing.php');

        try {
            $source->load();
            self::fail('Missing file must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('does not exist or is not readable', $e->getMessage());
            self::assertStringContainsString('missing.php', $e->getMessage());
        }
        $directory = new PhpFileConfigSource($this->workspace);

        try {
            $directory->load();
            self::fail('Directory must fail is_file check.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('does not exist or is not readable', $e->getMessage());
        }
    }

    public function testPhpFileSourceRejectsNonArrayReturns(): void
    {
        foreach (['<?php return [1, 2];', '<?php return "scalar";', '<?php return null;', '<?php'] as $body) {
            $path = $this->writeSource($body);
            $source = new PhpFileConfigSource($path);

            try {
                $source->load();
                self::fail('Non-associative return must fail: ' . $body);
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must return an associative array', $e->getMessage());
            }
        }
    }

    public function testPhpFileSourceWrapsParseErrors(): void
    {
        $path = $this->writeSource('<?php return [unterminated');
        $source = new PhpFileConfigSource($path);

        try {
            $source->load();
            self::fail('Parse error must be wrapped.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('failed to load', $e->getMessage());
        }
    }

    // ---- EnvConfigSource -----------------------------------------------------

    public function testEnvSourcePrefixGrammar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid environment prefix 'zef_'");
        new EnvConfigSource('zef_');
    }

    public function testEnvSourceMapsDoubleUnderscoreAndLowercase(): void
    {
        $_ENV += ['ZEF_DATABASE__HOST' => 'db.internal', 'ZEF_APP__MODE' => 'local', 'ZEF_DATABASE__POOL_SIZE' => '8'];

        try {
            $source = new EnvConfigSource();
            self::assertSame('env:ZEF_', $source->name());
            $values = $source->load();
            self::assertSame('db.internal', $values['database.host'] ?? null);
            self::assertSame('local', $values['app.mode'] ?? null);
            self::assertSame('8', $values['database.pool_size'] ?? null);
        } finally {
            unset($_ENV['ZEF_DATABASE__HOST'], $_ENV['ZEF_APP__MODE'], $_ENV['ZEF_DATABASE__POOL_SIZE']);
        }
    }

    public function testEnvSourceSkipsMalformedBodies(): void
    {
        $backup = $_ENV;
        $_ENV = ['ZEF_' => 'empty', 'ZEF-lower' => 'skip', 'ZEF_weird-cased' => 'skip', 'OTHER_X' => 'keep-out', 'ZEF_OK__VALUE' => 'fine'];

        try {
            $values = new EnvConfigSource()->load();
            self::assertSame(['ok.value' => 'fine'], $values);
        } finally {
            $_ENV = $backup;
        }
    }

    public function testEnvSourceRejectsEmptySegmentsAndIsSorted(): void
    {
        $backup = $_ENV;
        $_ENV = ['ZEF_A__' => 'x', 'ZEF__B' => 'y', 'ZEF_B__A' => '2', 'ZEF_A__B' => '1'];

        try {
            $values = new EnvConfigSource()->load();
            self::assertSame(['a.b' => '1', 'b.a' => '2'], $values);
        } finally {
            $_ENV = $backup;
        }
    }

    // ---- FileSecretsProvider -------------------------------------------------

    public function testFileSecretsRequireExistingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        new FileSecretsProvider($this->workspace . '/nope');
    }

    public function testFileSecretsResolveTrimmedValues(): void
    {
        mkdir($this->workspace . '/secrets');
        file_put_contents($this->workspace . '/secrets/db_pass', "  s3cr3t\n\n");
        file_put_contents($this->workspace . '/secrets/empty', '');
        $provider = new FileSecretsProvider($this->workspace . '/secrets');
        self::assertSame('s3cr3t', $provider->get('db_pass'));
        self::assertSame('', $provider->get('empty'));
        self::assertNull($provider->get('unknown'));
    }

    public function testFileSecretsRejectTraversalShapedKeys(): void
    {
        $provider = new FileSecretsProvider($this->workspace);
        foreach (['UPPER', 'with.dot..double', '../escape', 'a/b', '', '.hidden', str_repeat('a', 129)] as $bad) {
            self::assertNull($provider->get($bad), "Key '{$bad}' must be unknown.");
        }
    }

    /**
     * ZEF-DX-10 (#249 numbering, issue #250): read-side permission hygiene.
     * Loose permissions WARN through the optional PSR-3 sink and never
     * change the returned value; owner-only files stay silent.
     */
    public function testFileSecretsWarnOnLoosePermissionsWithoutThrowing(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }
        $dir = $this->workspace . '/secrets';
        mkdir($dir, 0o700);
        file_put_contents($dir . '/tight', 'keeper');
        chmod($dir . '/tight', 0o600);
        file_put_contents($dir . '/group_readable', 'g');
        chmod($dir . '/group_readable', 0o640);
        file_put_contents($dir . '/world_writable', 'w');
        chmod($dir . '/world_writable', 0o666);

        $logger = new CollectingLogger();
        $provider = new FileSecretsProvider($dir, $logger);

        // Values are returned unchanged regardless of posture.
        self::assertSame('keeper', $provider->get('tight'));
        self::assertSame('g', $provider->get('group_readable'));
        self::assertSame('w', $provider->get('world_writable'));

        // Exactly the two loose files warn; the owner-only one stays silent.
        self::assertCount(2, $logger->warnings);
        self::assertStringContainsString("key 'group_readable'", $logger->warnings[0]);
        self::assertStringContainsString('0640', $logger->warnings[0]);
        self::assertStringContainsString("key 'world_writable'", $logger->warnings[1]);
        self::assertStringContainsString('0666', $logger->warnings[1]);

        // A world-writable DIRECTORY warns once at construction (chmod
        // explicitly: mkdir() applies the process umask to the mode).
        $loose = $this->workspace . '/loose-dir';
        mkdir($loose, 0o777);
        chmod($loose, 0o777);
        $dirLogger = new CollectingLogger();
        new FileSecretsProvider($loose, $dirLogger);
        self::assertCount(1, $dirLogger->warnings);
        self::assertStringContainsString('world-writable', $dirLogger->warnings[0]);
        self::assertStringContainsString('0777', $dirLogger->warnings[0]);
    }

    // ---- ConfigLoader construction -------------------------------------------

    public function testLoaderRejectsNonSourceElements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement ConfigSourceInterface');
        // @phpstan-ignore argument.type (deliberately wrong element type)
        new ConfigLoader(['not-a-source']);
    }

    public function testLoaderRejectsInvalidAndDuplicateSourceNames(): void
    {
        $path = $this->writeSource('<?php return [];');

        try {
            new ConfigLoader([new PhpFileConfigSource($path, 'bad name!')]);
            self::fail('Invalid source name must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Invalid config source name 'bad name!'", $e->getMessage());
        }

        try {
            new ConfigLoader([
                new PhpFileConfigSource($path, 'dup'),
                new PhpFileConfigSource($path, 'dup'),
            ]);
            self::fail('Duplicate source name must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Duplicate config source name 'dup'", $e->getMessage());
        }
        $loader = new ConfigLoader([new PhpFileConfigSource($path)]);
        self::assertSame(['file:source.php'], $loader->sourceNames());
    }

    // ---- Merge semantics ------------------------------------------------------

    public function testLaterSourcesOverrideEarlier(): void
    {
        $first = new PhpFileConfigSource($this->writeSource(
            '<?php return ["a" => 1, "deep" => ["x" => 1, "keep" => true], "list" => [1, 2]];',
            'first.php',
        ));
        $second = new PhpFileConfigSource($this->writeSource(
            '<?php return ["a" => 2, "deep" => ["x" => 9], "list" => [7]];',
            'second.php',
        ));
        $raw = new ConfigLoader([$first, $second])->raw();
        self::assertSame(['a' => 2, 'deep' => ['x' => 9, 'keep' => true], 'list' => [7]], $raw);
        // An empty array replaces; an empty source cannot erase anything.
        $third = new PhpFileConfigSource($this->writeSource('<?php return ["list" => []];', 'third.php'));
        $raw = new ConfigLoader([$first, $third])->raw();
        self::assertSame(['a' => 1, 'deep' => ['x' => 1, 'keep' => true], 'list' => []], $raw);
    }

    public function testDottedKeysArePathShorthandEverywhere(): void
    {
        $nested = new PhpFileConfigSource($this->writeSource(
            '<?php return ["app" => ["mode" => "prod"]];',
            'nested.php',
        ));
        $flat = new PhpFileConfigSource($this->writeSource(
            '<?php return ["app.name" => "toko", "app.mode" => "local"];',
            'flat.php',
        ));
        $raw = new ConfigLoader([$nested, $flat])->raw();
        self::assertSame(['mode' => 'local', 'name' => 'toko'], $raw['app']);
        // Inside one source: the later declaration wins; a top-level dotted
        // key reaching into a nested subtree replaces that subtree's branch.
        $mixed = new PhpFileConfigSource($this->writeSource(
            '<?php return ["a" => "scalar", "a.b" => 1, "c.d" => 2, "c" => ["e" => 3], "h" => ["deep" => ["x" => 1]], "h.deep" => 2];',
            'mixed.php',
        ));
        $raw = new ConfigLoader([$mixed])->raw();
        self::assertSame(['b' => 1], $raw['a']);
        self::assertSame(['e' => 3], $raw['c']);
        self::assertSame(['deep' => 2], $raw['h']);
        // Relative expansion: a dotted key inside a subtree nests there.
        $relative = new PhpFileConfigSource($this->writeSource(
            '<?php return ["f" => ["g" => 1, "f.g" => 9]];',
            'relative.php',
        ));
        $raw = new ConfigLoader([$relative])->raw();
        self::assertSame(['g' => 1, 'f' => ['g' => 9]], $raw['f']);
    }

    public function testLoaderRejectsListShapedSources(): void
    {
        // @phpstan-ignore argument.type (deliberately list-shaped values)
        $source = new ConfigV2ArraySource([1, 2], 'listy');
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Config source 'listy' must return an associative array.");
        new ConfigLoader([$source])->raw();
    }

    // ---- Secrets ---------------------------------------------------------------

    public function testSecretsResolveAndViolationsCollect(): void
    {
        mkdir($this->workspace . '/secrets');
        file_put_contents($this->workspace . '/secrets/api_key', ' k-123 ');
        $secrets = new FileSecretsProvider($this->workspace . '/secrets');
        $schema = new ConfigSchema([
            new ConfigKey('api.key', ConfigValueType::String),
            new ConfigKey('api.url', ConfigValueType::String),
            new ConfigKey('inline', ConfigValueType::String),
        ]);
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["api" => ["key" => "%secret:api_key%", "url" => "%secret:missing%", "inline" => "pre%secret:api_key%"]];',
            'secrets.php',
        ));

        try {
            new ConfigLoader([$source], $secrets, $schema)->load();
            self::fail('Unknown secret must fail the load.');
        } catch (ConfigValidationException $e) {
            self::assertCount(1, $e->violations());
            self::assertSame('api.url', $e->violations()[0]->key);
            self::assertSame("references unknown secret 'missing'", $e->violations()[0]->message);
        }
        $ok = new PhpFileConfigSource($this->writeSource(
            '<?php return ["api" => ["key" => "%secret:api_key%", "url" => "https://x", "inline" => "pre%secret:api_key%"]];',
            'secrets2.php',
        ));
        $config = new ConfigLoader([$ok], $secrets, $schema)->load();
        // Full-value reference resolved + trimmed; inline fragment left alone.
        self::assertSame('k-123', $config->string('api.key'));
        self::assertSame('pre%secret:api_key%', $config->string('api.inline'));
    }

    public function testRawLeavesSecretsUnresolved(): void
    {
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["a" => ["b" => "%secret:k%"]];',
            'rawsecret.php',
        ));
        self::assertSame(['a' => ['b' => '%secret:k%']], new ConfigLoader([$source])->raw());
    }

    // ---- Validation + defaults --------------------------------------------------

    public function testLoadWithoutSchemaSkipsValidation(): void
    {
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["port" => "not-even-numeric"];',
            'noschema.php',
        ));
        $config = new ConfigLoader([$source])->load();
        self::assertSame('not-even-numeric', $config->get('port'));
    }

    public function testLoadFailsFastWithFullReport(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('port', ConfigValueType::Int, min: 1),
            new ConfigKey('mode', ConfigValueType::String, required: true),
            new ConfigKey('missing_opt', ConfigValueType::Int, default: 3),
        ]);
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["port" => 0, "typo_key" => true];',
            'bad.php',
        ));

        try {
            new ConfigLoader([$source], null, $schema)->load();
            self::fail('Schema violations must fail the load.');
        } catch (ConfigValidationException $e) {
            self::assertCount(2, $e->violations());
            self::assertSame('port', $e->violations()[0]->key);
            self::assertSame('must be >= 1', $e->violations()[0]->message);
            self::assertSame('mode', $e->violations()[1]->key);
            self::assertSame('is required', $e->violations()[1]->message);
        }
    }

    public function testLoadAppliesDefaultsAfterValidation(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('port', ConfigValueType::Int, default: 5432),
            new ConfigKey('app.name', ConfigValueType::String, default: 'zef'),
        ]);
        $source = new PhpFileConfigSource($this->writeSource('<?php return [];', 'empty.php'));
        $config = new ConfigLoader([$source], null, $schema)->load();
        self::assertSame(5432, $config->int('port'));
        self::assertSame('zef', $config->string('app.name'));
    }

    // ---- Compiler round-trip -----------------------------------------------------

    public function testCompilerRoundTripThroughCompiledSource(): void
    {
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["app" => ["mode" => "prod", "flags" => ["a" => true, "b" => null, "n" => 0]], "port" => "5432", "ratio" => 0.25];',
            'compile.php',
        ));
        $config = new ConfigLoader([$source])->load();
        $compiledPath = $this->workspace . '/compiled.php';
        new ConfigCompiler()->export($config, $compiledPath);
        $compiled = new CompiledConfigSource($compiledPath);
        self::assertSame('compiled:compiled.php', $compiled->name());
        $reloaded = new ConfigLoader([$compiled])->load();
        self::assertSame('prod', $reloaded->string('app.mode'));
        self::assertSame(5432, $reloaded->int('port'));
        self::assertSame(0.25, $reloaded->float('ratio'));
        self::assertSame(['a' => true, 'b' => null, 'n' => 0], $reloaded->array('app.flags'));
        self::assertTrue($reloaded->bool('app.flags.a'));
        self::assertNull($reloaded->get('app.flags.b'));
    }

    public function testCompilerRejectsUnexportableValues(): void
    {
        $config = new ConfigLoader([new ConfigV2ArraySource(['deep' => ['obj' => new \stdClass()]])])->load();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Config value at 'deep.obj' cannot be compiled");
        new ConfigCompiler()->export($config, $this->workspace . '/out.php');
    }

    public function testCompilerRequiresWritableTargetDirectory(): void
    {
        $config = new ConfigLoader([new ConfigV2ArraySource(['a' => 1])])->load();

        try {
            new ConfigCompiler()->export($config, $this->workspace . '/missing-dir/out.php');
            self::fail('Unwritable target must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('not writable', $e->getMessage());
        }
    }

    public function testCompiledSourceGuards(): void
    {
        $missing = new CompiledConfigSource($this->workspace . '/nope.php', 'compiled-cache');
        self::assertSame('compiled-cache', $missing->name());

        try {
            $missing->load();
            self::fail('Missing compiled file must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('does not exist or is not readable', $e->getMessage());
        }
        $broken = new CompiledConfigSource($this->writeSource('<?php return [1];', 'broken.php'));

        try {
            $broken->load();
            self::fail('Non-array compiled file must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('must return an associative array', $e->getMessage());
        }
    }

    private function writeSource(string $body, string $name = 'source.php'): string
    {
        $path = $this->workspace . '/' . $name;
        file_put_contents($path, $body);

        return $path;
    }
}
