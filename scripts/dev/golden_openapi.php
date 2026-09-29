<?php

declare(strict_types=1);

/**
 * Mencetak dokumen OpenAPI golden + koleksi Postman golden untuk test.
 * Output dipakai sebagai assertSame baseline (telah direview manual).
 */

use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\MediaType;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\Tag;

require_once __DIR__ . '/../../vendor/autoload.php';

// Single source for the User schema ref pointer (S1192: was duplicated 3x).
const USER_SCHEMA_REF = '#/components/schemas/User';

$builder = new SpecificationBuilder(
    new Info(
        title: 'Golden API',
        version: '2.20.0',
        description: 'Golden document',
        termsOfService: 'https://zef.dev/tos',
        contact: new Contact(name: 'Guild', email: 'guild@zef.dev'),
        license: new License(name: 'MIT', identifier: 'MIT'),
    ),
);
$builder->addServer(new Server('https://api.zef.dev', 'prod', ['host' => 'api.zef.dev']));
$builder->addTag(new Tag('users', 'User management'));
$builder->addSecurityScheme('bearer', new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT', description: 'JWT auth'));
$builder->addSchema('User', new Schema(
    type: SchemaType::Object,
    title: 'User',
    description: 'A user',
    deprecated: true,
    minProperties: 1,
    maxProperties: 99,
    required: ['id', 'email'],
    properties: [
        'id' => new Schema(type: SchemaType::Integer, minimum: 1, readOnly: true, description: 'Identifier'),
        'email' => new Schema(type: SchemaType::String, format: 'email', maxLength: 254, example: 'u@zef.dev'),
        'status' => new Schema(type: SchemaType::String, enum: ['active', 'off']),
        'tags' => new Schema(type: SchemaType::Array, items: new Schema(type: SchemaType::String), uniqueItems: true, minItems: 1, maxItems: 10),
        'nickname' => new Schema(ref: USER_SCHEMA_REF, nullable: true),
    ],
    additionalPropertiesAllowed: false,
    example: ['id' => 1],
));
$builder->addOperation(new Operation(
    operationId: 'getUser',
    method: 'GET',
    path: '/users/{id}',
    responses: ['200' => new Response('User found', ['application/json' => new Schema(ref: USER_SCHEMA_REF)]), '404' => new Response('Missing')],
    summary: 'Get one',
    description: 'Full detail',
    tags: ['users'],
    parameters: [
        new Parameter('id', ParameterLocation::Path, new Schema(type: SchemaType::Integer, minimum: 1), 'User id', example: 7),
        new Parameter('expand', ParameterLocation::Query, new Schema(type: SchemaType::String), '', required: true, deprecated: true),
    ],
    deprecated: true,
    security: [new SecurityRequirement(['bearer' => []])],
));
$builder->addOperation(new Operation(
    operationId: 'createUser',
    method: 'POST',
    path: '/users',
    responses: ['201' => new Response('Created')],
    requestBody: new RequestBody([MediaType::Json->value => new Schema(ref: USER_SCHEMA_REF)], 'New user', true),
));

$spec = $builder->build();
echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
file_put_contents(__DIR__ . '/../../build/golden-spec.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$collection = (new PostmanCollectionExporter())->export($spec);
echo "\n=== POSTMAN ===\n";
echo json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
file_put_contents(__DIR__ . '/../../build/golden-postman.json', json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
