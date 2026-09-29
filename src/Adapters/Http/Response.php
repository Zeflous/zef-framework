<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Constant\HttpReasonPhrases;
use Zef\Framework\Validation\HttpStatusValidator;

final class Response extends MessageBase implements ResponseInterface
{
    private int $status;
    private string $reasonPhrase;

    public function __construct(
        int $status = 200,
        array $headers = [],
        StreamInterface|string $body = '',
        string $reasonPhrase = '',
        string $protocolVersion = '1.1',
    ) {
        $bodyStream = is_string($body) ? Stream::fromString($body) : $body;
        parent::__construct($bodyStream, $headers, $protocolVersion);
        self::assertStatus($status);
        $this->assertReasonPhrase($reasonPhrase);
        $this->status = $status;
        $this->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (HttpReasonPhrases::MAP[$status] ?? '');
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->status;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        self::assertStatus($code);
        $this->assertReasonPhrase($reasonPhrase);
        $n = clone $this;
        $n->status = $code;
        $n->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (HttpReasonPhrases::MAP[$code] ?? '');

        return $n;
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /** Validates $status against the shared RFC 9110 status grammar. */
    private static function assertStatus(int $status): void
    {
        new HttpStatusValidator()->assert($status);
    }

    /**
     * RFC 9110 §15.1: reason-phrase = *( HTAB / SP / VCHAR / obs-text ).
     * CR/LF/NUL in the phrase would corrupt any status-line serializer.
     */
    private function assertReasonPhrase(string $reasonPhrase): void
    {
        if (preg_match('/^[\t\x20-\x7E\x80-\xFF]*$/', $reasonPhrase) !== 1) {
            throw new \InvalidArgumentException('Invalid reason phrase.');
        }
    }
}
