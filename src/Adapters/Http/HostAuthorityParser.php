<?php

declare(strict_types=1);

/*
 * ZEF Framework — HTTP authority parsing (Host / forwarded authority).
 *
 * Extracted from RequestFactory as part of the overall-codebase
 * zero-debt campaign (S1448 remediation): the buildUri() decomposition
 * rounds grew RequestFactory past the 20-method SonarCloud gate, and
 * the authority grammar is a self-contained, dependency-free cluster —
 * the RFC 9112 §3.2 authority shape, RFC 9110 reg-name / IPv6 bracket
 * literals, and FQDN root-dot tolerance. Move-only extraction: every
 * message and branch is byte-identical to the pre-extraction code.
 */

namespace Zef\Framework\Http;

final class HostAuthorityParser
{
    private const string MALFORMED_HOST = 'Malformed Host header.';

    /** @return array{0:string,1:null|int} */
    public static function parse(string $authority): array
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
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }
        [$host, $port] = str_starts_with($authority, '[')
            ? self::parseBracketedAuthority($authority)
            : self::parseBareAuthority($authority);
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
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }

        return [$host, $port];
    }

    /** @return array{0:string,1:null|int} */
    private static function parseBracketedAuthority(string $authority): array
    {
        $close = strpos($authority, ']');
        if ($close === false) {
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }
        $host = substr($authority, 1, $close - 1);
        $rest = substr($authority, $close + 1);
        if ($rest === '') {
            return [$host, null];
        }
        if (!str_starts_with($rest, ':') || !ctype_digit(substr($rest, 1))) {
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }

        return [$host, (int) substr($rest, 1)];
    }

    /** @return array{0:string,1:null|int} */
    private static function parseBareAuthority(string $authority): array
    {
        if (substr_count($authority, ':') !== 1) {
            return [$authority, null];
        }
        [$host, $portText] = explode(':', $authority, 2);
        if ($portText === '' || !ctype_digit($portText)) {
            throw new \InvalidArgumentException(self::MALFORMED_HOST);
        }

        return [$host, (int) $portText];
    }

    private static function isValidDnsHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        return self::isValidTrimmedDnsHost(rtrim($host, '.'));
    }

    private static function isValidTrimmedDnsHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        return array_all(explode('.', $host), fn (string $label): bool => self::isValidDnsLabel($label));
    }

    private static function isValidDnsLabel(string $label): bool
    {
        $length = strlen($label);
        if ($length < 1 || $length > 63 || $label[0] === '-' || $label[$length - 1] === '-') {
            return false;
        }
        for ($i = 0; $i < $length; ++$i) {
            $char = $label[$i];
            // N-11 (issue #176): '_' is accepted to align with
            // Uri::assertHost()'s RFC 3986 reg-name grammar — RFC 9110
            // defines Host as reg-name and '_' is unreserved, so
            // intranet names like "my_service.internal" must validate
            // identically at both layers instead of being accepted by
            // Uri and rejected at the ingress boundary. The allowed
            // alphabet (lowercase alphanumerics, '-' and '_') is
            // expressed as a strpbrk charset so the branch stays a
            // single, flat condition.
            if (strpbrk($char, 'abcdefghijklmnopqrstuvwxyz0123456789-_') === false) {
                return false;
            }
        }

        return true;
    }
}
