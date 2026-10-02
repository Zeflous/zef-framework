<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Adapters layer (inbound adapters)
 * Added by the router feature-expansion pass (content negotiation routing).
 */

namespace Zef\Framework\Router;

/**
 * HTTP content negotiation over the `Accept` request header (roadmap:
 * "Content negotiation routing").
 *
 * A route group may declare the representations it serves
 * (`'accepts' => ['application/json']`); the dispatcher then admits the match
 * only when the request's Accept header accepts one of them, and answers 406
 * otherwise. Media-range grammar is the one browsers actually send: an
 * explicit `type/subtype`, a type wildcard, or a universal wildcard, each
 * with an optional `q` weight. A missing Accept header means "anything"
 * (RFC 9110 §12.5.1).
 *
 * Pure functions over strings — no state, no construction (like
 * {@see RoutePatternParser}).
 */
final class ContentNegotiator
{
    /**
     * Parses an Accept header into weighted media ranges (q=0 ranges dropped).
     *
     * @return list<array{type:string,subtype:string,q:float}>
     */
    public static function parseAccept(string $header): array
    {
        $ranges = [];
        foreach (explode(',', $header) as $part) {
            $range = self::parseRange($part);
            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /**
     * True when the request admits at least one of the given representations.
     * An empty representation list imposes no constraint; a missing Accept
     * header accepts anything.
     *
     * @param list<string> $representations
     */
    public static function matches(string $acceptHeader, array $representations): bool
    {
        if ($representations === [] || trim($acceptHeader) === '') {
            return true;
        }
        $ranges = self::parseAccept($acceptHeader);

        return $ranges !== [] && array_any(
            $representations,
            static fn (string $representation): bool => self::representationQuality($representation, $ranges) > 0.0,
        );
    }

    /**
     * Best-matching representation for the request (highest q; input order on
     * ties), or null when none is acceptable.
     *
     * @param list<string> $representations
     */
    public static function select(string $acceptHeader, array $representations): ?string
    {
        if ($representations === []) {
            return null;
        }
        $ranges = trim($acceptHeader) === ''
            ? [['type' => '*', 'subtype' => '*', 'q' => 1.0]]
            : self::parseAccept($acceptHeader);
        $best = null;
        $bestQuality = 0.0;
        foreach ($representations as $representation) {
            $quality = self::representationQuality($representation, $ranges);
            if ($quality > $bestQuality) {
                $bestQuality = $quality;
                $best = $representation;
            }
        }

        return $best;
    }

    /**
     * One comma-separated media range, or null when malformed / q=0.
     *
     * @return null|array{type:string,subtype:string,q:float}
     */
    private static function parseRange(string $part): ?array
    {
        $part = trim($part);
        if ($part === '') {
            return null;
        }
        $segments = explode(';', $part);
        $media = strtolower(trim(array_shift($segments)));
        $slash = explode('/', $media, 2);
        $type = trim($slash[0]);
        $subtype = trim($slash[1] ?? '');
        $q = self::quality($segments);
        if (!str_contains($media, '/') || $type === '' || $subtype === '' || $q <= 0.0) {
            return null;
        }

        return ['type' => $type, 'subtype' => $subtype, 'q' => min($q, 1.0)];
    }

    /**
     * @param list<string> $segments
     */
    private static function quality(array $segments): float
    {
        $q = 1.0;
        foreach ($segments as $param) {
            $param = strtolower(trim($param));
            if (str_starts_with($param, 'q=')) {
                $raw = substr($param, 2);
                $q = is_numeric($raw) ? (float) $raw : 0.0;
            }
        }

        return $q;
    }

    /**
     * @param list<array{type:string,subtype:string,q:float}> $ranges
     */
    private static function representationQuality(string $representation, array $ranges): float
    {
        $representation = strtolower(trim($representation));
        if (!str_contains($representation, '/')) {
            return 0.0;
        }
        $slash = explode('/', $representation, 2);
        $best = 0.0;
        foreach ($ranges as $range) {
            if (self::rangeAccepts($range, $slash[0], $slash[1]) && $range['q'] > $best) {
                $best = $range['q'];
            }
        }

        return $best;
    }

    /**
     * @param array{type:string,subtype:string,q:float} $range
     */
    private static function rangeAccepts(array $range, string $type, string $subtype): bool
    {
        $typeOk = $range['type'] === '*' || $range['type'] === $type;
        $subtypeOk = $range['subtype'] === '*' || $range['subtype'] === $subtype;

        return $typeOk && $subtypeOk;
    }
}
