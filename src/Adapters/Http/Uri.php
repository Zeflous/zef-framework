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
        $parts = UriGrammar::parse($uri);
        $this->scheme = $parts['scheme'];
        $this->userInfo = $parts['userInfo'];
        $this->host = $parts['host'];
        $this->port = $parts['port'];
        $this->path = $parts['path'];
        $this->query = $parts['query'];
        $this->fragment = $parts['fragment'];
        $this->assertPortInRange($this->port);
        $this->assertTrustedHost($this->host);
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
            $effectivePath = $this->path;
            if ($effectivePath !== '' && $effectivePath[0] !== '/') {
                $effectivePath = '/' . $effectivePath;
            }
            $uri .= $effectivePath;
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
        if ($this->port !== null && !UriGrammar::isDefaultPortForScheme($this->scheme, $this->port)) {
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

    /**
     * N-7 (issue #176): PSR-7 recommends omitting a port that is the
     * scheme's default — this getter reports null for http:80 and
     * https:443, aligning Uri with RequestFactory's own buildUri(),
     * which already drops default SERVER_PORT values. The property keeps
     * the explicitly-parsed port so a later withScheme() flip re-exposes
     * a now non-default port.
     */
    #[\Override]
    public function getPort(): ?int
    {
        if ($this->port === null || UriGrammar::isDefaultPortForScheme($this->scheme, $this->port)) {
            return null;
        }

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
        UriGrammar::assertNoControls($scheme, 'URI scheme');
        UriGrammar::assertScheme($scheme);
        $n = clone $this;
        $n->scheme = strtolower($scheme);

        return $n;
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        UriGrammar::assertNoControls($user, 'URI user info');
        if ($password !== null) {
            UriGrammar::assertNoControls($password, 'URI user info');
        }
        $n = clone $this;
        $n->userInfo = UriGrammar::encodeComponent($user, UriGrammar::USERINFO_ALLOWED)
            . ($password !== null ? ':' . UriGrammar::encodeComponent($password, UriGrammar::PASSWORD_ALLOWED) : '');

        return $n;
    }

    #[\Override]
    public function withHost(string $host): UriInterface
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        UriGrammar::assertHost($host);
        $n = clone $this;
        $n->host = strtolower($host);
        $this->assertTrustedHost($n->host);

        return $n;
    }

    #[\Override]
    public function withPort(?int $port): UriInterface
    {
        $this->assertPortInRange($port);
        $n = clone $this;
        $n->port = $port;

        return $n;
    }

    #[\Override]
    public function withPath(string $path): UriInterface
    {
        UriGrammar::assertNoControls($path, 'URI path');
        $n = clone $this;
        $n->path = UriGrammar::encodeComponent($path, UriGrammar::PATH_ALLOWED);

        return $n;
    }

    #[\Override]
    public function withQuery(string $query): UriInterface
    {
        UriGrammar::assertNoControls($query, 'URI query');
        $n = clone $this;
        $n->query = UriGrammar::encodeComponent($query, UriGrammar::QUERY_FRAGMENT_ALLOWED);

        return $n;
    }

    #[\Override]
    public function withFragment(string $fragment): UriInterface
    {
        UriGrammar::assertNoControls($fragment, 'URI fragment');
        $n = clone $this;
        $n->fragment = UriGrammar::encodeComponent($fragment, UriGrammar::QUERY_FRAGMENT_ALLOWED);

        return $n;
    }

    private function assertPortInRange(?int $port): void
    {
        new PortRangeValidator()->assert($port);
    }

    private function assertTrustedHost(string $host): void
    {
        new TrustedHostValidator($this->trustedHosts)->assert($host);
    }
}
