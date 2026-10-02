<?php

declare(strict_types=1);

// ZEF Framework — issue #355 regression suite: the configuration-subsystem
// hardening sweep (C-1..C-6):
//   C-1  fail-fast `%secret:%` reference without a bound secrets provider;
//   C-2  empty resolved secret rejected;
//   C-3  authoritative secret-path masking in config:show + Config::secretPaths();
//   C-4  strict bool parsing at the three live call sites;
//   C-5  compiled-config integrity envelope + world-writable refusal;
//   C-6  secrets-port unification (deprecation bridge + first-hit chain).

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ChainSecretsProvider;
use Zef\Framework\Config\CompiledConfigSource;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigCompiler;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\EnvironmentSecretProvider;
use Zef\Framework\Config\FileSecretsProvider;
use Zef\Framework\Config\PhpFileConfigSource;
use Zef\Framework\Config\ResilientSecretsProvider;
use Zef\Framework\Config\SecretProviderAdapter;
use Zef\Framework\Config\SecretsProviderInterface;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Inspector\ConfigShower;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Observability\NoopTracer;
use Zef\Framework\Observability\TelemetryFactory;

/**
 * @internal
 */
final class AuditConfig355HardeningTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-c355-' . bin2hex(random_bytes(5));
        mkdir($this->workspace, 0o700, true);
        mkdir($this->workspace . '/secrets', 0o700, true);
    }

    protected function tearDown(): void
    {
        putenv('ZEF_OTEL_ENABLED');
        putenv('ZEF355_ENV_PASS');
        $this->rm($this->workspace);
    }

    // ---- C-1: literal secret reference without a provider is a violation ----

    public function testSecretRefWithoutProviderFailsTheLoad(): void
    {
        $source = $this->source('<?php return ["db" => ["pass" => "%secret:db_pass%"]];', 'c1.php');

        try {
            new ConfigLoader([$source])->load();
            self::fail('A secret reference without a bound provider must fail the load.');
        } catch (ConfigValidationException $e) {
            self::assertCount(1, $e->violations());
            self::assertSame('db.pass', $e->violations()[0]->key);
            self::assertSame(
                "references secret 'db_pass' but no secrets provider is bound to the config loader",
                $e->violations()[0]->message,
            );
        }
    }

    public function testSecretRefWithoutProviderKeepsPlaceholderOutOfValues(): void
    {
        // raw() stays diagnostics-only (unresolved, no provider needed) — the
        // fail-fast boundary is load(), the production path.
        $source = $this->source('<?php return ["db" => ["pass" => "%secret:db_pass%"]];', 'c1raw.php');
        self::assertSame(['db' => ['pass' => '%secret:db_pass%']], new ConfigLoader([$source])->raw());
    }

    public function testSecretRefInnocentKeyStillDetectedWithoutProvider(): void
    {
        // The grammar scan is value-based, not name-based: a placeholder under
        // an innocent key (`gateway.param1`) must trip the same violation.
        $source = $this->source('<?php return ["gateway" => ["param1" => "%secret:vpn_psk%"]];', 'c1b.php');

        try {
            new ConfigLoader([$source])->load();
            self::fail('The scan is value-based; the placeholder must not survive load().');
        } catch (ConfigValidationException $e) {
            self::assertSame('gateway.param1', $e->violations()[0]->key);
        }
    }

    // ---- C-2: empty resolved secret is a violation ---------------------------

    public function testEmptySecretFileIsRejectedAtLoad(): void
    {
        file_put_contents($this->workspace . '/secrets/db_pass', '');
        $source = $this->source('<?php return ["db" => ["pass" => "%secret:db_pass%"]];', 'c2.php');

        try {
            new ConfigLoader([$source], new FileSecretsProvider($this->workspace . '/secrets'))->load();
            self::fail('An empty resolved secret must fail the load.');
        } catch (ConfigValidationException $e) {
            self::assertCount(1, $e->violations());
            self::assertSame('db.pass', $e->violations()[0]->key);
            self::assertSame("secret 'db_pass' resolved to an empty value", $e->violations()[0]->message);
        }
    }

    public function testUnknownSecretMessageIsUnchanged(): void
    {
        $source = $this->source('<?php return ["db" => ["pass" => "%secret:absent%"]];', 'c2b.php');

        try {
            new ConfigLoader([$source], new FileSecretsProvider($this->workspace . '/secrets'))->load();
            self::fail('Unknown secret must keep failing.');
        } catch (ConfigValidationException $e) {
            self::assertSame("references unknown secret 'absent'", $e->violations()[0]->message);
        }
    }

    // ---- C-3: authoritative secret-path masking -------------------------------

    public function testSecretPathsAccessorReportsResolvedLeaves(): void
    {
        file_put_contents($this->workspace . '/secrets/db_pass', "k-123\n");
        $config = new ConfigLoader(
            [$this->source('<?php return ["db" => ["pass" => "%secret:db_pass%"]];', 'c3.php')],
            new FileSecretsProvider($this->workspace . '/secrets'),
        )->load();
        self::assertSame(['db.pass'], $config->secretPaths());
    }

    public function testShowerMasksAuthoritativeSecretPathInFullDump(): void
    {
        $secret = 'TOPSECRET-9f8e7d6c';
        $aggregator = $this->aggregatorWith(['param1' => $secret, 'host' => '127.0.0.1']);

        // Without the plumbing the innocent-named key renders plaintext —
        // exactly the C-3 leak the plumbing closes.
        $leaky = $this->io();
        new ConfigShower($aggregator, $leaky)->run(null);
        self::assertStringContainsString($secret, $leaky->outLog()[0], 'pre-condition: heuristics alone miss this key');

        $io = $this->io();
        new ConfigShower($aggregator, $io, null, ['db.param1'])->run(null);
        $dump = $io->outLog()[0];
        self::assertStringContainsString('"param1": "****(' . strlen($secret) . ')"', $dump);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('"host": "127.0.0.1"', $dump, 'non-secret sibling stays intact');
    }

    public function testShowerMasksSecretInsideRequestedParentSubtree(): void
    {
        $secret = 'TOPSECRET-9f8e7d6c';
        $aggregator = $this->aggregatorWith(['param1' => $secret, 'host' => '127.0.0.1']);

        $io = $this->io();
        new ConfigShower($aggregator, $io, null, ['db.param1'])->run('db');
        $dump = $io->outLog()[0];
        self::assertStringContainsString('"param1": "****(' . strlen($secret) . ')"', $dump);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('"host": "127.0.0.1"', $dump);
    }

    public function testShowerMasksLookupInsideAndAboveSecretPath(): void
    {
        $secret = 'TOPSECRET-9f8e7d6c';
        $aggregator = $this->aggregatorWith(['param1' => $secret, 'host' => '127.0.0.1']);

        $io = $this->io();
        new ConfigShower($aggregator, $io, null, ['db.param1'])->run('db.param1');
        self::assertSame('"****(' . strlen($secret) . ')"', $io->outLog()[0]);

        $io2 = $this->io();
        new ConfigShower($aggregator, $io2, null, ['db.param1'])->run('db.host');
        self::assertSame('"127.0.0.1"', $io2->outLog()[0], 'non-secret lookup is untouched');
    }

    public function testShowerRevealStillBypassesAuthoritativeMask(): void
    {
        $secret = 'TOPSECRET-9f8e7d6c';
        $aggregator = $this->aggregatorWith(['param1' => $secret]);

        $io = $this->io();
        new ConfigShower($aggregator, $io, null, ['db.param1'])->run('db.param1', true);
        self::assertStringContainsString($secret, $io->outLog()[0], '--reveal remains conscious debugging');
    }

    public function testConfigBagCarriesSecretPathsFromCtor(): void
    {
        $config = new Config(['a' => 'x', 'b' => ['c' => 'y']], null, ['a', 'b.c']);
        self::assertSame(['a', 'b.c'], $config->secretPaths());
        self::assertSame([], new Config([])->secretPaths(), 'no references — empty map');
    }

    // ---- C-4: strict bool parsing at live call sites --------------------------

    public function testOtelEnabledSpellingNowEnablesTelemetry(): void
    {
        putenv('ZEF_OTEL_ENABLED=enabled');
        $telemetry = TelemetryFactory::fromEnvironment(env: new Env());
        self::assertNotInstanceOf(
            NoopTracer::class,
            $telemetry->tracer(),
            "'enabled' must count as TRUE under the strict grammar (audit #304).",
        );
    }

    public function testOtelUnrecognizedBoolRefusesToBoot(): void
    {
        putenv('ZEF_OTEL_ENABLED=maybe');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("ZEF_OTEL_ENABLED='maybe' is not a recognized boolean");
        TelemetryFactory::fromEnvironment(env: new Env());
    }

    // ---- C-5: compiled-config integrity envelope ------------------------------

    public function testCompiledFileCarriesEnvelopeStamps(): void
    {
        $target = $this->workspace . '/compiled.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        $source = new CompiledConfigSource($target);
        self::assertSame(['a' => 1], $source->load());
        $content = (string) file_get_contents($target);
        self::assertStringContainsString("'version' => '" . ZefVersion::VERSION . "'", $content);
        self::assertStringContainsString("'fingerprint' => '", $content);
        self::assertStringContainsString("'values' =>", $content);
    }

    public function testLegacyPlainCompiledFileIsRejected(): void
    {
        $target = $this->workspace . '/legacy.php';
        file_put_contents($target, '<?php return ["a" => 1];');

        try {
            new CompiledConfigSource($target)->load();
            self::fail('A pre-envelope compiled file must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('predates the integrity envelope', $e->getMessage());
            self::assertStringContainsString('Recompile', $e->getMessage());
        }
    }

    public function testTamperedCompiledFileFailsFingerprint(): void
    {
        $target = $this->workspace . '/tampered.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        $edited = str_replace("'a' => 1,", "'a' => 2,", (string) file_get_contents($target));
        self::assertNotSame((string) file_get_contents($target), $edited, 'fixture edit must apply');
        file_put_contents($target, $edited);

        try {
            new CompiledConfigSource($target)->load();
            self::fail('An edited compiled file must fail its fingerprint.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('failed its integrity fingerprint', $e->getMessage());
        }
    }

    public function testForeignVersionStampIsRejected(): void
    {
        $target = $this->workspace . '/foreign.php';
        $values = ['a' => 1];
        file_put_contents($target, '<?php return ' . var_export([
            'version' => '9.9.9-foreign',
            'fingerprint' => hash('sha256', serialize($values)),
            'values' => $values,
        ], true) . ';');

        try {
            new CompiledConfigSource($target)->load();
            self::fail('A file compiled by another build must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("compiled by framework version '9.9.9-foreign'", $e->getMessage());
        }
    }

    public function testEnvelopeWithListValuesIsRejected(): void
    {
        $target = $this->workspace . '/listy.php';
        file_put_contents($target, '<?php return ' . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'x',
            'values' => [1, 2],
        ], true) . ';');

        try {
            new CompiledConfigSource($target)->load();
            self::fail('A list-shaped value tree must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('must return an associative value tree', $e->getMessage());
        }
    }

    public function testWorldWritableCompiledFileIsRefused(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permission bits only.');
        }
        $target = $this->workspace . '/loose.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        chmod($target, 0o666);

        try {
            new CompiledConfigSource($target)->load();
            self::fail('A world-writable executed config file must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('is world-writable', $e->getMessage());
            self::assertStringContainsString('code-injection', $e->getMessage());
        }
    }

    public function testWorldWritableCompiledDirectoryIsRefused(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permission bits only.');
        }
        $dir = $this->workspace . '/loosedir';
        mkdir($dir, 0o700);
        $target = $dir . '/compiled.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        chmod($dir, 0o777);

        try {
            new CompiledConfigSource($target)->load();
            self::fail('A world-writable compiled-config directory must be refused.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('directory', $e->getMessage());
            self::assertStringContainsString('world-writable', $e->getMessage());
        }
    }

    // ---- C-6: secrets-port unification ----------------------------------------

    public function testChainResolvesFirstHitInOrder(): void
    {
        $first = new ChainSecretsProvider([$this->providerOf('shared', 'from-first'), $this->providerOf('other', 'x')]);
        $second = $this->providerOf('shared', 'from-second');
        $chain = new ChainSecretsProvider([$first, $second]);
        self::assertSame('from-first', $chain->get('shared'), 'first hit wins');
        self::assertSame('x', $chain->get('other'), 'later members still reachable');
        self::assertNull($chain->get('absent'), 'all-miss stays null for the loader violation');
    }

    public function testChainCtorGuards(): void
    {
        try {
            new ChainSecretsProvider([]);
            self::fail('Empty chain must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least one provider', $e->getMessage());
        }

        try {
            // @phpstan-ignore argument.type (deliberately wrong member type)
            new ChainSecretsProvider([new \stdClass()]);
            self::fail('Non-provider member must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('stdClass', $e->getMessage());
        }
    }

    public function testAdapterBridgesLegacyEnvProviderOntoCanonicalPort(): void
    {
        putenv('ZEF355_ENV_PASS=s3cret');
        $adapter = new SecretProviderAdapter(new EnvironmentSecretProvider());
        self::assertSame('s3cret', $adapter->get('zef355.env.pass'), 'dotted lowercase maps onto UPPERCASE env name');
        self::assertSame('s3cret', $adapter->get('zef355-env-pass'), 'dash maps onto underscore too');
        self::assertNull($adapter->get('zef355.absent'), 'unknown stays null');
    }

    public function testAdapterRejectsUnmappableKey(): void
    {
        $adapter = new SecretProviderAdapter(new EnvironmentSecretProvider());

        try {
            $adapter->get('2fa.code');
            self::fail('A leading-digit key cannot map onto the legacy grammar.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Secret key '2fa.code' cannot be mapped", $e->getMessage());
            self::assertStringContainsString('2FA_CODE', $e->getMessage());
        }
    }

    public function testChainComposesAdapterAndFileThenWrapsInResilience(): void
    {
        putenv('ZEF355_ENV_PASS=s3cret');
        file_put_contents($this->workspace . '/secrets/file_only', 'from-file');
        $chain = new ChainSecretsProvider([
            new SecretProviderAdapter(new EnvironmentSecretProvider()),
            new FileSecretsProvider($this->workspace . '/secrets'),
        ]);
        $resilient = new ResilientSecretsProvider($chain);
        self::assertSame('s3cret', $resilient->get('zef355.env.pass'), 'env leg through the adapter');
        self::assertSame('from-file', $resilient->get('file_only'), 'file leg first-hit fallback');
        self::assertNull($resilient->get('absent'), 'unknown stays null through the whole stack');
    }

    // ---- fixtures ---------------------------------------------------------------

    private function source(string $code, string $name): PhpFileConfigSource
    {
        $path = $this->workspace . '/' . $name;
        file_put_contents($path, $code);

        return new PhpFileConfigSource($path);
    }

    /**
     * @param array<string,mixed> $dbConfig
     */
    private function aggregatorWith(array $dbConfig): ConfigAggregator
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider($this->moduleProvider('Db', $dbConfig));

        return $aggregator;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function moduleProvider(string $module, array $config): ConfigProviderInterface
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

    private function providerOf(string $key, string $value): SecretsProviderInterface
    {
        return new readonly class($key, $value) implements SecretsProviderInterface {
            public function __construct(
                private string $key,
                private string $value,
            ) {}

            #[\Override]
            public function get(string $key): ?string
            {
                return $key === $this->key ? $this->value : null;
            }
        };
    }

    private function io(): ConsoleIO
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        return new ConsoleIO($out, $err);
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        $entries = $entries === false ? [] : $entries;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rm($path);
            } else {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use — temp file the test itself created in its own workspace; teardown only, no request superglobal in a PHPUnit process (§7.2 register)
            }
        }
        @rmdir($dir);
    }
}
