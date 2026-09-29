<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\UriInterface;
use Zef\Framework\Validation\PortRangeValidator;
use Zef\Framework\Validation\TrustedHostValidator;

final class Uri implements UriInterface
{
    private const string PATH_ALLOWED = ":/@!$&'()*+,;=-._~";
    private const string QUERY_FRAGMENT_ALLOWED = ":/?@!$&'()*+,;=-._~";
    private const string USERINFO_ALLOWED = "!$&'()*+,;=:";
    private const string SCHEME_PATTERN = '/^[A-Za-z][A-Za-z0-9+.-]*\z/';

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '', private readonly array $trustedHosts = [])
    {
        if ($uri === '') {
            return;
        }
        $this->assertNoControls($uri, 'URI');
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI '{$uri}'.");
        }
        $this->scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $this->assertScheme($this->scheme);
        $user = isset($parts['user']) ? (string) $parts['user'] : '';
        $pass = array_key_exists('pass', $parts) ? (string) $parts['pass'] : null;
        $this->userInfo = $this->encodeComponent($user, self::USERINFO_ALLOWED);
        if ($pass !== null) {
            $this->userInfo .= ':' . $this->encodeComponent($pass, "!$&'()*+,;=");
        }
        // Bug fix #2: parse_url returns '[::1]' WITH brackets for IPv6 hosts.
        // Strip them before assertHost() which uses FILTER_VALIDATE_IP.
        $rawHost = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $rawHost = substr($rawHost, 1, -1);
        }
        $this->host = $rawHost;
        $this->assertHost($this->host);
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        new PortRangeValidator()->assert($this->port);
        $this->path = $this->encodeComponent((string) ($parts['path'] ?? ''), self::PATH_ALLOWED);
        $this->query = $this->encodeComponent((string) ($parts['query'] ?? ''), self::QUERY_FRAGMENT_ALLOWED);
        $this->fragment = $this->encodeComponent((string) ($parts['fragment'] ?? ''), self::QUERY_FRAGMENT_ALLOWED);
        new TrustedHostValidator($this->trustedHosts)->assertTrusted($this->host);
    }

    /**
     * beta2 fix: per RFC 3986 / PSR-7, when an authority component is
     * present the path must be empty or begin with "/".
     */
    #[\Override]
    public function __toString(): string
    {
        $uri = $this->scheme !== '' ? $this->scheme . ':' : '';
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
            $path = $this->path;
            if ($path !== '' && $path[0] !== '/') {
                $path = '/' . $path;
            }
            $uri .= $path;
        } else {
            $uri .= $this->path;
        }
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    #[\Override]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[\Override]
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $displayHost = str_contains($this->host, ':') && !str_starts_with($this->host, '[')
            ? '[' . $this->host . ']'
            : $this->host;
        $authority = ($this->userInfo !== '' ? $this->userInfo . '@' : '') . $displayHost;
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    #[\Override]
    public function getHost(): string
    {
        return $this->host;
    }

    #[\Override]
    public function getPort(): ?int
    {
        return $this->port;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getQuery(): string
    {
        return $this->query;
    }

    #[\Override]
    public function getFragment(): string
    {
        return $this->fragment;
    }

    #[\Override]
    public function withScheme(string $scheme): UriInterface
    {
        $this->assertNoControls($scheme, 'URI scheme');
        $this->assertScheme($scheme);
        $n = clone $this;
        $n->scheme = strtolower($scheme);

        return $n;
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $this->assertNoControls($user, 'URI user info');
        if ($password !== null) {
            $this->assertNoControls($password, 'URI user info');
        }
        $n = clone $this;
        $n->userInfo = $this->encodeComponent($user, self::USERINFO_ALLOWED)
            . ($password !== null ? ':' . $this->encodeComponent($password, "!$&'()*+,;=") : '');

        return $n;
    }

    #[\Override]
    public function withHost(string $host): UriInterface
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $this->assertHost($host);
        $n = clone $this;
        $n->host = strtolower($host);
        new TrustedHostValidator($this->trustedHosts)->assertTrusted($n->host);

        return $n;
    }

    #[\Override]
    public function withPort(?int $port): UriInterface
    {
        new PortRangeValidator()->assert($port);
        $n = clone $this;
        $n->port = $port;

        return $n;
    }

    #[\Override]
    public function withPath(string $path): UriInterface
    {
        $this->assertNoControls($path, 'URI path');
        $n = clone $this;
        $n->path = $this->encodeComponent($path, self::PATH_ALLOWED);

        return $n;
    }

    #[\Override]
    public function withQuery(string $query): UriInterface
    {
        $this->assertNoControls($query, 'URI query');
        $n = clone $this;
        $n->query = $this->encodeComponent($query, self::QUERY_FRAGMENT_ALLOWED);

        return $n;
    }

    #[\Override]
    public function withFragment(string $fragment): UriInterface
    {
        $this->assertNoControls($fragment, 'URI fragment');
        $n = clone $this;
        $n->fragment = $this->encodeComponent($fragment, self::QUERY_FRAGMENT_ALLOWED);

        return $n;
    }

    private function assertNoControls(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException("Invalid {$label} control characters.");
        }
    }

    private function assertScheme(string $scheme): void
    {
        if ($scheme !== '' && preg_match(self::SCHEME_PATTERN, $scheme) !== 1) {
            throw new \InvalidArgumentException('Invalid URI scheme.');
        }
    }

    private function encodeComponent(string $value, string $allowed): string
    {
        $result = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $value[$i];
            $o = ord($ch);
            if (
                $ch === '%'
                && $i + 2 < $len
                && ctype_xdigit($value[$i + 1])
                && ctype_xdigit($value[$i + 2])
            ) {
                $result .= '%' . strtoupper($value[$i + 1] . $value[$i + 2]);
                $i += 2;

                continue;
            }
            if (
                ($o >= 65 && $o <= 90)
                || ($o >= 97 && $o <= 122)
                || ($o >= 48 && $o <= 57)
                || str_contains('-._~' . $allowed, $ch)
            ) {
                $result .= $ch;

                continue;
            }
            $result .= sprintf('%%%02X', $o);
        }

        return $result;
    }

    private function assertHost(string $host): void
    {
        if ($host === '') {
            return;
        }
        $this->assertNoControls($host, 'URI host');
        if (str_contains($host, ':')) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Invalid URI host.');
            }

            return;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return;
        }
        // RFC 3986 reg-name also permits '_' (common for intranet hosts,
        // RFC 9110 Host = reg-name); the DNS-only class rejected it.
        if (
            strlen($host) > 253
            || preg_match(
                '/^(?=.{1,253}$)(?:[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)(?:\.(?:[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?))*$/',
                $host,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid URI host.');
        }
    }
}

/*
 * Shared request-line behaviour extracted from Request and ServerRequest.
 * Eliminates the duplicate deriveRequestTarget / getRequestTarget /
 * withRequestTarget / getMethod / withMethod / getUri / withUri / Host logic.
 */
