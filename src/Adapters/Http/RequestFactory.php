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
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Validation\HeaderValidator;
use Zef\Framework\Validation\TrustedHostValidator;

final class RequestFactory
{
    /**
     * Bug fix #8: simplified superglobal access patterns.
     *
     * v2.6.0: split into fromGlobals() (superglobal adapter) and
     * fromServer() (injectable) so SAPI state can be simulated in tests.
     */
    public static function fromGlobals(
        array $trustedHosts = [],
        array $trustedProxies = [],
        ?RequestBodyPolicy $bodyPolicy = null,
    ): ServerRequestInterface {
        return self::fromServer($_SERVER, $trustedHosts, $trustedProxies, $bodyPolicy);
    }

    /**
     * Injectable variant of fromGlobals(): identical behavior, but reads
     * from an explicit SAPI array instead of the $_SERVER superglobal.
     * The optional $query/$cookies/$parsedBody/$uploadedFiles arguments
     * make the remaining SAPI state injectable too (previously $_GET,
     * $_COOKIE, $_POST and $_FILES leaked through, so a worker reusing
     * the process saw stale/foreign superglobal state).
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $parsedBody
     * @param array<string,mixed> $uploadedFiles
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
        $protocol = self::protocolVersion((string) ($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'));
        $headers = self::extractHeaders($server);
        $uri = self::buildUri($server, $trustedHosts, $trustedProxies);
        $cookies ??= is_array($_COOKIE) ? $_COOKIE : [];
        $query ??= is_array($_GET) ? $_GET : [];
        $uploads = self::normalizeUploads($uploadedFiles ?? $_FILES ?? []);
        $bodyPolicy ??= new RequestBodyPolicy();
        $contentLengthHeader = (string) ($headers['content-length'][0] ?? '');
        if (
            $contentLengthHeader !== ''
            && ctype_digit($contentLengthHeader)
            && (int) $contentLengthHeader > $bodyPolicy->maxBytes
        ) {
            throw new PayloadTooLargeException('Request body exceeds configured size limit.');
        }
        $input = fopen('php://input', 'rb');
        if ($input === false) {
            throw new \RuntimeException('Unable to open request input stream.');
        }
        $body = new LimitedInputStream(new Stream($input), $bodyPolicy);
        $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'][0] ?? ''))[0]));
        $parsedBody = null;
        if (
            $contentType === 'application/x-www-form-urlencoded'
            || $contentType === 'multipart/form-data'
        ) {
            $parsedBody ??= is_array($_POST) && $_POST !== [] ? $_POST : null;
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
            $validator = new TrustedHostValidator($trustedHosts);
            $uriHost = $request->getUri()->getHost();
            if ($uriHost === '') {
                throw new \InvalidArgumentException('Missing request host.');
            }
            $validator->assert($uriHost);

            // PSR-7 allows Host to differ from the URI. Honor the same trusted
            // proxy boundary as fromServer(), without trusting forwarded data
            // supplied by arbitrary clients.
            $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            $remote = is_string($remote) ? $remote : '';
            $authority = TrustedProxyMatcher::matches($remote, $trustedProxies) && $request->hasHeader('X-Forwarded-Host')
                ? self::firstForwardedValue($request->getHeaderLine('X-Forwarded-Host'))
                : ($request->hasHeader('Host') ? $request->getHeaderLine('Host') : $uriHost);
            [$host] = self::parseAuthority($authority);
            if ($host === '') {
                throw new \InvalidArgumentException('Missing request host.');
            }
            $validator->assert($host);
        }

        $length = $request->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int) $length > $bodyPolicy->maxBytes) {
            throw new PayloadTooLargeException('Request body exceeds configured size limit.');
        }
        $body = $request->getBody();
        $size = $body->getSize();
        if ($size !== null && $size > $bodyPolicy->maxBytes) {
            throw new PayloadTooLargeException('Request body exceeds configured size limit.');
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

    private static function buildUri(array $server, array $trustedHosts, array $trustedProxies): Uri
    {
        [$path, $query] = self::splitRequestTarget((string) ($server['REQUEST_URI'] ?? '/'));
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $trusted = TrustedProxyMatcher::matches($remote, $trustedProxies);
        $hostHeader = $trusted && isset($server['HTTP_X_FORWARDED_HOST'])
            ? self::firstForwardedValue((string) $server['HTTP_X_FORWARDED_HOST'])
            : (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        [$host, $forwardedPort] = self::parseAuthority($hostHeader);
        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        if ($trusted && isset($server['HTTP_X_FORWARDED_PROTO'])) {
            $forwardedScheme = strtolower(trim(explode(',', (string) $server['HTTP_X_FORWARDED_PROTO'])[0]));
            if (!in_array($forwardedScheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException('Invalid forwarded protocol.');
            }
            $scheme = $forwardedScheme;
        }
        $port = $forwardedPort;
        if ($port === null && isset($server['SERVER_PORT'])) {
            $rawPort = (string) $server['SERVER_PORT'];
            // Non-numeric/0 ports cast to 0 and previously leaked into the
            // base URL, making Uri throw. Ignore malformed values instead.
            if (
                ctype_digit($rawPort)
                && ($p = (int) $rawPort) >= 1 && $p <= 65535
                && (($scheme === 'http' && $p !== 80) || ($scheme === 'https' && $p !== 443))
            ) {
                $port = $p;
            }
        }
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        $scriptFilename = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $parsedPath = parse_url($script, PHP_URL_PATH);
        $scriptPath = $script !== '' ? (is_string($parsedPath) ? $parsedPath : '') : '';
        $scriptBase = $scriptPath !== '' ? basename($scriptPath) : '';
        $filenameBase = $scriptFilename !== '' ? basename($scriptFilename) : '';
        $isFrontControllerScript = $scriptPath !== '' && $filenameBase !== '' && $scriptBase === $filenameBase;
        if ($isFrontControllerScript) {
            if ($path === $scriptPath) {
                $path = '/';
            } elseif (str_starts_with($path, $scriptPath . '/')) {
                $path = substr($path, strlen($scriptPath));
            }
            if ($path === '') {
                $path = '/';
            }
        }
        $hostLiteral = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $base = $scheme . '://'
            . ($hostLiteral !== '' ? $hostLiteral : 'localhost')
            . ($port !== null ? ':' . $port : '')
            . $path
            . ($query !== '' ? '?' . $query : '');

        return new Uri($base, $trustedHosts);
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
            $name = null;
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = str_replace('_', '-', $key);
            }
            if ($name === null) {
                continue;
            }
            $valueBytes = strlen($value);
            if (++$count > $maxCount || $valueBytes > $maxValueBytes) {
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
            $error = is_int($error)
                ? $error
                : ((is_string($error) && ctype_digit($error)) ? (int) $error : UPLOAD_ERR_NO_FILE);
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

    /** @return array{0:string,1:null|int} */
    private static function parseAuthority(string $authority): array
    {
        $authority = trim($authority);
        // RFC 9112 §3.2: HTTP/1.0 clients may omit the Host header and
        // CLI workers have neither HTTP_HOST nor SERVER_NAME. An empty
        // authority is reported as such so callers can apply their own
        // fallback instead of receiving a hard failure.
        if ($authority === '') {
            return ['', null];
        }
        if (preg_match('~[\x00-\x20\x7f@\/?#]~', $authority) === 1) {
            throw new \InvalidArgumentException('Malformed Host header.');
        }
        $host = $authority;
        $port = null;
        if ($authority[0] === '[') {
            $close = strpos($authority, ']');
            if ($close === false) {
                throw new \InvalidArgumentException('Malformed Host header.');
            }
            $host = substr($authority, 1, $close - 1);
            $rest = substr($authority, $close + 1);
            if ($rest !== '') {
                if (!str_starts_with($rest, ':') || !ctype_digit(substr($rest, 1))) {
                    throw new \InvalidArgumentException('Malformed Host header.');
                }
                $port = (int) substr($rest, 1);
            }
        } elseif (substr_count($authority, ':') === 1) {
            [$host, $portText] = explode(':', $authority, 2);
            if ($portText === '' || !ctype_digit($portText)) {
                throw new \InvalidArgumentException('Malformed Host header.');
            }
            $port = (int) $portText;
        }
        $host = strtolower(trim($host));
        // Strip exactly one FQDN root dot ('example.com.'). Uri's host
        // grammar rejects trailing dots, so the same header must not
        // half-validate here and then crash downstream.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDns = !$isIp
            && self::isValidDnsHost($host)
            && !str_ends_with($host, '.');
        if ($host === '' || (!$isIp && !$isDns)) {
            throw new \InvalidArgumentException('Malformed Host header.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Malformed Host header.');
        }

        return [$host, $port];
    }

    private static function isValidDnsHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $host = rtrim($host, '.');
        if ($host === '') {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            $length = strlen($label);
            if ($length < 1 || $length > 63 || $label[0] === '-' || $label[$length - 1] === '-') {
                return false;
            }
            for ($i = 0; $i < $length; ++$i) {
                $char = $label[$i];
                if (!(($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9') || $char === '-')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function firstForwardedValue(string $value): string
    {
        return trim(explode(',', $value, 2)[0]);
    }
}
