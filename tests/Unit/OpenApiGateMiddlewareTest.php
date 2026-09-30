<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.33.0 — OpenAPI runtime gate: the PSR-15 adapter. The
 * 12 boundaries are pinned engine-side in OpenApiGateMatrixTest; this file
 * pins the ADAPTER mechanics: problem+json rendering (RFC 9457), the
 * request attribute export, stream safety (rewind after read, non-seekable
 * buffering), the fail-closed boot (B12), the forRoutes composition and
 * the opt-in response-contract mode (B11).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response as HttpTextResponse;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;
use Zef\Framework\OpenApi\Http\OpenApiGateMiddleware;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\MediaType;
use Zef\Framework\OpenApi\OpenApiGateException;
use Zef\Framework\OpenApi\OpenApiGateOptions;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class OpenApiGateMiddlewareTest extends TestCase
{
    public function testAdmittedRequestCarriesTheOperationAttribute(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'pong'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process($this->request('GET', '/ping'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', (string) $response->getBody());
        self::assertNotNull($handler->seen);
        $context = $handler->seen->getAttribute(OpenApiGateMiddleware::REQUEST_ATTRIBUTE);
        self::assertIsArray($context);
        self::assertSame('ping', $context['operationId']);
    }

    public function testPassThroughSetsNullAttribute(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'unknown'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $middleware->process($this->request('GET', '/not-in-the-document'), $handler);

        self::assertNotNull($handler->seen);
        self::assertNull($handler->seen->getAttribute(OpenApiGateMiddleware::REQUEST_ATTRIBUTE));
    }

    public function testRejectionIsProblemPlusJson(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'must not run'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process($this->request('DELETE', '/ping'), $handler);

        self::assertNull($handler->seen);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([
            'type' => 'about:blank',
            'title' => 'Method Not Allowed',
            'status' => 405,
            'detail' => "Method 'DELETE' is not documented for this path.",
            'instance' => '/ping',
            'allowed' => ['GET', 'HEAD'],
        ], $body);
    }

    public function testSecurityRejectionCarriesTheErrorsExtension(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'must not run'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process($this->request('GET', '/users/7'), $handler);

        self::assertSame(401, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(401, $body['status']);
        self::assertSame('Unauthorized', $body['title']);
        self::assertSame([
            ['in' => 'security', 'name' => 'bearer', 'pointer' => '', 'message' => "security scheme 'bearer' is not satisfied"],
        ], $body['errors']);
    }

    public function testRealHeadersAreMatchedCaseInsensitively(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'ok'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());

        // PSR-7 preserves the original header case ('X-REQUEST-ID'); the
        // documented parameter name is 'X-Request-Id' — the lookup must be
        // case-insensitive and the value still schema-checked.
        $response = $middleware->process(
            $this->request('GET', '/users/7', headers: [
                'Authorization' => 'Bearer tok',
                'X-REQUEST-ID' => 'abcdefghijk',
            ]),
            $handler,
        );

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        $errors = $body['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('header', $first['in']);
        self::assertSame('X-Request-Id', $first['name']);
        self::assertSame('string is longer than maxLength 8', $first['message']);
    }

    public function testConstructionFailsClosedOnInvalidDocument(): void
    {
        $this->expectException(OpenApiGateException::class);
        $this->expectExceptionMessage('not enforceable');
        new OpenApiGateMiddleware(['openapi' => 'nope']);
    }

    public function testBodyStaysReadableByTheHandlerAfterValidation(): void
    {
        $body = '{"id":42,"email":"a@b.c"}';
        $handler = new RecordingHandler(new HttpTextResponse(201, [], 'created'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process(
            $this->request('POST', '/users', contentType: 'application/json', body: $body),
            $handler,
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($handler->seen);
        // The gate's read rewound the seekable stream — the handler reads
        // the same bytes.
        self::assertSame($body, (string) $handler->seen->getBody());
    }

    public function testNonSeekableBodyIsBufferedAndReplaced(): void
    {
        $body = '{"id":42,"email":"a@b.c"}';
        $handler = new RecordingHandler(new HttpTextResponse(201, [], 'created'));
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process(
            $this->request('POST', '/users', contentType: 'application/json', body: new NonSeekableBodyStream($body)),
            $handler,
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($handler->seen);
        $stream = $handler->seen->getBody();
        self::assertTrue($stream->isSeekable());
        self::assertSame($body, (string) $stream);
    }

    public function testForRoutesBuildsTheGateFromTheRouteTable(): void
    {
        $router = new Router();
        $router->add('GET', '/ping', 'ping.handler');
        $router->add('POST', '/users/{id:int}', 'users.create');

        /** @var list<array<string, mixed>> $routes */
        $routes = $router->getRoutes();
        $middleware = OpenApiGateMiddleware::forRoutes($routes);
        $handler = new RecordingHandler(new HttpTextResponse(200, [], 'ok'));

        $response = $middleware->process($this->request('GET', '/ping'), $handler);
        self::assertSame(200, $response->getStatusCode());
        $context = $handler->seen?->getAttribute(OpenApiGateMiddleware::REQUEST_ATTRIBUTE);
        self::assertIsArray($context);
        self::assertSame('/ping', $context['path']);

        // Route constraints become path parameter schemas: 'abc' violates {id:int}.
        $response = $middleware->process($this->request('POST', '/users/abc', contentType: 'application/json', body: '{}'), $handler);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testResponseValidationIsOffByDefault(): void
    {
        $offContract = new HttpTextResponse(200, ['Content-Type' => 'application/json'], '{"not":"a string"}');
        $handler = new RecordingHandler($offContract);
        $middleware = new OpenApiGateMiddleware($this->middlewareSpec());
        $response = $middleware->process($this->request('GET', '/echo'), $handler);

        self::assertSame($offContract, $response);
    }

    public function testResponseValidationIsFailClosed500(): void
    {
        $logger = new CollectingLogger();
        $offContract = new HttpTextResponse(200, ['Content-Type' => 'application/json'], '{"not":"a string"}');
        $handler = new RecordingHandler($offContract);
        $middleware = new OpenApiGateMiddleware(
            $this->middlewareSpec(),
            new OpenApiGateOptions(validateResponses: true),
            $logger,
        );
        $response = $middleware->process($this->request('GET', '/echo'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('The response violated the API contract.', $body['detail']);
        self::assertSame('echo', $body['operationId']);
        $errors = $body['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('response', $first['in']);

        self::assertNotEmpty($logger->errors);
        self::assertSame(
            '[ZEF][openapi] response contract violation for echo: expected string, got object',
            $logger->errors[0],
        );
    }

    public function testResponseValidationFlagsUndocumentedStatus(): void
    {
        $handler = new RecordingHandler(new HttpTextResponse(204, [], ''));
        $middleware = new OpenApiGateMiddleware(
            $this->middlewareSpec(),
            new OpenApiGateOptions(validateResponses: true),
        );
        $response = $middleware->process($this->request('GET', '/echo'), $handler);

        self::assertSame(500, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        $errors = $body['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('response status 204 is not documented', $first['message']);
    }

    public function testValidatedResponseStaysReadableAndPassesThrough(): void
    {
        $onContract = new HttpTextResponse(200, ['Content-Type' => 'application/json'], '"ok"');
        $handler = new RecordingHandler($onContract);
        $middleware = new OpenApiGateMiddleware(
            $this->middlewareSpec(),
            new OpenApiGateOptions(validateResponses: true),
        );
        $response = $middleware->process($this->request('GET', '/echo'), $handler);

        self::assertSame($onContract, $response);
        // The validation read rewound the stream — the emitter reads the
        // same bytes.
        self::assertSame('"ok"', (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function middlewareSpec(): array
    {
        $builder = new SpecificationBuilder(new Info(title: 'Gate Middleware API', version: '1.0.0'));
        $builder->addSecurityScheme('bearer', new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer'));
        $builder->addOperation(new Operation(
            operationId: 'ping',
            method: 'GET',
            path: '/ping',
            responses: ['200' => new Response('Pong')],
        ));
        $builder->addOperation(new Operation(
            operationId: 'getUser',
            method: 'GET',
            path: '/users/{id}',
            responses: ['200' => new Response('User')],
            parameters: [
                new Parameter('id', ParameterLocation::Path, new Schema(type: SchemaType::Integer)),
                new Parameter('X-Request-Id', ParameterLocation::Header, new Schema(type: SchemaType::String, maxLength: 8), '', required: true),
            ],
            security: [new SecurityRequirement(['bearer' => []])],
        ));
        $builder->addOperation(new Operation(
            operationId: 'createUser',
            method: 'POST',
            path: '/users',
            responses: ['201' => new Response('Created')],
            requestBody: new RequestBody(
                [MediaType::Json->value => new Schema(
                    type: SchemaType::Object,
                    required: ['id', 'email'],
                    properties: [
                        'id' => new Schema(type: SchemaType::Integer),
                        'email' => new Schema(type: SchemaType::String, format: 'email'),
                    ],
                )],
                'New user',
                true,
            ),
        ));
        $builder->addOperation(new Operation(
            operationId: 'echo',
            method: 'GET',
            path: '/echo',
            responses: ['200' => new Response('Echo', ['application/json' => new Schema(type: SchemaType::String)])],
        ));

        return $builder->build();
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, mixed>  $attributes
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        array $attributes = [],
        string $contentType = '',
        StreamInterface|string|null $body = null,
    ): ServerRequest {
        $headers['Content-Type'] ??= $contentType;
        if ($contentType === '') {
            unset($headers['Content-Type']);
        }
        $bodyStream = $body === null ? null : (is_string($body) ? Stream::fromString($body) : $body);

        return new ServerRequest(
            $method,
            new Uri('http://localhost' . $path),
            [],
            [],
            $query,
            [],
            null,
            $headers,
            $bodyStream,
            '1.1',
            '',
            $attributes,
        );
    }
}

/**
 * @internal
 */
final class RecordingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $seen = null;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = $request;

        return $this->response;
    }
}

/**
 * @internal
 */
final class NonSeekableBodyStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private readonly string $content,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        $remaining = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $remaining;
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): int
    {
        return strlen($this->content);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('The stream is not seekable.');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new \RuntimeException('The stream is not seekable.');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('The stream is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->__toString();
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
