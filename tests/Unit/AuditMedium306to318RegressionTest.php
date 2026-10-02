<?php

declare(strict_types=1);

/*
 * Regression suite for the medium-severity audit issues #306..#318
 * (deep logic audit, branch fix/audit-medium-306-318). One focused test
 * per fixed issue; each test names the issue it pins.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\CompiledConfigSource;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigCompiler;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\FileSecretsProvider;
use Zef\Framework\Config\PhpFileConfigSource;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\DeferrableProviderInterface;
use Zef\Framework\Container\ServiceProviderInterface;
use Zef\Framework\Container\ServiceRegistrarInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;
use Zef\Framework\Job\RedisStreamJobQueue;
use Zef\Framework\Job\ScheduleInterface;
use Zef\Framework\Job\Scheduler;
use Zef\Framework\OpenApi\Attribute\Property as PropertyAttr;
use Zef\Framework\OpenApi\Attribute\Schema;
use Zef\Framework\OpenApi\ClassSchemaBuilder;
use Zef\Framework\OpenApi\FieldRulesSchemaMapper;
use Zef\Framework\OpenApi\OpenApiScalarConstraints;
use Zef\Framework\OpenApi\SchemaGenerator;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Validation\FieldRules;
use Zef\Framework\Validation\Validator;
use Zef\Middleware\ConfigProvider;
use Zef\Middleware\ErrorResponseFactory;

/**
 * @internal
 */
final class AuditMedium306to318RegressionTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-audit-m-' . bin2hex(random_bytes(5));
        mkdir($this->workspace . '/secrets', 0o777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); // nosemgrep: php.lang.security.unlink-use — teardown of the suite's own temp workspace, paths are test-generated
        }
    }

    // ------------------------------------------------------------------
    // #306 — CORS sits immediately after the error handler.
    // ------------------------------------------------------------------

    public function testCorsRunsOutsideTheSecurityMiddlewares(): void
    {
        $config = new ConfigProvider(false, new AuditMediumEnvStub([]))->getConfig();
        $stack = $config['stack'];
        self::assertIsArray($stack);
        assert(array_is_list($stack) && $stack !== []);
        self::assertSame(1, array_search('middleware.cors', $stack, true), 'CORS must be the second entry, right after the error handler.');
        self::assertGreaterThan(
            array_search('middleware.cors', $stack, true),
            array_search('middleware.security', $stack, true),
            'Security short-circuits must pass through CORS decoration.',
        );
    }

    // ------------------------------------------------------------------
    // #307 — Bearer tokens in Authorization values are fully redacted
    // while trailing prose survives single-token redaction.
    // ------------------------------------------------------------------

    public function testBearerSchemeValuesAreConsumedToEndOfLine(): void
    {
        $message = 'connect failed: Authorization: Bearer abc123xyzsupersecret (trace 42)';
        $body = json_decode((string) new ErrorResponseFactory(true)->create(500, $message, 'cid')->getBody(), true);
        assert(is_array($body) && isset($body['message']) && is_string($body['message']));
        self::assertStringNotContainsString('abc123xyzsupersecret', $body['message']);
        self::assertStringContainsString('[REDACTED]', $body['message']);
    }

    public function testQuotedBearerValuesKeepQuotedRegion(): void
    {
        // Quoted multi-token values cannot match the conservative candidate
        // regex at all; the quoted single-token case keeps historical
        // quote-to-quote redaction.
        $message = 'upstream said "Authorization: Bearer" with a leaked token';
        $body = json_decode((string) new ErrorResponseFactory(true)->create(500, $message, 'cid')->getBody(), true);
        assert(is_array($body) && isset($body['message']) && is_string($body['message']));
        self::assertStringContainsString('[REDACTED]', $body['message']);
    }

    public function testApiKeySchemeValuesAreConsumedToo(): void
    {
        $message = 'auth rejected for apikey: Bearer xyzsecret99 - rotate it';
        $body = json_decode((string) new ErrorResponseFactory(true)->create(500, $message, 'cid')->getBody(), true);
        assert(is_array($body) && isset($body['message']) && is_string($body['message']));
        self::assertStringNotContainsString('xyzsecret99', $body['message']);
    }

    // ------------------------------------------------------------------
    // #308 — secret-resolved values are masked in config error messages.
    // ------------------------------------------------------------------

    public function testSecretMaterialIsMaskedInTypeMismatchMessages(): void
    {
        file_put_contents($this->workspace . '/secrets/live_key', "sk-live-do-not-print-987654\n");
        $source = $this->writeSource(
            '<?php return ["api" => ["key" => "%secret:live_key%", "port" => 8080]];',
            'secrets-mask.php',
        );
        $schema = new ConfigSchema([
            new ConfigKey('api.key', ConfigValueType::Int),
            new ConfigKey('api.port', ConfigValueType::Int),
        ]);
        $loader = new ConfigLoader(
            [$source],
            new FileSecretsProvider($this->workspace . '/secrets'),
            $schema,
        );

        try {
            $loader->load();
            self::fail('A string secret on an int key must fail the load.');
        } catch (ConfigValidationException $e) {
            $message = $e->violations()[0]->message;
            self::assertStringContainsString("string('******')", $message, 'Violation must carry the mask, not the value.');
            self::assertStringNotContainsString('sk-live-do-not-print-987654', $message);
        }
    }

    public function testTypedAccessorMasksSecretValuesToo(): void
    {
        file_put_contents($this->workspace . '/secrets/tok', "rawtokenvalue-not-for-logs\n");
        $source = $this->writeSource('<?php return ["svc" => ["token" => "%secret:tok%"]];', 'secrets-typed.php');
        $config = new ConfigLoader(
            [$source],
            new FileSecretsProvider($this->workspace . '/secrets'),
        )->load();

        try {
            $config->int('svc.token');
            self::fail('int() on a string secret must throw.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringNotContainsString('rawtokenvalue-not-for-logs', $e->getMessage());
            self::assertStringContainsString("string('******')", $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // #309 — CompiledConfigSource::load() is repeatable in one process.
    // ------------------------------------------------------------------

    public function testCompiledConfigSourceLoadIsRepeatable(): void
    {
        $path = $this->workspace . '/compiled.php';
        file_put_contents($path, '<?php return ["a" => 1];');
        $source = new CompiledConfigSource($path);
        self::assertSame(['a' => 1], $source->load());
        self::assertSame(['a' => 1], $source->load(), 'A second load() must return the array, not the require_once `true`.');
    }

    // ------------------------------------------------------------------
    // #310 — compiled config publishes with restrictive permissions and
    // leaves no temp files behind.
    // ------------------------------------------------------------------

    public function testCompiledConfigPublishesAtMode0600WithoutTempLeftovers(): void
    {
        $target = $this->workspace . '/var/config.compiled.php';
        mkdir(dirname($target), 0o777, true);
        new ConfigCompiler(0o600)->export(
            new Config(['db' => ['dsn' => 'sqlite::memory:']]),
            $target,
        );
        self::assertFileExists($target);
        // Windows cannot represent POSIX modes (chmod only toggles the read-only
        // flag, reported as 0666); the 0600 guarantee is verified on POSIX.
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0o600, fileperms($target) & 0o777);
        }
        self::assertSame([], glob($this->workspace . '/var/.*.tmp'), 'No temp file may survive the publish.');
    }

    // ------------------------------------------------------------------
    // #311 — deferred provider chains trigger transitively.
    // ------------------------------------------------------------------

    public function testDeferredProviderChainsTriggerBeforeFreeze(): void
    {
        $container = new Container();
        $container->register('app.svc', static fn ($ctx, string $a): string => $a . '/app', ['a.svc']);
        $container->registerProvider(new AuditMediumDeferredA());
        $container->registerProvider(new AuditMediumDeferredB());

        $container->validateAndFreeze();

        self::assertSame('b', $container->get('b.svc'));
        self::assertSame('a+b', $container->get('a.svc'));
        self::assertSame('a+b/app', $container->get('app.svc'), 'The whole deferred chain must be registered before freeze (issue #311).');
    }

    // ------------------------------------------------------------------
    // #312 — a mid-tick enqueue failure keeps the cursor progress.
    // ------------------------------------------------------------------

    public function testSchedulerCursorAdvancesPastSuccessfullyEnqueuedFires(): void
    {
        $queue = new class implements JobQueueInterface {
            /** @var list<JobEnvelope> */
            public array $enqueued = [];

            /** Fails the enqueue at this 1-based position exactly once. */
            public int $failOnNth = 0;

            private int $served = 0;

            public function enqueue(JobEnvelope $job): void
            {
                if (++$this->served === $this->failOnNth) {
                    throw new \OverflowException('simulated queue failure');
                }
                $this->enqueued[] = $job;
            }

            public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
            {
                return array_shift($this->enqueued);
            }

            public function size(): int
            {
                return count($this->enqueued);
            }
        };
        $schedule = new class implements ScheduleInterface {
            private int $calls = 0;

            public function nextRunAfter(int $nowUnixNano): int
            {
                return match (++$this->calls) {
                    1 => 10,
                    2 => 20,
                    default => PHP_INT_MAX,
                };
            }

            public function describe(): string
            {
                return 'stub';
            }
        };

        $queue->failOnNth = 2; // fire 10 succeeds, fire 20 fails this tick
        $scheduler = new Scheduler($queue);
        $scheduler->register('job', [], $schedule);

        try {
            $scheduler->tick(100);
            self::fail('The simulated enqueue failure must surface.');
        } catch (\OverflowException) {
            // expected: fire 10 enqueued, fire 20 failed
        }
        self::assertCount(1, $queue->enqueued);
        self::assertSame(10, $queue->enqueued[0]->availableAtUnixNano);

        // Next tick resumes AFTER the committed cursor (10) — fire 10 must
        // not be duplicated.
        $scheduler->tick(100);
        $times = array_map(static fn (JobEnvelope $e): int => $e->availableAtUnixNano, $queue->enqueued);
        self::assertSame([10, 20], $times, 'The next tick must enqueue only the remaining fire, not replay the committed one.');
    }

    // ------------------------------------------------------------------
    // #313 — the dequeue script no longer compares stream IDs
    // lexicographically.
    // ------------------------------------------------------------------

    public function testDequeueScriptHasNoLexicographicIdTieBreak(): void
    {
        $ref = new \ReflectionClass(RedisStreamJobQueue::class);
        $script = $ref->getConstant('LUA_DEQUEUE');
        self::assertIsString($script);
        self::assertStringNotContainsString('entries[i][1] < bestId', $script, 'Byte-wise id comparison must be gone (issue #313).');
    }

    // ------------------------------------------------------------------
    // #314 — attribute-annotated union properties keep their composition.
    // ------------------------------------------------------------------

    public function testAnnotatedUnionPropertyKeepsOneOf(): void
    {
        $schema = new ClassSchemaBuilder(new SchemaGenerator())->build(AuditMediumUnionDto::class);
        $mixed = $schema->properties['mixed'] ?? null;
        self::assertNotNull($mixed);
        self::assertNotNull($mixed->oneOf, 'applyPropertyMeta() must forward oneOf (issue #314).');
        self::assertCount(2, $mixed->oneOf ?? []);
    }

    // ------------------------------------------------------------------
    // #315 — float bounds survive generation and are enforced.
    // ------------------------------------------------------------------

    public function testFloatBoundsSurviveSchemaGeneration(): void
    {
        $rules = new FieldRules('rate')->typeNumeric()->min(0.5)->max(99.5);
        $schema = new FieldRulesSchemaMapper()->mapField($rules);
        self::assertSame(0.5, $schema->minimum, 'min(0.5) must not be dropped (issue #315).');
        self::assertSame(99.5, $schema->maximum);

        $issues = OpenApiScalarConstraints::numericIssues(0.4, ['minimum' => 0.5], '/rate');
        self::assertNotSame([], $issues, 'The runtime gate must enforce float minimums.');
        $ok = OpenApiScalarConstraints::numericIssues(0.6, ['minimum' => 0.5], '/rate');
        self::assertSame([], $ok);
    }

    // ------------------------------------------------------------------
    // #316 — required()->nullable() fields are not advertised as required.
    // ------------------------------------------------------------------

    public function testNullableRequiredFieldsAreNotAdvertisedAsRequired(): void
    {
        $validator = new Validator();
        $validator->field('note')->required()->nullable();
        $validator->field('id')->required();
        $schema = new FieldRulesSchemaMapper()->mapValidator($validator);
        self::assertContains('id', $schema->required);
        self::assertNotContains('note', $schema->required, 'The validator accepts absence of a nullable-required field, so the schema must not list it (issue #316).');
    }

    // ------------------------------------------------------------------
    // #317 — tokens bind to the per-principal context when supplied.
    // ------------------------------------------------------------------

    public function testCsrfTokenBindingRejectsForeignPrincipals(): void
    {
        $manager = new CsrfTokenManager(str_repeat('k', 32));
        $bound = $manager->issue('session-victim');
        self::assertTrue($manager->isValid($bound, 'session-victim'));
        self::assertFalse($manager->isValid($bound, 'session-attacker'), 'A token valid for one principal must fail under another binding (issue #317).');
        self::assertFalse($manager->isValid($bound), 'A bound token must also fail without its binding context.');
        $unbound = $manager->issue();
        self::assertTrue($manager->isValid($unbound), 'BC: unbound tokens validate without a context.');
        self::assertFalse($manager->isValid($unbound, 'session-victim'), 'An unbound token must not pass under a binding context.');
    }

    public function testCsrfBindingWorksWithTtlEnabled(): void
    {
        $manager = new CsrfTokenManager(str_repeat('k', 32), 32, 300);
        $bound = $manager->issue('sess-1');
        self::assertTrue($manager->isValid($bound, 'sess-1'));
        self::assertFalse($manager->isValid($bound, 'sess-2'));
    }

    // ------------------------------------------------------------------
    // #318 — empty values skipped by skipEmpty do not surface as
    // validated data.
    // ------------------------------------------------------------------

    public function testEmptyValuesBypassedBySkipEmptyAreNotValidatedData(): void
    {
        $validator = new Validator();
        $validator->field('status')->in(['active', 'inactive']);
        $validator->field('name')->required();
        $result = $validator->validate(['status' => '', 'name' => 'ann']);
        self::assertTrue($result->ok());
        self::assertArrayNotHasKey('status', $result->data, 'An empty value no rule accepted must not reach handlers (issue #318).');
        self::assertSame('ann', $result->data['name']);
    }

    public function testEmptyArrayValuesAreDroppedToo(): void
    {
        $validator = new Validator();
        $validator->field('tags')->in(['a', 'b']);
        $result = $validator->validate(['tags' => []]);
        self::assertTrue($result->ok());
        self::assertArrayNotHasKey('tags', $result->data);
    }

    public function testRequiredFieldsStillFailOnEmpty(): void
    {
        $validator = new Validator();
        $validator->field('status')->required()->in(['active', 'inactive']);
        $result = $validator->validate(['status' => '']);
        self::assertFalse($result->ok(), 'required() must keep rejecting empty values.');
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function writeSource(string $code, string $name): PhpFileConfigSource
    {
        $path = $this->workspace . '/' . $name;
        file_put_contents($path, $code);

        return new PhpFileConfigSource($path);
    }
}

final class AuditMediumEnvStub implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;

        return $raw === null ? $default : filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    #[\Override]
    public function readBoolStrict(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }

        throw new \InvalidArgumentException(sprintf(
            "%s='%s' is not a recognized boolean (allowed: 1/0, true/false, yes/no, on/off, enabled/disabled).",
            $name,
            $raw,
        ));
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = $this->values[$name] ?? '';

        return $raw === '' ? [] : array_map(trim(...), explode(',', $raw));
    }

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        return max($min, min($max, (int) ($this->values[$name] ?? $default)));
    }
}

final class AuditMediumDeferredA implements ServiceProviderInterface, DeferrableProviderInterface
{
    public function provides(): array
    {
        return ['a.svc'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        // Defines a service that depends on ANOTHER deferred provider's id —
        // the dependency only becomes visible after this provider runs.
        $container->register('a.svc', static fn ($ctx, string $b): string => 'a+' . $b, ['b.svc']);
    }
}

final class AuditMediumDeferredB implements ServiceProviderInterface, DeferrableProviderInterface
{
    public function provides(): array
    {
        return ['b.svc'];
    }

    public function register(ServiceRegistrarInterface $container): void
    {
        $container->register('b.svc', static fn ($ctx): string => 'b');
    }
}

#[Schema(name: 'AuditMediumUnionDto')]
final class AuditMediumUnionDto
{
    #[PropertyAttr(description: 'mixed identifier')]
    public int|string $mixed = '';
}
