<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: cURL-backed S3HttpTransport (default production wire).
 *
 * The cURL extension is required lazily and mapped onto StorageException:
 * an environment without ext-curl surfaces as a clear storage error at the
 * call site (curl_init() throws Error on a missing function) instead of a
 * wiring-time crash. The request/response plumbing around the curl calls
 * (header building, response-header parsing) is pure and public so it can
 * be exercised — and mutation-tested — without any network or extension.
 */

namespace Zef\Framework\Storage;

final class CurlS3HttpTransport implements S3HttpTransport
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;
    private const int TOTAL_TIMEOUT_SECONDS = 30;

    #[\Override]
    public function request(string $method, string $url, array $headers, string $body): S3HttpResponse
    {
        if ($method === '') {
            throw new StorageException('Object storage transport requires an HTTP method.');
        }

        try {
            $handle = curl_init($url);
        } catch (\Error) {
            // curl_init() is undefined without ext-curl (function call → Error).
            throw new StorageException('The cURL extension is required by the S3-compatible storage transport.');
        }
        if ($handle === false) {
            throw new StorageException('Unable to initialise the cURL handle for object storage.');
        }
        $rawHeaders = [];

        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => self::headerLines($headers),
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rawHeaders): int {
                    $rawHeaders[] = $line;

                    return strlen($line);
                },
            ];
            if ($body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            // A HEAD Content-Length describes the object, not a response body.
            if ($method === 'HEAD') {
                $options[CURLOPT_NOBODY] = true;
            }
            curl_setopt_array($handle, $options);
            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                throw new StorageException('Object storage transport error: ' . curl_error($handle));
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
        if (!is_string($responseBody)) {
            throw new StorageException('Object storage transport returned a non-string body.');
        }

        return new S3HttpResponse($status, self::parseHeaders($rawHeaders), $responseBody);
    }

    /**
     * Serialize request headers into the wire format curl expects.
     *
     * @param array<string,string> $headers
     *
     * @return list<string>
     */
    public static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * Parse raw header lines into the lowercase-name → values map
     * (status lines and separators are skipped).
     *
     * @param list<string> $rawHeaders
     *
     * @return array<string, list<string>>
     */
    public static function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                continue; // status line / separators
            }
            $colon = (int) strpos($trimmed, ':');
            $name = strtolower(trim(substr($trimmed, 0, $colon)));
            $value = trim(substr($trimmed, $colon + 1));
            $headers[$name][] = $value;
        }

        return $headers;
    }
}
