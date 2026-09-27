<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Conditional GET middleware (RFC 9110 §13).
 *
 * For successful GET/HEAD responses with a non-empty body it computes a
 * strong SHA-256 ETag and evaluates the request's preconditions:
 *
 * - `If-None-Match` (ETag comparison, `*` and weak `W/` forms supported)
 * - `If-Modified-Since` (only when the handler supplied `Last-Modified`)
 *
 * When a precondition matches, a body-less 304 with the validator headers is
 * returned so caches can reuse their copy.
 *
 * OPT-IN: register the service and add it to the `middleware.stack` of the
 * application that wants conditional requests. It is intentionally NOT part
 * of the default stack.
 */
final class ETagMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }
        $body = (string) $response->getBody();
        $lastModified = $response->getHeaderLine('Last-Modified');
        if ($body === '' && $lastModified === '') {
            return $response;
        }
        $etag = $body !== '' ? '"' . bin2hex(hash('sha256', $body, true)) . '"' : '';

        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($etag !== '' && $ifNoneMatch !== '' && self::ifNoneMatchMatches($ifNoneMatch, $etag)) {
            return $this->notModified($response, $etag);
        }

        $ifModifiedSince = trim($request->getHeaderLine('If-Modified-Since'));
        if (
            $etag === ''
            && $lastModified !== ''
            && $ifModifiedSince !== ''
            && self::notModifiedSince($ifModifiedSince, $lastModified)
        ) {
            return $this->notModified($response, '');
        }

        if ($etag !== '' && !$response->hasHeader('ETag')) {
            return $response->withHeader('ETag', $etag);
        }

        return $response;
    }

    /**
     * RFC 9110 §13.1.2 If-None-Match evaluation.
     * Accepts `*`, comma-separated lists, and weak `W/"..."` forms.
     */
    public static function ifNoneMatchMatches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '*') {
            return true;
        }
        $canonical = self::stripWeakness($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (self::stripWeakness($candidate) === $canonical) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 9110 §13.1.3 If-Modified-Since evaluation: 304 when the stored
     * representation was last modified at or before the client's timestamp.
     * Invalid dates are ignored (treated as "no precondition").
     */
    public static function notModifiedSince(string $ifModifiedSince, string $lastModified): bool
    {
        $client = self::parseHttpDate($ifModifiedSince);
        $server = self::parseHttpDate($lastModified);
        if (!$client instanceof \DateTimeImmutable || !$server instanceof \DateTimeImmutable) {
            return false;
        }

        // Truncate to whole seconds (HTTP dates carry no sub-second precision).
        return $server->getTimestamp() <= $client->getTimestamp();
    }

    private function notModified(ResponseInterface $response, string $etag): ResponseInterface
    {
        $response = $response
            ->withStatus(304)
            ->withBody(Stream::fromString(''))
            // ZEF-DEEP-05: a 304 MUST NOT contain content (RFC 9110 §15.4.5).
            // The 200's Content-Length describes a payload that will never
            // arrive; the SAPI emitter reconciles lying framing headers, but
            // RoadRunnerRuntime forwards headers verbatim — a stale
            // Content-Length can hang sloppy clients/proxies waiting for
            // octets that never come. Content-Type is kept (harmless
            // representation metadata, widely sent by real servers).
            ->withoutHeader('Content-Length')
        ;
        if ($etag !== '') {
            return $response->withHeader('ETag', $etag);
        }

        return $response;
    }

    private static function stripWeakness(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/') || str_starts_with($etag, 'w/')) {
            $etag = substr($etag, 2);
        }

        return trim($etag, '"');
    }

    private static function parseHttpDate(string $value): ?\DateTimeImmutable
    {
        $formats = [
            'D, d M Y H:i:s T', // RFC 5322/1123 (preferred)
            'D, d M Y H:i:s',   // lenient: missing GMT
            'l, d M Y H:i:s T', // RFC 850 (obsolete)
            'l, d M Y H:i:s',
        ];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('GMT'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone(new \DateTimeZone('GMT'));
            }
        }

        return null;
    }
}
