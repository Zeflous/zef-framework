<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — OpenAPI structural spec validator + Postman
 * collection exporter + cache adapter behind the v2.9.0 cache port.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\OpenApiSpecValidator;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\SpecificationCache;

/**
 * @internal
 */
final class OpenApiSpecValidatorTest extends TestCase
{
    private OpenApiSpecValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OpenApiSpecValidator();
    }

    public function testValidDocumentProducesNoErrors(): void
    {
        self::assertSame([], $this->validator->validate($this->validSpec()));
    }

    public function testVersionMustBeSemver(): void
    {
        $spec = $this->validSpec();
        $spec['openapi'] = 'latest';
        $errors = $this->validator->validate($spec);
        self::assertContains('Field "openapi" must be a semver string like "3.1.0".', $errors);
    }

    public function testInfoTitleRequired(): void
    {
        $spec = $this->validSpec();
        unset($spec['info']['title']);
        $errors = $this->validator->validate($spec);
        self::assertContains('Field "info.title" must be a non-empty string.', $errors);
    }

    public function testPathsMustBeObject(): void
    {
        $spec = $this->validSpec();
        $spec['paths'] = 'nope';
        $errors = $this->validator->validate($spec);
        self::assertContains('Field "paths" must be an object.', $errors);
    }

    public function testPathKeysMustBeRooted(): void
    {
        $spec = $this->validSpec();
        $spec['paths']['ping'] = $spec['paths']['/ping'];
        unset($spec['paths']['/ping']);
        $errors = $this->validator->validate($spec);
        self::assertContains("Path key 'ping' must be a non-empty string beginning with '/'.", $errors);
    }

    public function testUnknownMethodRejected(): void
    {
        $spec = $this->validSpec();
        $spec['paths']['/ping']['fetch'] = $spec['paths']['/ping']['get'];
        $errors = $this->validator->validate($spec);
        self::assertContains("Path '/ping' contains an unknown HTTP method 'fetch'.", $errors);
    }

    public function testOperationNeedsOperationId(): void
    {
        $spec = $this->validSpec();
        unset($spec['paths']['/ping']['get']['operationId']);
        $errors = $this->validator->validate($spec);
        self::assertContains('Operation [get] /ping must define a non-empty operationId.', $errors);
    }

    public function testDuplicateOperationIdRejected(): void
    {
        $spec = $this->validSpec();
        $spec['components'] ??= [];

        /** @var array<string, mixed> $pingGet */
        $pingGet = $spec['paths']['/ping']['get'];
        $spec['paths']['/echo'] = ['get' => $pingGet];
        $errors = $this->validator->validate($spec);
        self::assertContains("Duplicate operationId 'ping' (also used by [get] /echo).", $errors);
    }

    public function testResponseDescriptionRequired(): void
    {
        $spec = $this->validSpec();

        /** @var array<string, mixed> $pingOp */
        $pingOp = $spec['paths']['/ping']['get'];

        /** @var array<int|string, mixed> $responses200 */
        $responses200 = $pingOp['responses'];

        /** @var array<string, mixed> $r200 */
        $r200 = $responses200['200'];
        $r200['description'] = '';
        $responses200['200'] = $r200;
        $pingOp['responses'] = $responses200;
        $spec['paths']['/ping']['get'] = $pingOp;
        $spec['paths']['/ping']['get']['responses'] = $responses200;
        $errors = $this->validator->validate($spec);
        self::assertContains("Operation [get] /ping response '200' must carry a non-empty description.", $errors);
    }

    public function testPathParameterMustBeRequired(): void
    {
        $spec = $this->validSpec();
        $spec['paths']['/users/{id}'] = ['get' => [
            'operationId' => 'getUser',
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => false]],
            'responses' => ['200' => ['description' => 'ok']],
        ]];
        $errors = $this->validator->validate($spec);
        self::assertContains("Operation [get] /users/{id} path parameter 'id' must set required: true.", $errors);
    }

    public function testInvalidParameterLocation(): void
    {
        $spec = $this->validSpec();

        /** @var array<string, mixed> $pingGetParams */
        $pingGetParams = $spec['paths']['/ping']['get'];
        $pingGetParams['parameters'] = [['name' => 'x', 'in' => 'body']];
        $spec['paths']['/ping']['get'] = $pingGetParams;
        $errors = $this->validator->validate($spec);
        self::assertContains("Operation [get] /ping parameter 'x' has invalid location 'body'.", $errors);
    }

    public function testUnresolvableRefDetected(): void
    {
        $spec = $this->validSpec();
        $spec['paths']['/users'] = ['get' => [
            'operationId' => 'users',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Ghost']]]]],
        ]];
        $errors = $this->validator->validate($spec);
        self::assertContains("Unresolvable \$ref '#/components/schemas/Ghost' (missing from components.schemas).", $errors);
    }

    public function testResolvableRefAccepted(): void
    {
        $spec = $this->validSpec();
        $spec['components'] = ['schemas' => ['User' => ['type' => 'object']]];
        $spec['paths']['/users'] = ['get' => [
            'operationId' => 'users',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]]],
        ]];
        self::assertSame([], $this->validator->validate($spec));
    }

    public function testSecuritySchemeInvariants(): void
    {
        $spec = $this->validSpec();
        $spec['components'] = ['securitySchemes' => ['bad' => ['type' => 'http']]];
        $errors = $this->validator->validate($spec);
        self::assertContains("Security scheme 'bad' of type http must define a scheme.", $errors);
    }

    public function testApiKeySchemeNeedsLocation(): void
    {
        $spec = $this->validSpec();
        $spec['components'] = ['securitySchemes' => ['key' => ['type' => 'apiKey']]];
        $errors = $this->validator->validate($spec);
        self::assertContains("Security scheme 'key' of type apiKey must define in: query|header|cookie.", $errors);
    }

    public function testErrorsComeOutDeterministic(): void
    {
        $spec = $this->validSpec();
        $spec['openapi'] = 'nope';
        unset($spec['info']['title']);
        self::assertSame($this->validator->validate($spec), $this->validator->validate($spec));
    }

    public function testPostmanExportShape(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'Postman API', version: '2.20.0', description: 'docs'));
        $spec = $builder->build();
        $spec['paths']['/users/{id}'] = ['get' => [
            'operationId' => 'getUser',
            'summary' => 'Get user',
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true], ['name' => 'expand', 'in' => 'query', 'description' => 'relations']],
            'responses' => ['200' => ['description' => 'ok']],
        ]];
        $spec['components'] = ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]];

        $collection = new PostmanCollectionExporter()->export($spec);

        /** @var array<string, mixed> $info */
        $info = $collection['info'];
        self::assertSame('Postman API', $info['name']);
        self::assertSame('2.20.0', $info['version']);

        /** @var array<string, mixed> $auth */
        $auth = $collection['auth'] ?? [];
        self::assertSame('bearer', $auth['type']);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];
        self::assertSame('/users/{id}', $folder['name']);

        /** @var list<array<string, mixed>> $folderItems */
        $folderItems = $folder['item'];

        /** @var array<string, mixed> $request */
        $request = $folderItems[0]['request'];
        self::assertSame('GET', $request['method']);

        /** @var array<string, mixed> $url */
        $url = $request['url'];
        self::assertSame('{{baseUrl}}/users/{id}', $url['raw']);
        self::assertSame(['users', ':id'], $url['path']);
        self::assertSame(['{{baseUrl}}'], $url['host']);
        self::assertSame([['key' => 'expand', 'value' => '', 'description' => 'relations']], $url['query']);
    }

    public function testPostmanRequestBodyEmbedsExample(): void
    {
        $spec = [
            'info' => ['title' => 'Body API', 'version' => '1.0.0', 'schema' => 'x'],
            'paths' => ['/widgets' => ['post' => [
                'operationId' => 'createWidget',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'qty' => ['type' => 'integer', 'minimum' => 5],
                        'status' => ['type' => 'string', 'enum' => ['on', 'off']],
                    ],
                ]]]],
                'responses' => ['201' => ['description' => 'created']],
            ]]],
        ];
        $collection = new PostmanCollectionExporter()->export($spec);

        /** @var array<string, mixed> $widgetsFolder */
        $widgetsFolder = $collection['item'][0];

        /** @var list<array<string, mixed>> $widgetItems */
        $widgetItems = $widgetsFolder['item'];

        /** @var array<string, mixed> $widgetRequest */
        $widgetRequest = $widgetItems[0]['request'];

        /** @var array<string, mixed> $body */
        $body = $widgetRequest['body'];
        self::assertSame('raw', $body['mode']);
        self::assertIsString($body['raw']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($body['raw'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('string', $payload['name']);
        self::assertSame(5, $payload['qty']);
        self::assertSame('on', $payload['status']);
    }

    public function testPostmanExportIsDeterministic(): void
    {
        $exporter = new PostmanCollectionExporter();
        $spec = ['info' => ['title' => 'X', 'version' => '1'], 'paths' => ['/a' => ['get' => ['operationId' => 'a', 'responses' => ['200' => ['description' => 'ok']]]]]];
        self::assertSame($exporter->export($spec), $exporter->export($spec));
    }

    public function testCacheHitMissAndInvalidate(): void
    {
        $fake = new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $items = [];

            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            #[\Override]
            public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
            {
                $this->items[$key] = $value;
            }

            #[\Override]
            public function delete(string $key): void
            {
                unset($this->items[$key]);
            }

            #[\Override]
            public function has(string $key): bool
            {
                return isset($this->items[$key]);
            }

            #[\Override]
            public function clear(): void
            {
                $this->items = [];
            }
        };
        $cache = new SpecificationCache($fake, 60);
        self::assertNull($cache->get('1.0.0'));
        $cache->set('1.0.0', ['openapi' => '3.1.0'], 120);
        self::assertSame(['openapi' => '3.1.0'], $cache->get('1.0.0'));
        $cache->invalidate('1.0.0');
        self::assertNull($cache->get('1.0.0'));
    }

    public function testCacheTreatsGarbageAsMiss(): void
    {
        $fake = new class implements CacheInterface {
            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                return 'corrupted-string';
            }

            #[\Override]
            public function set(string $key, mixed $value, ?int $ttlSeconds = null): void {}

            #[\Override]
            public function delete(string $key): void {}

            #[\Override]
            public function has(string $key): bool
            {
                return true;
            }

            #[\Override]
            public function clear(): void {}
        };
        $cache = new SpecificationCache($fake);
        self::assertNull($cache->get('1.0.0'));
    }

    public function testCacheKeysAreFingerprintScoped(): void
    {
        // Regresi P-22 (issue #172): the version alone does not identify a
        // spec — two applications (or two deploys with different route
        // tables) sharing one cache backend must not read each other's
        // documents, while the same fingerprint still shares one scope.
        $fake = new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $items = [];

            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            #[\Override]
            public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
            {
                $this->items[$key] = $value;
            }

            #[\Override]
            public function delete(string $key): void
            {
                unset($this->items[$key]);
            }

            #[\Override]
            public function has(string $key): bool
            {
                return isset($this->items[$key]);
            }

            #[\Override]
            public function clear(): void
            {
                $this->items = [];
            }
        };
        $appA = new SpecificationCache($fake, 60, 'routes-hash-a');
        $appB = new SpecificationCache($fake, 60, 'routes-hash-b');

        $appA->set('1.0.0', ['openapi' => '3.1.0', 'app' => 'A']);
        self::assertNull($appB->get('1.0.0'), 'distinct fingerprints must yield distinct keys');
        self::assertSame(['openapi' => '3.1.0', 'app' => 'A'], $appA->get('1.0.0'));

        $appA2 = new SpecificationCache($fake, 60, 'routes-hash-a');
        self::assertSame(['openapi' => '3.1.0', 'app' => 'A'], $appA2->get('1.0.0'));

        $appA->invalidate('1.0.0');
        self::assertNull($appA2->get('1.0.0'), 'invalidate() must target the fingerprinted key');

        // Default fingerprint keeps the legacy key layout (BC for existing
        // deployments); fingerprinted entries never claim the legacy key.
        $legacy = new SpecificationCache($fake);
        $legacy->set('2.0.0', ['openapi' => '3.1.0']);
        self::assertArrayHasKey('zef.openapi.spec.2.0.0', $fake->items);
        self::assertArrayNotHasKey('zef.openapi.spec.1.0.0', $fake->items);
    }

    public function testJsonSerializerIsReusableForDocuments(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $doc = ['openapi' => '3.1.0', 'info' => ['title' => 'x', 'version' => '1'], 'paths' => new \stdClass()];
        self::assertStringContainsString('"paths": {}', $serializer->serialize($doc, true));
    }

    /**
     * @return array{openapi: string, info: array<string, mixed>, paths: array<string, array<string, array<string, mixed>>>, components?: array<string, mixed>}
     */
    private function validSpec(): array
    {
        $builder = new SpecificationBuilder(new Info(title: 'API', version: '1.0.0'));

        /** @var array{openapi: string, info: array<string, mixed>, paths: array<string, array<string, array<string, mixed>>>, components?: array<string, mixed>} $spec */
        $spec = $builder->build();
        $spec['paths']['/ping'] = ['get' => ['operationId' => 'ping', 'responses' => ['200' => ['description' => 'pong']]]];

        return $spec;
    }
}
