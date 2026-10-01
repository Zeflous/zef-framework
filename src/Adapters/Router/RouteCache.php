<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapter helpers)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Router;

use Zef\Framework\Exception\RouteCacheException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * Route cache: export a Router to a compiled PHP file and restore it later.
 *
 * The compiled artifact is pure data (`<?php return array(...);` produced by
 * var_export), loads with a plain include, and Router::fromCompiledArray()
 * restores a frozen, radix-compiled router without re-validating any route —
 * the cold-start path for large route tables.
 *
 * Write is atomic: temp file in the same directory + rename.
 *
 * Staleness fingerprint (v2.31.0, Regresi I-7 / issue #175): the file is an
 * ENVELOPE — the framework {@see ZefVersion} stamp, a SHA-256
 * {@see fingerprint} of the exported route table, and the table itself —
 * mirroring the ConfigCompiler/RadixTreeCache cache contract. {@see load()}
 * refuses to restore verbatim data that fails either check (legacy
 * pre-v2.31.0 bare arrays carry no stamp at all), and {@see loadIfFresh()}
 * is the soft-miss read that also compares the fingerprint against the
 * CURRENT route table, so a cache written before a route change is never
 * served as if it were current.
 */
final class RouteCache
{
    public static function export(Router $router): array
    {
        return $router->exportRoutes();
    }

    public static function write(Router $router, string $path): void
    {
        $data = self::export($router);
        $exported = var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => self::fingerprint($data),
            'routes' => $data,
        ], true);
        $payload = sprintf(
            "<?php\n\n%s\n// Regenerate with RouteCache::write() after every route change.\n\nreturn %s;\n",
            '// Compiled ZEF route cache — do not edit by hand.',
            $exported,
        );
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true)) {
            throw new RouteCacheException("Cannot create route-cache directory '{$directory}'.");
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RouteCacheException("Cannot write route cache '{$tmp}'.");
        }
        if (!rename($tmp, $path)) {
            // Cleanup of $tmp, a name this method generated itself
            // ($path . '.' . bin2hex(random_bytes(6)) . '.tmp'). No request input
            // reaches the argument; this runs only when the rename immediately above failed.
            // Registered as an accepted suppression: docs/security/php-sast.md §7.
            if (is_file($tmp)) {
                unlink($tmp); // nosemgrep: php.lang.security.unlink-use
            }

            throw new RouteCacheException("Cannot finalize route cache '{$path}'.");
        }
    }

    /**
     * Restore a router from a cache written by {@see write()}.
     *
     * Fail-closed staleness contract (Regresi I-7, issue #175): the envelope
     * must carry THIS framework version stamp and a fingerprint that matches
     * the route table it contains. A legacy pre-v2.31.0 bare route array, a
     * cache from another framework release, or a corrupt/hand-edited payload
     * throws instead of silently restoring — the caller regenerates.
     */
    public static function load(string $path): Router
    {
        if (!is_file($path)) {
            throw new RouteCacheException("Route cache file '{$path}' does not exist.");
        }
        $data = include $path;
        if (!is_array($data)) {
            throw new RouteCacheException("Route cache file '{$path}' did not return an array.");
        }
        $version = $data['version'] ?? null;
        if ($version !== ZefVersion::VERSION) {
            $origin = is_string($version)
                ? "framework version '{$version}'"
                : 'no version stamp (legacy pre-v2.31.0 bare route array)';

            throw new RouteCacheException("Route cache file '{$path}' is stale ({$origin}; running "
                . ZefVersion::VERSION . '). Regenerate it with RouteCache::write().');
        }
        $routes = $data['routes'] ?? null;
        if (!is_array($routes)) {
            $message = "Route cache file '{$path}' has no route table.";

            throw new RouteCacheException($message . ' Regenerate it with RouteCache::write().');
        }
        if (($data['fingerprint'] ?? null) !== self::fingerprint($routes)) {
            $message = "Route cache file '{$path}' failed its staleness fingerprint check (corrupt or hand-edited).";

            throw new RouteCacheException($message . ' Regenerate it with RouteCache::write().');
        }

        return Router::fromCompiledArray($routes);
    }

    /**
     * Soft-miss read (Regresi I-7, issue #175): the cached router ONLY when
     * the cache was written by this framework version for EXACTLY the route
     * table that $current holds now. Every miss — file absent or unreadable,
     * corrupt include, legacy bare array, cross-release stamp, envelope
     * tampering, or route-table drift since the write — returns null, and
     * the caller keeps serving from the live $current router (whether to
     * rebuild the cache stays the caller's decision, mirroring
     * RadixTreeCache::read()). This is the read the compile/warm pipeline
     * should call: a stale cache is never restored verbatim.
     */
    public static function loadIfFresh(string $path, Router $current): ?Router
    {
        $envelope = self::readFreshEnvelope($path);
        if ($envelope !== null) {
            $currentFingerprint = self::fingerprint($current->exportRoutes());
            if ($envelope['fingerprint'] === $currentFingerprint
                && self::fingerprint($envelope['routes']) === $currentFingerprint) {
                return Router::fromCompiledArray($envelope['routes']);
            }
        }

        return null;
    }

    /**
     * Reads and structurally validates the cache envelope (version stamp,
     * route table, stored fingerprint): any structural miss — file absent
     * or unreadable, corrupt include, non-array payload, legacy bare
     * array, cross-release stamp — yields null.
     *
     * @return null|array{routes: array<mixed>, fingerprint: string}
     */
    private static function readFreshEnvelope(string $path): ?array
    {
        $data = self::includeEnvelope($path);
        if ($data === null) {
            // @infection-ignore-all ReturnRemoval — ekuivalen: tanpa early return, $data null membuat $routes/$stored null dan guard berikutnya tetap mengembalikan null
            return null;
        }
        $routes = $data['routes'] ?? null;
        $stored = $data['fingerprint'] ?? null;
        if (($data['version'] ?? null) !== ZefVersion::VERSION || !is_array($routes) || !is_string($stored)) {
            return null;
        }

        return ['routes' => $routes, 'fingerprint' => $stored];
    }

    /**
     * Includes the cache artifact and returns its payload as an array.
     *
     * Deliberately a plain include (not include_once): the artifact is a
     * side-effect-free `<?php return array(...);` data file, and repeated
     * in-process reads must keep seeing the payload — include_once would
     * hand back true on the second read, a silent permanent soft-miss for
     * long-running RoadRunner workers.
     *
     * @return null|array<mixed>
     */
    private static function includeEnvelope(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        try {
            $data = include $path;
        } catch (\Throwable) {
            return null; // corrupt payload — soft miss
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Content fingerprint of an exported route table: SHA-256 over the
     * serialized table. Deterministic for identical tables (exportRoutes()
     * sorts before exporting), so two structurally identical route tables
     * hash identically and any route change shifts the hash.
     */
    private static function fingerprint(mixed $routes): string
    {
        return hash('sha256', serialize($routes));
    }
}
