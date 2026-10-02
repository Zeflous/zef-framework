<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Adapters layer (inbound adapters)
 * Added by the router feature-expansion pass (localization routing).
 */

namespace Zef\Framework\Router;

/**
 * Locale prefix negotiation for localized routes (roadmap: "Localization
 * routing (/{locale}/...)").
 *
 * Mirrors the {@see \Zef\Framework\Http\ApiVersionNegotiator} contract: the
 * leading `/{locale}` segment is split off a request path and returned as a
 * locale token only when it is either a registered supported locale or a
 * well-formed BCP-47-ish language tag ("en", "en-US", "pt_BR"). A plain
 * path word ("users", "admin") is never hijacked as a locale — that is what
 * lets `Router::localized()` coexist with ordinary prefix-less routes.
 */
final readonly class LocaleNegotiator
{
    private const string TOKEN_GRAMMAR = '[A-Za-z]{1,8}(?:[_-][A-Za-z0-9]{1,8})?';

    /** @var array<string,true> */
    private array $supported;

    /** @var list<string> */
    private array $locales;

    /**
     * @param list<string> $supported e.g. ['en', 'id', 'en-US']
     */
    public function __construct(array $supported)
    {
        if ($supported === []) {
            throw new \InvalidArgumentException('LocaleNegotiator requires at least one supported locale.');
        }
        foreach ($supported as $locale) {
            if (!is_string($locale) || preg_match('/^' . self::TOKEN_GRAMMAR . '$/', $locale) !== 1) {
                throw new \InvalidArgumentException(
                    'Supported locales must be well-formed language tags, got: '
                    . (is_scalar($locale) ? (string) $locale : get_debug_type($locale)),
                );
            }
        }
        $this->supported = array_fill_keys($supported, true);
        $this->locales = array_values($supported);
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->locales;
    }

    /**
     * Splits a leading /{locale} prefix off a path.
     * "/en/users" -> ["en", "/users"]; "/users" -> [null, "/users"].
     *
     * @return array{?string,string}
     */
    public function splitPathPrefix(string $path): array
    {
        if (preg_match('#^/(' . self::TOKEN_GRAMMAR . ')(/|$)#', $path, $m) === 1) {
            $token = $m[1];
            if (isset($this->supported[$token]) || $this->isWellFormed($token)) {
                $rest = substr($path, strlen($m[0]));

                return [$token, $rest === '' ? '/' : '/' . $rest];
            }
        }

        return [null, $path];
    }

    private function isWellFormed(string $token): bool
    {
        return preg_match('/^' . self::TOKEN_GRAMMAR . '$/', $token) === 1;
    }
}
