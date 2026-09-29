<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\UriInterface;
use Zef\Framework\Validation\HttpMethodValidator;

/**
 * Shared request-line behaviour extracted from Request and ServerRequest.
 * Eliminates the duplicate deriveRequestTarget / getRequestTarget /
 * withRequestTarget / getMethod / withMethod / getUri / withUri / Host logic.
 */
trait RequestTrait
{
    private ?string $requestTargetOverride = null;
    private string $method = '';
    private UriInterface $uri;

    public function getRequestTarget(): string
    {
        return $this->requestTargetOverride ?? $this->deriveRequestTarget($this->uri);
    }

    public function withRequestTarget(string $requestTarget): static
    {
        self::assertRequestTarget($requestTarget);
        $n = clone $this;
        $n->requestTargetOverride = $requestTarget;

        return $n;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        HttpMethodValidator::assert($method);
        $n = clone $this;
        $n->method = $method;

        return $n;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $n = clone $this;
        $n->uri = $uri;
        $existingHost = $this->getHeaderLine('Host');
        if ((!$preserveHost || $existingHost === '') && $uri->getHost() !== '') {
            return $n->withHeader('Host', $this->hostHeaderFromUri($uri));
        }

        return $n;
    }

    private function deriveRequestTarget(UriInterface $uri): string
    {
        $path = $uri->getPath();
        if ($path === '') {
            $target = '/';
        } elseif ($path[0] === '/') {
            $target = $path;
        } else {
            $target = '/' . $path;
        }
        if ($uri->getQuery() !== '') {
            $target .= '?' . $uri->getQuery();
        }

        return $target;
    }

    /**
     * Single validation path for request targets, shared by
     * withRequestTarget() and both Request/ServerRequest constructors so
     * a raw CRLF can never reach the request line (request splitting).
     */
    private static function assertRequestTarget(string $requestTarget): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $requestTarget) === 1) {
            throw new \InvalidArgumentException('Invalid request target.');
        }
    }

    /**
     * RFC 9110 §7.2: IPv6 hosts MUST be bracketed in the Host header.
     * Mirrors the bracketing performed by Uri::getAuthority().
     */
    private function hostHeaderFromUri(UriInterface $uri): string
    {
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if ($uri->getPort() !== null) {
            $authority .= ':' . $uri->getPort();
        }

        return $authority;
    }
}
