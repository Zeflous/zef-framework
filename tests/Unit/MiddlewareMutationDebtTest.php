<?php

declare(strict_types=1);

/*
 * ZEF Framework — issue #90 mutation debt burn-down, zone `middleware`
 * (round 1).
 *
 * Kill-map for the 56 non-killed mutants measured at 13f9a3b
 * (237 total, 181 killed, MSI 76.37): every test below names the mutants
 * it exists to kill in its docblock. Documented EQUIVALENTS that no
 * behavioural test can kill (kept as evidence, see the PR body):
 *   - CorsMiddleware.php:40 / :62  UnwrapTrim — OriginPolicy::normalizeOrigin()
 *     trims again internally, so the outer trims are defence-in-depth only.
 *   - CorsMiddleware.php:70  Identical (=== -> ==) — both operands are
 *     always plain strings; the comparison is type-stable.
 *   - CorsMiddleware.php:104 UnwrapArrayValues — implode() ignores array
 *     keys, and the token map's values are unique by construction.
 *   - SecurityHeadersMiddleware.php:43 Coalesce — differs from the ?? form
 *     only by an E_WARNING on a missing key; warnings are not failures
 *     under the current phpunit.xml.dist profile.
 *   - ConfigProvider.php:233/:246/:252 Throw_ — modern phpredis throws
 *     RedisException itself on connect/auth failure before the manual
 *     throw lines can execute (verified empirically against Redis :6399).
 *
 * The error_log assertions override the /dev/null routing from
 * phpunit.xml.dist locally (the documented escape hatch in that file's
 * own comment) and restore it in a finally block.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\ConfigProvider;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\ErrorLogger;
use Zef\Middleware\ErrorResponseFactory;
use Zef\Middleware\GlobalErrorHandler;

/**
 * @internal
 */
final class MiddlewareMutationDebtTest extends TestCase
{
    // ------------------------------------------------------------------
    // ConfigProvider — buildStack (kills ConfigProvider.php:139
    // DecrementInteger + IncrementInteger: an off-by-one splice length
    // either swallows `middleware.security` or truncates the tail).
    // ------------------------------------------------------------------

    public function testStackInsertsRateLimitMiddlewareAtTheConfiguredPositionWhenTiersAreSet(): void
    {
        $env = new MiddlewareDebtEnv(['ZEF_SECURITY_RATE_LIMIT_TIERS' => '[{"name":"t","limit":5,"windowSeconds":60}]']);

        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, $env)->getConfig();

        self::assertSame(
            [
                'middleware.error',
                'middleware.security.runtime',
                'middleware.security.rate_limit',
                'middleware.security',
                'middleware.timing',
                'middleware.cors',
            ],
            $config['stack'],
            'The tier middleware must be INSERTED at index 2 without removing or shifting out any other stack entry.',
        );
    }

    public function testStackStaysCanonicalWithoutTiers(): void
    {
        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, new MiddlewareDebtEnv([]))->getConfig();

        self::assertSame(
            ['middleware.error', 'middleware.security.runtime', 'middleware.security', 'middleware.timing', 'middleware.cors'],
            $config['stack'],
        );
    }

    // ------------------------------------------------------------------
    // ConfigProvider — parseRateLimitTiers (kills :162 Concat family — the
    // boot error must carry BOTH the fixed prefix and the JSON parse
    // detail; and :183 ArrayOneItem — every parsed tier must survive).
    // ------------------------------------------------------------------

    public function testMalformedTiersJsonFailsBootCarryingTheParseDetail(): void
    {
        $env = new MiddlewareDebtEnv(['ZEF_SECURITY_RATE_LIMIT_TIERS' => '[{"name"']);

        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, $env)->getConfig();
        $factory = $this->serviceFactory($config, 'middleware.security.rate_limit');

        try {
            $factory();
            self::fail('Malformed tier JSON must be a boot error when the rate-limit factory is invoked.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('ZEF_SECURITY_RATE_LIMIT_TIERS is not valid JSON:', $e->getMessage(), 'The fixed prefix must LEAD the message — operand order is part of the contract.');
            self::assertStringContainsString('Syntax error', $e->getMessage(), 'The JsonException detail must ride along in the message.');
        }
    }

    public function testEveryParsedTierSurvivesIntoTheRulesList(): void
    {
        $env = new MiddlewareDebtEnv([
            'ZEF_SECURITY_RATE_LIMIT_TIERS' => '[{"name":"api","limit":10,"windowSeconds":60,"pathPrefix":"/api"},{"name":"web","limit":100,"windowSeconds":3600}]',
            'ZEF_SECURITY_RATE_LIMIT_ALGORITHM' => 'sliding',
        ]);

        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, $env)->getConfig();
        $factory = $this->serviceFactory($config, 'middleware.security.rate_limit');

        $middleware = $factory();
        self::assertInstanceOf(RateLimitMiddleware::class, $middleware);

        $rules = new \ReflectionProperty($middleware, 'rules')->getValue($middleware);
        self::assertIsArray($rules);
        self::assertCount(2, $rules, 'Both configured tiers must reach the middleware — dropping the second tier is exactly the ArrayOneItem mutant.');
        $names = [];
        foreach ($rules as $rule) {
            self::assertInstanceOf(RateLimitRule::class, $rule);
            $names[] = $rule->name;
        }
        self::assertSame(['api', 'web'], $names);
    }

    // ------------------------------------------------------------------
    // ConfigProvider — buildRateLimiter fallback (kills :213 Concat family
    // + :217 FunctionCallRemoval: the warning must be EMITED with the full
    // message — store name, the underlying failure detail, and the
    // fallback note).
    // ------------------------------------------------------------------

    public function testUnreachableRedisStoreWarnsWithFullDetailAndFallsBackToInMemory(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded — the Redis-backed fallback paths need the client (Windows smoke profile).');
        }
        $env = new MiddlewareDebtEnv([
            'ZEF_RATE_LIMIT_STORE' => 'redis',
            'ZEF_REDIS_URL' => 'redis://127.0.0.1:1/0',
            'ZEF_REDIS_TIMEOUT_MS' => '100',
        ]);
        $logger = new CollectingLogger();
        $middleware = $this->buildRuntimeMiddleware($env, $logger);

        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
        $warning = $logger->lastWarning();
        self::assertNotNull($warning, 'The store failure must reach the logger.');
        self::assertStringContainsString('Rate-limit store "redis" unavailable', $warning);
        self::assertMatchesRegularExpression(
            '/unavailable \(Connection refused\); falling back to in-memory per-process limiter\.\r?$/u',
            $warning,
            'The failure detail must sit INSIDE the parentheses, before the fallback note — operand order is part of the contract.',
        );
    }

    public function testWrongRedisCredentialsWarnAndFallBackToInMemory(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded — the Redis-backed fallback paths need the client (Windows smoke profile).');
        }
        // The WRONGPASS detail only exists when a live server rejects the
        // credentials; with the server down the failure becomes "Connection
        // refused" and this contract test cannot hold (issue #213). Probe
        // reachability first so the suite self-skips as documented.
        $probe = new \Redis();
        $live = false;

        try {
            $live = $probe->pconnect('127.0.0.1', 6399, 2.0);
        } catch (\RedisException) {
            $live = false;
        }
        if (!$live) {
            self::markTestSkipped('Redis test server (127.0.0.1:6399) not reachable — the WRONGPASS path needs a live server to reject credentials.');
        }
        $env = new MiddlewareDebtEnv([
            'ZEF_RATE_LIMIT_STORE' => 'redis',
            'ZEF_REDIS_URL' => 'redis://:definitely-wrong-password@127.0.0.1:6399/0',
            'ZEF_REDIS_TIMEOUT_MS' => '500',
        ]);
        $logger = new CollectingLogger();
        $middleware = $this->buildRuntimeMiddleware($env, $logger);

        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
        $warning = $logger->lastWarning();
        self::assertNotNull($warning);
        self::assertStringContainsString('Rate-limit store "redis" unavailable', $warning);
        self::assertMatchesRegularExpression(
            '/unavailable \(WRONGPASS[^)]*\); falling back to in-memory per-process limiter\.\r?$/u',
            $warning,
            'The RedisException detail must sit INSIDE the parentheses, before the fallback note.',
        );
    }

    public function testStoreFallbackWithoutALoggerRoutesToErrorLog(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded — the Redis-backed fallback paths need the client (Windows smoke profile).');
        }
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-errlog-store-fallback-');
        $previous = ini_set('error_log', $logFile);

        try {
            $env = new MiddlewareDebtEnv([
                'ZEF_RATE_LIMIT_STORE' => 'redis',
                'ZEF_REDIS_URL' => 'redis://127.0.0.1:1/0',
                'ZEF_REDIS_TIMEOUT_MS' => '100',
            ]);

            /** @var array<string, mixed> $config */
            $config = new ConfigProvider(false, $env)->getConfig();
            $factory = $this->serviceFactory($config, 'middleware.security.runtime');
            // A container that cannot supply a logger leaves the factory's
            // $logger at null — the fallback warning must then go to error_log.
            $container = new class implements ContainerInterface {
                #[\Override]
                public function get(string $id): mixed
                {
                    throw new \RuntimeException('no logger available');
                }

                #[\Override]
                public function has(string $id): bool
                {
                    return false;
                }
            };

            $middleware = $factory($container); // @phpstan-ignore arguments.count (the runtime factory takes the container)
            self::assertInstanceOf(SecurityRuntimeMiddleware::class, $middleware);
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '/dev/null');
        }

        $raw = (string) file_get_contents($logFile);
        self::assertMatchesRegularExpression(
            '/Rate-limit store "redis" unavailable \(Connection refused\); falling back to in-memory per-process limiter\.\r?$/mu',
            $raw,
            'Without a logger the store-fallback warning must reach error_log with the full ordered message.',
        );
        // The path is a tempnam() fixture created by this test itself; no request
        // input reaches it. Registered as an accepted suppression: docs/security/php-sast.md §7.
        @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
    }

    // ------------------------------------------------------------------
    // ConfigProvider — buildCors wildcard (kills :276 ReturnRemoval +
    // ArrayItemRemoval: the any-origin flag must produce a middleware
    // that serves a literal `*` Allow-Origin header).
    // ------------------------------------------------------------------

    public function testCorsWildcardFromEnvAnyFlagServesAStarHeader(): void
    {
        $env = new MiddlewareDebtEnv(['ZEF_CORS_ORIGIN_ANY' => 'true']);

        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, $env)->getConfig();
        $cors = $this->serviceFactory($config, 'middleware.cors')();

        self::assertInstanceOf(CorsMiddleware::class, $cors);
        $response = $cors->process(
            $this->request('/', 'GET', ['Origin' => 'https://somewhere-else.example']),
            $this->handler(),
        );
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // ------------------------------------------------------------------
    // CorsMiddleware — constructor normalisation (kills :38 Continue_ for
    // non-string entries, :42 Continue_ for blank entries, :45 TrueValue +
    // :46 ArrayItemRemoval + :112 FalseValue via the wildcard contract).
    // ------------------------------------------------------------------

    public function testConstructorIgnoresBlankAndNonStringOriginEntries(): void
    {
        // @phpstan-ignore-next-line argument.type (non-string entries are the scenario under test)
        $middleware = new CorsMiddleware([123, '', ' https://b.example ', 'https://a.example']);

        foreach (['https://a.example', 'https://b.example'] as $origin) {
            $response = $middleware->process($this->request('/', 'GET', ['Origin' => $origin]), $this->handler());
            self::assertSame($origin, $response->getHeaderLine('Access-Control-Allow-Origin'), "Origin {$origin} must survive normalisation.");
        }
    }

    public function testWildcardConfigurationServesAnyOriginWithAStarHeader(): void
    {
        $middleware = new CorsMiddleware(['*']);

        $response = $middleware->process(
            $this->request('/', 'GET', ['Origin' => 'https://unlisted.example']),
            $this->handler(),
        );
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // ------------------------------------------------------------------
    // CorsMiddleware — process paths (kills :66 ReturnRemoval via the
    // missing-Origin preflight, :70 UnwrapStrToUpper + the :71 family via
    // the bare disallowed preflight, :74 ReturnRemoval + :112 FalseValue
    // via the header-less plain request, :78 UnwrapStrToUpper via the
    // lowercase allowed preflight).
    // ------------------------------------------------------------------

    public function testMissingRequestOriginServesTheHandlerResponseForPreflight(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);

        $response = $middleware->process($this->request('/', 'OPTIONS'), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testDisallowedOriginPreflightGetsABareVaryOnly204(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);

        foreach (['OPTIONS', 'options'] as $method) {
            $response = $middleware->process(
                $this->request('/', $method, ['Origin' => 'https://evil.example']),
                $this->handler(),
            );
            self::assertSame(204, $response->getStatusCode(), "Method {$method}: a disallowed preflight must stay a bare 204.");
            self::assertSame('Origin', $response->getHeaderLine('Vary'));
            self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'), "Method {$method}: no Allow-Origin for a disallowed origin.");
        }
    }

    public function testDisallowedOriginPlainRequestGetsNoCorsHeaders(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);

        $response = $middleware->process(
            $this->request('/', 'GET', ['Origin' => 'https://evil.example']),
            $this->handler(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testMalformedOriginIsRejectedNotTreatedAsAllowed(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);

        // A scheme-less Origin fails OriginPolicy::normalizeOrigin() with
        // InvalidArgumentException; the catch arm must keep returning false —
        // flipping it to true would decorate a malformed origin with CORS headers.
        $response = $middleware->process(
            $this->request('/', 'GET', ['Origin' => 'not-a-valid-origin']),
            $this->handler(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'), 'A malformed Origin must NOT be served CORS headers.');
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testAllowedOriginPreflightWorksForLowercaseMethod(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);

        $response = $middleware->process(
            $this->request('/', 'options', ['Origin' => 'https://a.example']),
            $this->handler(),
        );
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://a.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testAllowedPlainRequestEchoesTheOriginAndCarriesTheContract(): void
    {
        $middleware = new CorsMiddleware(
            ['https://a.example'],
            'GET, POST',
            'X-Test-Header',
        );

        $response = $middleware->process($this->request('/', 'GET', ['Origin' => 'https://a.example']), $this->handler());
        self::assertSame('https://a.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('X-Test-Header', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    // ------------------------------------------------------------------
    // CorsMiddleware — Vary handling (kills :96 UnwrapTrim + :98
    // UnwrapStrToLower: token deduplication must be trim- and
    // case-insensitive, producing one exact `Origin` token).
    // ------------------------------------------------------------------

    public function testVaryTokenDeduplicationIsTrimAndCaseInsensitive(): void
    {
        $middleware = new CorsMiddleware(['https://a.example']);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Vary' => 'Accept-Encoding, ORIGIN']);
            }
        };

        $response = $middleware->process($this->request('/', 'GET', ['Origin' => 'https://a.example']), $handler);
        self::assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'), 'Uppercase ORIGIN must deduplicate into exactly one Origin token.');
    }

    // ------------------------------------------------------------------
    // ErrorLogger (kills :21 FalseValue — no message key by default;
    // :26 ArrayItemRemoval + :30 ArrayItem — the envelope keys; :37
    // BitwiseOr family — unescaped slashes and unicode in the emitted
    // JSON).
    // ------------------------------------------------------------------

    public function testDefaultLoggerOmitsTheMessageKeyAndKeepsTheEnvelope(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-errlog-envelope-');
        $previous = ini_set('error_log', $logFile);

        try {
            new ErrorLogger()->log('cid-123', new \RuntimeException('boom / secret'), $this->request('/a/b'));
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '/dev/null');
        }

        $raw = (string) file_get_contents($logFile);
        // PHP's error_log file target prefixes the timestamp: strip to the JSON body.
        $jsonStart = strpos($raw, '{');
        self::assertIsInt($jsonStart, 'The error_log line must contain the JSON entry: ' . $raw);
        $decoded = json_decode(substr($raw, $jsonStart), true);
        self::assertIsArray($decoded, 'The entry must be one JSON line: ' . $raw);
        self::assertArrayNotHasKey('message', $decoded, 'Without includeMessage the exception text must NOT reach the log.');
        self::assertSame('error', $decoded['level']);
        self::assertSame('cid-123', $decoded['correlation_id']);
        self::assertSame('GET', $decoded['method']);
        self::assertSame('/a/b', $decoded['path']);
        self::assertSame('RuntimeException', $decoded['exception']);
        $timestamp = $decoded['timestamp'] ?? null;
        self::assertIsString($timestamp);
        self::assertNotSame('', $timestamp);
        // The path is a tempnam() fixture created by this test itself; no request
        // input reaches it. Registered as an accepted suppression: docs/security/php-sast.md §7.
        @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
    }

    public function testMessageFlagCarriesUnescapedUnicodeAndSlashes(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-errlog-message-');
        $previous = ini_set('error_log', $logFile);

        try {
            new ErrorLogger(includeMessage: true)->log(
                'cid-456',
                new \RuntimeException('café failed at /api/v1'),
                $this->request('/x'),
            );
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '/dev/null');
        }

        $raw = (string) file_get_contents($logFile);
        self::assertStringContainsString('café', $raw, 'JSON_UNESCAPED_UNICODE must keep the message readable.');
        self::assertStringContainsString('/api/v1', $raw, 'JSON_UNESCAPED_SLASHES must keep slashes literal.');
        self::assertStringNotContainsString('\/api', $raw, 'Escaped slashes would mean the BitwiseOr mutant survived.');
        self::assertStringContainsString('café failed at /api/v1', $raw);
        // The path is a tempnam() fixture created by this test itself; no request
        // input reaches it. Registered as an accepted suppression: docs/security/php-sast.md §7.
        @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
    }

    // ------------------------------------------------------------------
    // ErrorResponseFactory (kills :28/:30 ArrayItemRemoval + :31 TrueValue:
    // the payload contract must be exact, both modes).
    // ------------------------------------------------------------------

    public function testProductionPayloadContractIsExact(): void
    {
        $response = new ErrorResponseFactory(false)->create(500, 'boom', 'cid-789');
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'), 'The JSON error contract must declare its content type.');
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertSame(
            ['error' => true, 'status' => 500, 'message' => 'An error occurred', 'correlation_id' => 'cid-789'],
            $decoded,
        );
    }

    public function testDevPayloadCarriesTheSanitizedMessage(): void
    {
        $body = new ErrorResponseFactory(true)->create(500, 'password=hunter2 gone', 'cid-0')->getBody();
        $decoded = json_decode((string) $body, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['error']);
        self::assertSame(500, $decoded['status']);
        $message = $decoded['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('[REDACTED]', $message);
        self::assertStringContainsString('gone', $message);
        self::assertSame('cid-0', $decoded['correlation_id']);
    }

    // ------------------------------------------------------------------
    // GlobalErrorHandler (kills :54/:58/:59 — the telemetry context keys;
    // :62 Concat family — the error_log fallback message; :72
    // ArrayItemRemoval — the plain-500 correlation header).
    // ------------------------------------------------------------------

    public function testTelemetryContextCarriesTheFullErrorEnvelope(): void
    {
        $spy = new ContextSpyLogger();
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        $response = new GlobalErrorHandler($spy, new ErrorResponseFactory(false))->process(
            $this->request('/api/fail', 'POST', ['X-Request-ID' => 'req-id-42']),
            $handler,
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('req-id-42', $response->getHeaderLine('X-Request-ID'));
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('An error occurred', $payload['message'] ?? null);

        $context = $spy->lastErrorContext;
        self::assertNotNull($context);
        self::assertSame('boom', $context['exception.message']);
        self::assertSame('req-id-42', $context['request_id']);
        self::assertSame('POST', $context['method']);
        self::assertSame('/api/fail', $context['path']);
        self::assertInstanceOf(\RuntimeException::class, $context['exception']);
    }

    public function testLoggingFailureRoutesToErrorLogWithTheFailureClass(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-errlog-logging-failure-');
        $previous = ini_set('error_log', $logFile);

        try {
            $handler = new class implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \RuntimeException('boom');
                }
            };
            new GlobalErrorHandler(new ThrowingLogger(), new ErrorResponseFactory(false))->process(
                $this->request('/', 'GET', ['X-Request-ID' => 'req-id-7']),
                $handler,
            );
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '/dev/null');
        }

        $raw = (string) file_get_contents($logFile);
        self::assertMatchesRegularExpression(
            '/ZEF logging failure: RuntimeException\r?$/mu',
            $raw,
            'The failure line must LEAD with the fixed prefix and TRAIL with the failure class — operand order is part of the contract (CRLF tolerated: error_log line endings are platform-native).',
        );
        self::assertStringContainsString('ZEF logging failure: ', $raw);
        self::assertStringContainsString('RuntimeException', $raw, 'The failure CLASS must be part of the error_log line.');
        // The path is a tempnam() fixture created by this test itself; no request
        // input reaches it. Registered as an accepted suppression: docs/security/php-sast.md §7.
        @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
    }

    public function testFactoryFailureDegradesToPlain500WithTheCorrelationHeader(): void
    {
        // Invalid UTF-8 in the exception message makes json_encode inside
        // ErrorResponseFactory::create() throw (JSON_THROW_ON_ERROR), which
        // walks the last-resort plain-500 branch.
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException("boom \xB1\x31");
            }
        };

        $response = new GlobalErrorHandler(new CollectingLogger(), new ErrorResponseFactory(true))->process(
            $this->request('/', 'GET', ['X-Request-ID' => 'req-id-9']),
            $handler,
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('req-id-9', $response->getHeaderLine('X-Request-ID'));
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /**
     * @param array<string, mixed> $config
     *
     * @return callable(): object
     */
    private function serviceFactory(array $config, string $service): callable
    {
        $services = $config['services'] ?? null;
        self::assertIsArray($services);
        $entry = $services[$service] ?? null;
        self::assertIsArray($entry);
        $factory = $entry['factory'] ?? null;
        self::assertIsCallable($factory);

        return $factory;
    }

    private function buildRuntimeMiddleware(EnvInterface $env, LoggerInterface $logger): object
    {
        /** @var array<string, mixed> $config */
        $config = new ConfigProvider(false, $env)->getConfig();
        $factory = $this->serviceFactory($config, 'middleware.security.runtime');
        $container = new readonly class($logger) implements ContainerInterface {
            public function __construct(private LoggerInterface $logger) {}

            #[\Override]
            public function get(string $id): mixed
            {
                return $this->logger;
            }

            #[\Override]
            public function has(string $id): bool
            {
                return $id === LoggerInterface::class;
            }
        };

        return $factory($container); // @phpstan-ignore arguments.count (the runtime factory takes the container)
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']), [], [], [], [], null, $headers);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }
}

/**
 * EnvInterface stub for the ConfigProvider debt tests (issue #90).
 *
 * @internal
 */
final class MiddlewareDebtEnv implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null) {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

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

        throw new \InvalidArgumentException($name . "='" . $raw . "' is not a recognized boolean.");
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
        $values = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }
}

/**
 * Logger spy that captures the full error() context (issue #90: the
 * GlobalErrorHandler telemetry envelope mutants).
 *
 * @internal
 */
final class ContextSpyLogger implements LoggerInterface
{
    /** @var null|array<mixed> */
    public ?array $lastErrorContext = null;

    /** @var list<string> */
    public array $messages = [];

    /** @param mixed[] $context */
    #[\Override]
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
        $this->lastErrorContext = $context;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}

/**
 * Logger whose error() channel itself fails — drives the error_log fallback
 * branch of GlobalErrorHandler (issue #90).
 *
 * @internal
 */
final class ThrowingLogger implements LoggerInterface
{
    /** @param mixed[] $context */
    #[\Override]
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function alert(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function critical(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function error(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function warning(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function notice(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function info(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /** @param mixed[] $context */
    #[\Override]
    public function debug(string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger broken');
    }
}
