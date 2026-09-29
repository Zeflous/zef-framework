<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Exception\UnreadableResponseBodyException;

final class ResponseEmitter
{
    public function emit(
        ResponseInterface $response,
        bool $closeBody = false,
        bool $suppressBody = false,
    ): void {
        if (!headers_sent()) {
            $response = $this->emitHeaders($response);
        }
        $this->emitBody($response, $suppressBody, $closeBody);
    }

    /**
     * Sends the status line and the validated headers; returns the response
     * with a lying Content-Length header stripped when it cannot be
     * reconciled against the actual stream length.
     */
    private function emitHeaders(ResponseInterface $response): MessageInterface
    {
        $status = $response->getStatusCode();
        // Foreign PSR-7 implementations skip Zef's construction-time
        // validation: re-validate here and fail closed (skip the
        // header) instead of trusting attacker-shaped payloads.
        if ($status >= 100 && $status <= 599) {
            http_response_code($status);
        }
        $validator = new Validation\HeaderValidator();
        // A lying Content-Length (declared != octets actually sent)
        // truncates clients and desyncs keep-alive proxies (RFC 9110
        // §8.6; CL.CL smuggling). Reconcile against the stream.
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '') {
            $response = $this->reconcileContentLength($response, $response->getBody(), $declared);
        }
        $this->sendHeaders($response, $validator);

        return $response;
    }

    private function reconcileContentLength(
        ResponseInterface $response,
        StreamInterface $body,
        string $declared,
    ): MessageInterface {
        $size = $body->getSize();
        $consumed = $body->isSeekable() ? $body->tell() : null;
        $remaining = ($size !== null && $consumed !== null) ? max(0, $size - $consumed) : $size;
        if (!ctype_digit($declared) || $remaining === null || (int) $declared !== $remaining) {
            return $response->withoutHeader('Content-Length');
        }

        return $response;
    }

    private function sendHeaders(MessageInterface $response, Validation\HeaderValidator $validator): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            foreach (!is_array($values) ? [$values] : $values as $value) {
                try {
                    $validator->assertName((string) $name);
                    $validator->assertValue((string) $name, (string) $value);
                } catch (\Throwable) {
                    continue;
                }
                header($name . ': ' . $value, false);
            }
        }
    }

    private function emitBody(MessageInterface $response, bool $suppressBody, bool $closeBody): void
    {
        $body = $response->getBody();

        try {
            if (!$suppressBody && self::bodyEligible($response)) {
                if (!$body->isReadable()) {
                    throw new UnreadableResponseBodyException('Response body stream is not readable.');
                }
                $this->echoStream($body);
            }
        } finally {
            if ($closeBody) {
                $body->close();
            }
        }
    }

    /**
     * Whether the status code carries a payload at all (1xx is
     * informational-only; 204/205/304 forbid a body by RFC 9110).
     */
    private static function bodyEligible(MessageInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status !== 204 && $status !== 205 && $status !== 304;
    }

    private function echoStream(StreamInterface $body): void
    {
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            echo $chunk;
        }
    }
}
