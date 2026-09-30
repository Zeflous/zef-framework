<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Adapters layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Http\ProblemDetails;
use Zef\Framework\Http\Stream;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\OpenApiGateException;
use Zef\Framework\OpenApi\OpenApiGateOptions;
use Zef\Framework\OpenApi\OpenApiGateRequest;
use Zef\Framework\OpenApi\OpenApiGateVerdict;
use Zef\Framework\OpenApi\OpenApiRequestGate;
use Zef\Framework\OpenApi\RouteSpecExtractor;

/**
 * PSR-15 OpenAPI runtime gate — enforces the document's request contract
 * before the router and (opt-in) the response contract after the handler.
 *
 * Rejections are RFC 9457 problem+json with deterministic content; the
 * matched-operation context is exported on the request attribute
 * {@see self::REQUEST_ATTRIBUTE} (null on pass-through, boundary B1).
 *
 * Body handling is stream-safe by construction: seekable request/response
 * streams are cast (the cast rewinds), non-seekable streams are buffered
 * once and swapped for a rewindable copy, so handlers and the emitter can
 * still read what the gate read. The request stream is only touched at all
 * when the matched operation declares a requestBody — the engine's body
 * provider is lazy.
 */
final readonly class OpenApiGateMiddleware implements MiddlewareInterface
{
    public const string REQUEST_ATTRIBUTE = 'zef.openapi.gate';

    private OpenApiGateOptions $options;

    private OpenApiRequestGate $gate;

    /**
     * @param array<string, mixed> $spec the built OpenAPI document
     *
     * @throws OpenApiGateException when the document is not enforceable (B12, fail-closed at boot)
     */
    public function __construct(
        array $spec,
        ?OpenApiGateOptions $options = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->options = $options ?? $this->defaultOptions();
        $this->gate = OpenApiRequestGate::fromSpec($spec, $this->options);
    }

    /**
     * Convenience factory from the route table — the same composition
     * GenerateSpecCommand uses, so the enforced document and the served
     * document are identical by construction.
     *
     * @param list<array<string, mixed>>           $routes Router::getRoutes() shaped arrays
     * @param null|\Closure(string): ?class-string $classResolver
     */
    public static function forRoutes(
        array $routes,
        ?\Closure $classResolver = null,
        ?OpenApiGateOptions $options = null,
        ?LoggerInterface $logger = null,
    ): self {
        $info = new Info(
            title: 'ZEF Framework API',
            version: ZefVersion::VERSION,
            description: 'OpenAPI document enforced by the runtime gate (OpenApiGateMiddleware).',
        );
        $spec = new RouteSpecExtractor($info, $classResolver)->extract($routes)->build();

        return new self($spec, $options, $logger);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $bodyProvider = static fn (): string => (string) $stream;
        } else {
            $buffered = (string) $stream;
            $rewrapped = $request->withBody(Stream::fromString($buffered));
            if (!$rewrapped instanceof ServerRequestInterface) {
                throw new \LogicException('withBody must preserve the request type.');
            }
            $request = $rewrapped;
            $bodyProvider = static fn (): string => $buffered;
        }

        $verdict = $this->gate->evaluate($this->gateRequest($request, $bodyProvider));
        if (!$verdict->admitted) {
            return $this->rejectionResponse($verdict, $request);
        }

        $request = $request->withAttribute(self::REQUEST_ATTRIBUTE, $verdict->operation);
        $response = $handler->handle($request);

        if ($this->options->validateResponses && $verdict->operation !== null) {
            return $this->validateResponse($response, $verdict->operation, $request);
        }

        return $response;
    }

    /**
     * php:S2830: the default options are built through a private factory
     * instead of a bare `new` in the constructor.
     */
    private function defaultOptions(): OpenApiGateOptions
    {
        return new OpenApiGateOptions();
    }

    /**
     * @param \Closure(): ?string $bodyProvider
     */
    private function gateRequest(ServerRequestInterface $request, \Closure $bodyProvider): OpenApiGateRequest
    {
        /** @var array<string, list<string>> $rawHeaders */
        $rawHeaders = $request->getHeaders();
        $headers = [];
        foreach ($rawHeaders as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        /** @var array<string, mixed> $cookies */
        $cookies = $request->getCookieParams();

        /** @var array<string, mixed> $attributes */
        $attributes = $request->getAttributes();

        return new OpenApiGateRequest(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $query,
            $headers,
            $cookies,
            $request->getHeaderLine('Content-Type'),
            $bodyProvider,
            $attributes,
        );
    }

    /**
     * B11: buffer the response body (rewind-safe), judge it against the
     * operation's declared responses, and replace the response with a
     * fail-closed 500 problem+json on any violation.
     *
     * @param array<string, mixed> $operation the matched-operation context
     */
    private function validateResponse(
        ResponseInterface $response,
        array $operation,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $responseStream = $response->getBody();
        $responseBody = (string) $responseStream;
        if (!$responseStream->isSeekable()) {
            $rewrapped = $response->withBody(Stream::fromString($responseBody));
            if (!$rewrapped instanceof ResponseInterface) {
                throw new \LogicException('withBody must preserve the response type.');
            }
            $response = $rewrapped;
        }

        $responses = $operation['responses'] ?? [];
        $issues = is_array($responses)
            ? $this->gate->checkResponse(
                $responses,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type'),
                $responseBody,
            )
            : [];
        if ($issues === []) {
            return $response;
        }

        $operationId = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : '';
        $this->logger?->error(
            '[ZEF][openapi] response contract violation for ' . $operationId . ': ' . $issues[0]['message'],
        );

        return ProblemDetails::fromStatus(
            500,
            'The response violated the API contract.',
            $request->getUri()->getPath(),
            ['errors' => $issues, 'operationId' => $operationId],
        )->toResponse();
    }

    private function rejectionResponse(OpenApiGateVerdict $verdict, ServerRequestInterface $request): ResponseInterface
    {
        $extensions = $verdict->extensions;
        if ($verdict->issues !== []) {
            $extensions['errors'] = $verdict->issues;
        }

        $response = ProblemDetails::fromStatus(
            $verdict->status ?? 400,
            $verdict->detail,
            $request->getUri()->getPath(),
            $extensions,
        )->toResponse();
        foreach ($verdict->headers as $name => $value) {
            $rewrapped = $response->withHeader($name, $value);
            if (!$rewrapped instanceof ResponseInterface) {
                throw new \LogicException('withHeader must preserve the response type.');
            }
            $response = $rewrapped;
        }

        return $response;
    }
}
