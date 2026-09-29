<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Exception\StreamOpenException;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Validation\HeaderValidator;
use Zef\Framework\Validation\TrustedHostValidator;

final class RequestFactory
{
    private const string BODY_TOO_LARGE = 'Request body exceeds configured size limit.';

    /**
     * Bug fix #8: simplified superglobal access patterns.
     *
     * v2.6.0: split into fromGlobals() (superglobal adapter) and
     * fromServer() (injectable) so SAPI state can be simulated in tests.
     *
     * Regresi P-12 (issue #171): the ONLY superglobal reader. Every SAPI
     * source array ($_SERVER, $_GET, $_COOKIE, $_POST, $_FILES) is
     * forwarded explicitly so fromServer() itself never consults
     * process-global state — a long-running worker that reuses the process
     * can no longer observe stale/foreign request data through silent
     * fallbacks.
     */
    public static function fromGlobals(
        array $trustedHosts = [],
        array $trustedProxies = [],
        ?RequestBodyPolicy $bodyPolicy = null,
    ): ServerRequestInterface {
        return self::fromServer(
            $_SERVER,
            $trustedHosts,
            $trustedProxies,
            $bodyPolicy,
            is_array($_GET) ? $_GET : [],
            is_array($_COOKIE) ? $_COOKIE : [],
            is_array($_POST) && $_POST !== [] ? $_POST : null,
            $_FILES ?? [],
        );
    }

    /**
     * Injectable variant of fromGlobals(): identical behavior, but never
     * reads superglobals — every SAPI source array is passed explicitly.
     * Omitted (null) $query/$cookies/$uploadedFiles default to empty. The
     * $parsedBody argument mirrors what SAPI would have decoded into $_POST,
     * so it is honored only for the two form media types; every other
     * content type starts as null and is decoded downstream via
     * decodeJsonBody().
     *
     * @param array<string,mixed> $server
     * @param array<mixed> $query
     * @param array<mixed> $cookies
     * @param array<mixed> $parsedBody
     * @param array<mixed> $uploadedFiles
     */
    public static function fromServer(
        array $server,
        array $trustedHosts = [],
        array $trustedProxies = [],
        ?RequestBodyPolicy $bodyPolicy = null,
        ?array $query = null,
        ?array $cookies = null,
        ?array $parsedBody = null,
        ?array $uploadedFiles = null,
    ): ServerRequestInterface {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        // N-13 (issue #176): RFC 9110 §9.1 method tokens are case-sensitive,
        // but ZEF deliberately normalizes the request method to uppercase
        // at this single ingress boundary. Every internal consumer already
        // compares methods case-insensitively (Router::match/add(), the
        // security/CORS/ETag middlewares all strtoupper() before comparing),
        // and userland overwhelmingly writes `getMethod() === 'GET'` —
        // preserving the raw token would silently break those handlers on
        // non-canonical clients while buying nothing the pipeline needs.
        $protocol = self::protocolVersion((string) ($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'));
        $headers = self::extractHeaders($server);
        $uri = self::buildUri($server, $trustedHosts, $trustedProxies);
        // Regresi P-12 (issue #171): a null argument means "not supplied",
        // never "fall back to the superglobal" — cross-request state must
        // not leak into workers that reuse the process.
        $cookies ??= [];
        $query ??= [];
        $uploads = self::normalizeUploads($uploadedFiles ?? []);
        $bodyPolicy ??= new RequestBodyPolicy();
        $contentLengthHeader = (string) ($headers['content-length'][0] ?? '');
        if (
            $contentLengthHeader !== ''
            && ctype_digit($contentLengthHeader)
            && (int) $contentLengthHeader > $bodyPolicy->maxBytes
        ) {
            throw new PayloadTooLargeException(self::BODY_TOO_LARGE);
        }
        $input = fopen('php://input', 'rb');
        if ($input === false) {
            throw new StreamOpenException('Unable to open request input stream.');
        }
        $body = new LimitedInputStream(new Stream($input), $bodyPolicy);
        $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'][0] ?? ''))[0]));
        // Regresi P-12 (issue #171): the injected $parsedBody mirrors what
        // SAPI would have decoded into $_POST, so it is honored only for the
        // two form media types; every other content type starts null and is
        // decoded downstream (decodeJsonBody()).
        $formBody = $parsedBody;
        $parsedBody = null;
        if (
            $contentType === 'application/x-www-form-urlencoded'
            || $contentType === 'multipart/form-data'
        ) {
            $parsedBody ??= $formBody;
        }

        return new ServerRequest(
            $method,
            $uri,
            $server,
            $cookies,
            $query,
            $uploads,
            $parsedBody,
            $headers,
            $body,
            $protocol,
            self::requestTarget($server, $uri),
        );
    }

    /**
     * Enforce application ingress policy for already constructed PSR-7 requests,
     * including persistent workers that do not pass through fromServer().
     *
     * @param list<string> $trustedHosts
     * @param list<string> $trustedProxies
     */
    public static function validateIngress(
        ServerRequestInterface $request,
        array $trustedHosts,
        array $trustedProxies,
        RequestBodyPolicy $bodyPolicy,
    ): ServerRequestInterface {
        if ($trustedHosts !== []) {
            self::assertTrustedRequestHost($request, $trustedHosts, $trustedProxies);
        }

        $length = $request->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > $bodyPolicy->maxBytes) {
            throw new PayloadTooLargeException(self::BODY_TOO_LARGE);
        }
        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $bodyPolicy->maxBytes) {
            throw new PayloadTooLargeException(self::BODY_TOO_LARGE);
        }

        // Read at most maxBytes + 1 before dispatch, even when size/length is
        // unknown or understated. Lazy wrappers alone can be bypassed by a
        // handler that ignores the body or casts a stream to a string.
        $position = $body->isSeekable() ? $body->tell() : null;

        try {
            if ($position !== null) {
                $body->rewind();
            }
            $contents = new LimitedInputStream($body, $bodyPolicy)->getContents();
        } finally {
            if ($position !== null) {
                $body->seek($position);
            }
        }

        // Non-seekable input must remain readable by downstream middleware.
        if ($position === null) {
            // PSR-7 withBody() preserves the request type; MessageInterface's
            // inherited signature does not express that in the local shim.
            /** @var ServerRequestInterface $request */
            $request = $request->withBody(Stream::fromString($contents));
        }

        return $request;
    }

    public static function decodeJsonBody(ServerRequestInterface $request, bool $associative = true): mixed
    {
        $body = $request->getBody();
        $position = null;
        if ($body->isSeekable()) {
            $position = $body->tell();
            $body->rewind();
        }
        $raw = $body->getContents();
        if ($position !== null) {
            $body->seek($position);
        }
        if ($raw === '') {
            return null;
        }

        try {
            return json_decode($raw, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Malformed JSON request body.', 0, $e);
        }
    }

    /**
     * Trusted-host half of validateIngress(): the URI host and the effective
     * Host-header authority must both be trusted before the request passes
     * the application ingress.
     *
     * @param list<string> $trustedHosts
     * @param list<string> $trustedProxies
     */
    private static function assertTrustedRequestHost(
        ServerRequestInterface $request,
        array $trustedHosts,
        array $trustedProxies,
    ): void {
        $validator = new TrustedHostValidator($trustedHosts);
        $uriHost = $request->getUri()->getHost();
        if ($uriHost === '') {
            throw new \InvalidArgumentException('Missing request host.');
        }
        $validator->assertTrusted($uriHost);

        // PSR-7 allows Host to differ from the URI. Honor the same trusted
        // proxy boundary as fromServer(), without trusting forwarded data
        // supplied by arbitrary clients.
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) ? $remote : '';
        if (TrustedProxyMatcher::matches($remote, $trustedProxies) && $request->hasHeader('X-Forwarded-Host')) {
            $authority = self::firstForwardedValue($request->getHeaderLine('X-Forwarded-Host'));
        } elseif ($request->hasHeader('Host')) {
            $authority = $request->getHeaderLine('Host');
        } else {
            $authority = $uriHost;
        }
        [$host] = HostAuthorityParser::parse($authority);
        if ($host === '') {
            throw new \InvalidArgumentException('Missing request host.');
        }
        $validator->assertTrusted($host);
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function buildUri(array $server, array $trustedHosts, array $trustedProxies): Uri
    {
        [$path, $query] = self::splitRequestTarget((string) ($server['REQUEST_URI'] ?? '/'));
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $trusted = TrustedProxyMatcher::matches($remote, $trustedProxies);
        [$host, $forwardedPort] = HostAuthorityParser::parse(self::resolveHostAuthority($server, $trusted));
        $scheme = self::resolveScheme($server, $trusted);
        $port = self::resolvePort($server, $forwardedPort, $scheme);
        $path = self::stripScriptPrefix($server, $path);
        $hostLiteral = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $base = $scheme . '://'
            . ($hostLiteral !== '' ? $hostLiteral : 'localhost')
            . ($port !== null ? ':' . $port : '')
            . $path
            . ($query !== '' ? '?' . $query : '');

        return new Uri($base, $trustedHosts);
    }

    /**
     * Host authority selection: a forwarded Host (only from a trusted
     * proxy) wins, then HTTP_HOST, then SERVER_NAME.
     *
     * @param array<string,mixed> $server
     */
    private static function resolveHostAuthority(array $server, bool $trusted): string
    {
        return $trusted && isset($server['HTTP_X_FORWARDED_HOST'])
            ? self::firstForwardedValue((string) $server['HTTP_X_FORWARDED_HOST'])
            : (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
    }

    /**
     * Effective scheme: request HTTPS state, overridden by a forwarded
     * protocol (only from a trusted proxy).
     *
     * @param array<string,mixed> $server
     */
    private static function resolveScheme(array $server, bool $trusted): string
    {
        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        if (!$trusted || !isset($server['HTTP_X_FORWARDED_PROTO'])) {
            return $scheme;
        }
        $forwardedScheme = strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0]));
        if (!in_array($forwardedScheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Invalid forwarded protocol.');
        }

        return $forwardedScheme;
    }

    /**
     * Effective port: the forwarded authority port wins, otherwise a valid
     * non-default SERVER_PORT is used.
     *
     * @param array<string,mixed> $server
     */
    private static function resolvePort(array $server, ?int $forwardedPort, string $scheme): ?int
    {
        if ($forwardedPort !== null) {
            return $forwardedPort;
        }
        $rawPort = (string) ($server['SERVER_PORT'] ?? '');
        // Non-numeric/0 ports cast to 0 and previously leaked into the
        // base URL, making Uri throw. Ignore malformed values instead.
        $isExplicitPort = ctype_digit($rawPort)
            && (int) $rawPort >= 1
            && (int) $rawPort <= 65535
            && !self::isDefaultSchemePort($scheme, (int) $rawPort);

        return $isExplicitPort ? (int) $rawPort : null;
    }

    /** True for 80/http and 443/https — ports Uri omits from the base URL. */
    private static function isDefaultSchemePort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    /**
     * Strip the front-controller script prefix from the request path so
     * routing sees the application-relative path.
     *
     * @param array<string,mixed> $server
     */
    private static function stripScriptPrefix(array $server, string $path): string
    {
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        $scriptFilename = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $parsedPath = parse_url($script, PHP_URL_PATH);
        $scriptPath = '';
        if ($script !== '' && is_string($parsedPath)) {
            $scriptPath = $parsedPath;
        }
        $scriptBase = $scriptPath !== '' ? basename($scriptPath) : '';
        $filenameBase = $scriptFilename !== '' ? basename($scriptFilename) : '';
        $isFrontControllerScript = $scriptPath !== '' && $filenameBase !== '' && $scriptBase === $filenameBase;
        if (!$isFrontControllerScript) {
            return $path;
        }
        if ($path === $scriptPath) {
            return '/';
        }
        if (str_starts_with($path, $scriptPath . '/')) {
            $path = substr($path, strlen($scriptPath));
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Splits an origin-form request target into [path, query] without
     * parse_url(): a target such as '//foo/bar' is a legal absolute-path
     * reference, but parse_url() would reinterpret it as a network-path
     * (host 'foo') and silently corrupt routing.
     *
     * @return array{0:string,1:string}
     */
    private static function splitRequestTarget(string $target): array
    {
        if ($target === '') {
            return ['/', ''];
        }
        if (!str_starts_with($target, '/')) {
            // Absolute URI or asterisk-form target: delegate to parse_url.
            // parse_url() returns paths WITHOUT a leading '/' here: '*' →
            // path '*', '../' → '../', 'a/b' → 'a/b'. Concatenating such a
            // path after the authority merges it into the HOST
            // ('OPTIONS *' with Host: example.com built http://example.com*).
            // Normalize to '/' — the raw target is still preserved
            // verbatim by requestTarget(), so getRequestTarget() keeps
            // '*' / absolute-form exactly as received.
            $parts = parse_url($target);
            if ($parts === false) {
                throw new \InvalidArgumentException('Malformed REQUEST_URI.');
            }
            $path = (string) ($parts['path'] ?? '');

            return [
                $path !== '' && $path[0] === '/' ? $path : '/',
                (string) ($parts['query'] ?? ''),
            ];
        }
        $query = '';
        $qPos = strpos($target, '?');
        if ($qPos !== false) {
            $query = substr($target, $qPos + 1);
            $target = substr($target, 0, $qPos);
        }
        $hashPos = strpos($target, '#');
        if ($hashPos !== false) {
            $target = substr($target, 0, $hashPos);
        }

        return [$target === '' ? '/' : $target, $query];
    }

    /** @return array<string,list<string>> */
    private static function extractHeaders(array $server, ?EnvInterface $env = null): array
    {
        $env ??= new Env();
        $validator = new HeaderValidator();
        // Inbound header caps (resource-exhaustion backstop). CGI/FPM
        // bound these at the web server; worker / injected-server paths
        // previously had NO limit while the body policy capped at 2 MiB.
        // Issue #55 step 3: the caps flow through the EnvInterface port.
        $maxCount = $env->readInt('ZEF_MAX_HEADER_COUNT', 128, 8, 4096);
        $maxValueBytes = $env->readInt('ZEF_MAX_HEADER_VALUE_BYTES', 16384, 256, 1048576);
        $maxTotalBytes = $env->readInt('ZEF_MAX_HEADERS_TOTAL_BYTES', 65536, 1024, 1048576);
        $headers = [];
        $count = 0;
        $totalBytes = 0;
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $name = match (true) {
                str_starts_with($key, 'HTTP_') => str_replace('_', '-', substr($key, 5)),
                $key === 'CONTENT_TYPE', $key === 'CONTENT_LENGTH' => str_replace('_', '-', $key),
                default => null,
            };
            if ($name === null) {
                continue;
            }
            $valueBytes = strlen($value);
            ++$count;
            if ($count > $maxCount || $valueBytes > $maxValueBytes) {
                throw new PayloadTooLargeException('Request headers exceed the configured size limit.');
            }
            $totalBytes += $valueBytes + strlen($name);
            if ($totalBytes > $maxTotalBytes) {
                throw new PayloadTooLargeException('Request headers exceed the configured size limit.');
            }
            $validator->assertName($name);
            $validator->assertValue($name, $value);
            $headers[strtolower($name)] = [$value];
        }

        return $headers;
    }

    /** @return array<int|string,mixed> */
    private static function normalizeUploads(array $files): array
    {
        $factory = new Psr17Factory();
        $build = function ($name, $type, $tmp, $error, $size) use (&$build, $factory): array|UploadedFileInterface {
            if (is_array($error)) {
                $result = [];
                foreach ($error as $key => $err) {
                    $result[$key] = $build(
                        $name[$key] ?? null,
                        $type[$key] ?? null,
                        $tmp[$key] ?? null,
                        $err,
                        $size[$key] ?? null,
                    );
                }

                return $result;
            }

            return self::buildUploadedLeaf($factory, $name, $type, $tmp, $error, $size);
        };
        $out = [];
        foreach ($files as $field => $spec) {
            if (!is_array($spec) || !array_key_exists('error', $spec)) {
                $out[$field] = $spec;

                continue;
            }
            $out[$field] = $build(
                $spec['name'] ?? null,
                $spec['type'] ?? null,
                $spec['tmp_name'] ?? null,
                $spec['error'],
                $spec['size'] ?? null,
            );
        }

        return $out;
    }

    private static function buildUploadedLeaf(
        Psr17Factory $factory,
        mixed $name,
        mixed $type,
        mixed $tmp,
        mixed $error,
        mixed $size,
    ): UploadedFileInterface {
        $error = self::normalizeUploadError($error);
        $tmp = (string) ($tmp ?? '');
        // err=OK with no tmp_name is a "ghost upload": PHP always
        // provides tmp_name for real uploads. Previously the leaf was
        // built with error=OK and the CLIENT-DECLARED size while the
        // body was empty. Downgrade to NO_FILE so getError() tells
        // the truth.
        if ($error === UPLOAD_ERR_OK && $tmp === '') {
            $error = UPLOAD_ERR_NO_FILE;
        }
        $stream = ($error === UPLOAD_ERR_OK && $tmp !== '')
            ? $factory->createStreamFromFile($tmp, 'rb')
            : $factory->createStream('');

        return $factory->createUploadedFile(
            $stream,
            $size !== null ? (int) $size : null,
            $error,
            is_string($name) ? $name : null,
            is_string($type) ? $type : null,
        );
    }

    /** Coerce a SAPI upload error value to the UPLOAD_ERR_* int it represents. */
    private static function normalizeUploadError(mixed $error): int
    {
        if (is_int($error)) {
            return $error;
        }
        if (is_string($error) && ctype_digit($error)) {
            return (int) $error;
        }

        return UPLOAD_ERR_NO_FILE;
    }

    /**
     * Bug fix #19: HTTP/2 -> '2', HTTP/3 -> '3'.
     */
    private static function protocolVersion(string $protocol): string
    {
        if (preg_match('/^HTTP\/(\d+\.\d+)$/', $protocol, $m) === 1) {
            return $m[1];
        }
        // Single-digit major only: 'HTTP/22' previously produced '22'
        // which then failed MessageBase::assertProtocolVersion one layer
        // deeper. Equally-invalid input must degrade consistently.
        if (preg_match('/^HTTP\/(\d)$/', $protocol, $m) === 1) {
            return $m[1];
        }

        return '1.1';
    }

    private static function requestTarget(array $server, UriInterface $uri): string
    {
        $path = $uri->getPath();
        $target = (string) ($server['REQUEST_URI'] ?? ($path !== '' ? $path : '/'));
        if (preg_match('/[\r\n]/', $target) === 1) {
            throw new \InvalidArgumentException('Invalid request target.');
        }

        return $target;
    }

    private static function firstForwardedValue(string $value): string
    {
        return trim(explode(',', $value, 2)[0]);
    }
}
