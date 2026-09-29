<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Exception\InvalidHeaderException;
use Zef\Framework\Validation\HeaderValidator;

abstract class MessageBase implements MessageInterface
{
    protected string $protocolVersion = '1.1';

    /**
     * @var array<string,array<int,string>>
     */
    protected array $headers = [];
    protected StreamInterface $body;
    protected HeaderValidator $headerValidator;

    /**
     * @var array<string,string> lowercase header name => stored/original header key
     */
    private array $headerNames = [];

    public function __construct(
        ?StreamInterface $body = null,
        array $headers = [],
        string $protocolVersion = '1.1',
    ) {
        $this->body = $body ?? Stream::fromString('');
        // php:S2830: the default validator is built through a private factory
        // instead of a bare `new` in the constructor; the PSR-7 constructor
        // signature is public API and cannot grow an injection point.
        $this->headerValidator = $this->defaultHeaderValidator();
        $this->assertProtocolVersion($protocolVersion);
        $this->protocolVersion = $protocolVersion;
        foreach ($headers as $name => $value) {
            $this->setHeader((string) $name, $value);
        }
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\Override]
    public function withProtocolVersion(string $version): MessageInterface
    {
        $this->assertProtocolVersion($version);
        $n = clone $this;
        $n->protocolVersion = $version;

        return $n;
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->findHeaderKey($name) !== null;
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        $key = $this->findHeaderKey($name);

        return $key === null ? [] : $this->headers[$key];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @param string|string[] $value
     */
    #[\Override]
    public function withHeader(string $name, $value): MessageInterface
    {
        $n = clone $this;
        $n->setHeader($name, $value);

        return $n;
    }

    /**
     * @param mixed $value validated at runtime; string or list<string> accepted
     */
    #[\Override]
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $n = clone $this;
        $n->validateHeader($name, $value);
        $key = strtolower($name);
        $old = $n->headerNames[$key] ?? null;
        $values = array_map(strval(...), is_array($value) ? $value : [$value]);
        if ($old !== null) {
            $n->headers[$old] = array_merge($n->headers[$old], $values);
        } else {
            $n->headers[$name] = $values;
            $n->headerNames[$key] = $name;
        }

        return $n;
    }

    #[\Override]
    public function withoutHeader(string $name): MessageInterface
    {
        $n = clone $this;
        $key = strtolower($name);
        $old = $n->headerNames[$key] ?? null;
        if ($old !== null) {
            unset($n->headers[$old], $n->headerNames[$key]);
        }

        return $n;
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[\Override]
    public function withBody(StreamInterface $body): MessageInterface
    {
        $n = clone $this;
        $n->body = $body;

        return $n;
    }

    public function bodyString(): string
    {
        $pos = null;

        try {
            if ($this->body->isSeekable()) {
                $pos = $this->body->tell();
            }
            $this->body->rewind();
            $s = $this->body->getContents();
            if ($pos !== null) {
                $this->body->seek($pos);
            }

            return $s;
        } catch (\RuntimeException) {
            // N-10 (issue #176): PSR-7 streams signal I/O failures through
            // RuntimeException (Stream itself and PayloadTooLargeException
            // both extend it), so those still degrade to ''. Narrowed from
            // \Throwable: programming errors (TypeError, invalid arguments)
            // now propagate instead of silently masquerading as empty
            // bodies.
            return '';
        }
    }

    /**
     * @param mixed $value validated at runtime; string or list<string> accepted
     */
    protected function addInitialHeader(string $name, array|string $value): void
    {
        $this->setHeader($name, $value);
    }

    /**
     * Bug fix #19: extracted helper; accepts '1.0', '1.1', '2', '2.0', '3'.
     */
    private function assertProtocolVersion(string $version): void
    {
        if (preg_match('/^\d(?:\.\d)?$/', $version) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP protocol version.');
        }
    }

    private function defaultHeaderValidator(): HeaderValidator
    {
        return new HeaderValidator();
    }

    /**
     * @param mixed $value validated at runtime; string or list<string> accepted
     */
    private function setHeader(string $name, array|string $value): void
    {
        $this->validateHeader($name, $value);
        $key = strtolower($name);
        $old = $this->headerNames[$key] ?? null;
        if ($old !== null) {
            unset($this->headers[$old]);
        }
        $this->headers[$name] = array_map(strval(...), is_array($value) ? $value : [$value]);
        $this->headerNames[$key] = $name;
    }

    private function findHeaderKey(string $name): ?string
    {
        return $this->headerNames[strtolower($name)] ?? null;
    }

    /**
     * @param mixed $value validated at runtime; string or list<string> accepted
     */
    private function validateHeader(string $name, array|string $value): void
    {
        $this->headerValidator->assertName($name);
        foreach (is_array($value) ? $value : [$value] as $v) {
            // PSR-7 §3: header values must be strings. Silently coercing
            // null→'' or nested arrays→"Array" stored garbage (and emitted
            // it under default error handling); reject instead.
            if (!is_string($v)) {
                throw new InvalidHeaderException('Header value must be a string.');
            }
            $this->headerValidator->assertValue($name, $v);
        }
    }
}
