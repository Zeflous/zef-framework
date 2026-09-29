<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Adapters layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;

/**
 * Serves the assembled OpenAPI document as JSON on an HTTP route.
 *
 * Negotiates with If-None-Match via a deterministic ETag (sha256 of the
 * body) so CI tooling and browsers can revalidate cheaply.
 */
final readonly class SpecHandler implements RequestHandlerInterface
{
    private string $body;
    private string $etag;

    /**
     * @param array<string, mixed> $spec built OpenAPI document
     */
    public function __construct(
        array $spec,
        bool $pretty = true,
    ) {
        $this->body = self::serializedDocument($spec, $pretty);
        $this->etag = '"' . hash('sha256', $this->body) . '"';
    }

    /**
     * The document is rendered once at construction so every response and
     * the ETag derive from the same immutable body.
     *
     * @param array<string, mixed> $spec built OpenAPI document
     */
    private static function serializedDocument(array $spec, bool $pretty): string
    {
        $serializer = new JsonSpecificationSerializer();

        return $serializer->serialize($spec, $pretty);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $this->etag)) {
            return new Response(304, ['ETag' => $this->etag]);
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => $this->etag,
                'Cache-Control' => 'public, max-age=300',
            ],
            $this->body,
        );
    }
}
