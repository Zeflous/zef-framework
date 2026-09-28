<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: object-storage adapter for S3-compatible HTTP backends.
 *
 * Works against AWS S3 (path-style) and every S3-compatible server
 * (MinIO, Ceph RGW, Cloudflare R2, Google Cloud Storage via its S3-
 * compatible XML API with HMAC credentials) — the endpoint is an explicit
 * constructor argument. Signature: in-house AWS SigV4 ({@see SigV4}),
 * zero SDK dependency, honoring the house dependency policy (no new
 * composer requires; the HTTP wire is ext-curl, XML parsing is
 * ext-simplexml — both surfaced via composer suggest).
 */

namespace Zef\Framework\Storage;

final readonly class S3CompatibleStorage implements ObjectStorageInterface
{
    private const string BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    /** Pagination safety cap (P-9, issue #169): pages per list() call. */
    private const int MAX_LIST_PAGES = 1000;

    private string $baseUrl;
    private string $hostHeader;

    public function __construct(
        private string $bucket,
        private string $region,
        private string $accessKeyId,
        private string $secretAccessKey,
        private S3HttpTransport $http,
        ?string $endpoint = null,
    ) {
        if (preg_match(self::BUCKET_PATTERN, $bucket) !== 1) {
            throw new \InvalidArgumentException('Bucket name must be a valid S3 bucket (3..63 lowercase chars).');
        }
        if ($region === '' || strlen($region) > 64 || preg_match('/\s/', $region) === 1) {
            throw new \InvalidArgumentException('Region must be 1..64 characters without whitespace.');
        }
        if ($accessKeyId === '' || strlen($accessKeyId) > 256) {
            throw new \InvalidArgumentException('Access key ID must be 1..256 bytes.');
        }
        if ($secretAccessKey === '' || strlen($secretAccessKey) > 256) {
            throw new \InvalidArgumentException('Secret access key must be 1..256 bytes.');
        }
        $base = $endpoint ?? ('https://s3.' . $region . '.amazonaws.com');
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Endpoint must be an absolute http(s) URL.');
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            throw new \InvalidArgumentException('Endpoint must not carry a path component.');
        }
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';
        $this->hostHeader = strtolower($parts['host']) . $port;
        $this->baseUrl = $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    #[\Override]
    public function put(string $key, string $contents): void
    {
        StorageKeys::assertValidKey($key);
        $response = $this->signed('PUT', $key, [], $contents);
        $this->assertSuccess($response, "PUT object '{$key}'");
    }

    #[\Override]
    public function get(string $key): string
    {
        StorageKeys::assertValidKey($key);
        $response = $this->signed('GET', $key, [], '');
        if ($response->status === 404) {
            throw ObjectNotFoundException::forKey($key);
        }
        $this->assertSuccess($response, "GET object '{$key}'");

        return $response->body;
    }

    #[\Override]
    public function delete(string $key): void
    {
        StorageKeys::assertValidKey($key);
        $response = $this->signed('DELETE', $key, [], '');
        // Idempotent by contract: 2xx covers S3's 204-on-missing; 404 keeps
        // the contract explicit for compat servers that do return 404.
        if ($response->status !== 404) {
            $this->assertSuccess($response, "DELETE object '{$key}'");
        }
    }

    #[\Override]
    public function exists(string $key): bool
    {
        StorageKeys::assertValidKey($key);
        $response = $this->signed('HEAD', $key, [], '');
        if ($response->status === 404) {
            return false;
        }
        $this->assertSuccess($response, "HEAD object '{$key}'");

        return true;
    }

    #[\Override]
    public function stat(string $key): ?ObjectStat
    {
        StorageKeys::assertValidKey($key);
        $response = $this->signed('HEAD', $key, [], '');
        if ($response->status === 404) {
            return null;
        }
        $this->assertSuccess($response, "HEAD object '{$key}'");
        $length = $response->header('Content-Length');
        $lastModified = $response->header('Last-Modified');
        if ($length === null || !ctype_digit($length) || $lastModified === null) {
            throw new StorageException("HEAD response for '{$key}' is missing metadata headers.");
        }
        $modifiedAt = strtotime($lastModified);
        if ($modifiedAt === false) {
            throw new StorageException("HEAD response for '{$key}' carries an unparsable Last-Modified.");
        }

        return new ObjectStat($key, (int) $length, $modifiedAt * 1_000_000_000);
    }

    #[\Override]
    public function list(string $prefix = '', int $limit = 1000): array
    {
        StorageKeys::assertValidPrefix($prefix);
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Listing limit must be 1..1000.');
        }
        // ListObjectsV2 against the bucket root; path-style URL below. S3
        // may truncate the answer at one page, so the continuation token is
        // followed until the listing completes (P-9, issue #169). S3 lists
        // keys lexicographically and that order is preserved as-is — a local
        // re-sort would corrupt the merged multi-page ordering.
        $keys = [];
        $token = null;
        for ($page = 0;; ++$page) {
            if ($page === self::MAX_LIST_PAGES) {
                // Safety counter: a malformed or hostile pagination stream
                // must fail loudly instead of looping forever.
                throw new StorageException(
                    'S3 listing exceeded the pagination safety cap of ' . self::MAX_LIST_PAGES . ' pages.',
                );
            }
            $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => (string) $limit];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $response = $this->signed('GET', '', $query, '');
            $this->assertSuccess($response, 'LIST objects');
            $pageKeys = $this->parseListResponse($response);
            foreach ($pageKeys['keys'] as $key) {
                $keys[] = $key;
            }
            $token = $pageKeys['token'];
            if ($token === null || count($keys) >= $limit) {
                break;
            }
        }

        return array_slice($keys, 0, $limit);
    }

    /**
     * Build, sign and dispatch one request; returns the raw response.
     *
     * @param array<string,string> $query raw query pairs (encoded by SigV4)
     */
    private function signed(string $method, string $key, array $query, string $body): S3HttpResponse
    {
        $encodedKey = $key === ''
            ? ''
            : implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $canonicalPath = '/' . $this->bucket . ($encodedKey === '' ? '' : '/' . $encodedKey);
        $url = $this->baseUrl . $canonicalPath;
        $queryLine = SigV4::canonicalQuery($query);
        if ($queryLine !== '') {
            $url .= '?' . $queryLine;
        }
        $amzDate = gmdate('Ymd\THis\Z');
        $payloadHash = SigV4::payloadHash($body);
        $headers = [
            'host' => $this->hostHeader,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
        ];
        $headers['Authorization'] = SigV4::authorization(
            $method,
            $canonicalPath,
            $query,
            $headers,
            $payloadHash,
            $this->accessKeyId,
            $this->secretAccessKey,
            $this->region,
            $amzDate,
        );

        return $this->http->request($method, $url, $headers, $body);
    }

    private function assertSuccess(S3HttpResponse $response, string $operation): void
    {
        if ($response->status >= 200 && $response->status < 300) {
            return;
        }
        $snippet = trim((string) preg_replace('/\s+/', ' ', substr($response->body, 0, 256)));

        throw new StorageException(
            $operation . ' failed with HTTP status ' . $response->status
            . ($snippet === '' ? '.' : ': ' . $snippet),
        );
    }

    /**
     * Extract the object keys and the continuation marker from a
     * ListObjectsV2 document (namespace-aware: MinIO/Ceph emit the
     * 2006-03-01 default xmlns, AWS omits it). The token is non-null exactly
     * when the page reports IsTruncated=true.
     *
     * @return array{keys: list<string>, token: null|string}
     */
    private function parseListResponse(S3HttpResponse $response): array
    {
        if (!\function_exists('simplexml_load_string')) {
            throw new StorageException('The SimpleXML extension is required to parse S3 listing responses.');
        }
        $xml = @simplexml_load_string($response->body);
        if ($xml === false) {
            throw new StorageException('S3 listing response is not valid XML.');
        }
        $namespaces = $xml->getDocNamespaces();
        $root = isset($namespaces['']) && (string) $namespaces[''] !== ''
            ? $xml->children((string) $namespaces[''])
            : $xml;
        $keys = [];
        foreach ($root->Contents as $entry) {
            $keys[] = (string) $entry->Key;
        }
        if (strtolower(trim((string) $root->IsTruncated)) !== 'true') {
            return ['keys' => $keys, 'token' => null];
        }
        $token = trim((string) $root->NextContinuationToken);
        if ($token === '') {
            // Truncated without a token is a malformed V2 document: fail
            // loudly rather than silently reporting a partial page (P-9).
            throw new StorageException('S3 listing response is truncated without a continuation token.');
        }

        return ['keys' => $keys, 'token' => $token];
    }
}
