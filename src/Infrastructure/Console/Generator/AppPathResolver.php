<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * Lexical path-safety arithmetic extracted from AppGenerator (php:S2042):
 * host-aware normalization, absolute-path detection and the composer
 * path-repository reference computation. All operations are LEXICAL (the
 * filesystem is never touched — the scaffold target may not exist yet).
 */

namespace Zef\Framework\Console\Generator;

final readonly class AppPathResolver
{
    public function __construct(private string $root) {}

    /**
     * Lexically normalize a path: collapse `.`, `..` and duplicate slashes
     * WITHOUT touching the filesystem (the target may not exist yet). An
     * absolute path never escapes above its root — `/` on POSIX, the drive
     * or UNC prefix on Windows; a relative path keeps leading `..` segments.
     * On Windows both separator styles are accepted (PHP itself mixes them:
     * sys_get_temp_dir() returns backslashes); on POSIX a backslash stays a
     * legal filename character, so the swap is platform-gated.
     */
    public function normalize(string $path): string
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        if ($windows) {
            $path = str_replace('\\', '/', $path);
        }

        [$prefix, $path] = $this->extractRootPrefix($path, $windows);

        $absolute = $prefix !== '' || str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $this->popParentSegment($parts, $absolute);

                continue;
            }
            $parts[] = $segment;
        }

        $normalized = implode('/', $parts);
        if ($absolute) {
            return $prefix . '/' . $normalized;
        }

        return $normalized === '' ? '.' : $normalized;
    }

    /**
     * Absolute in the HOST's terms: `/`-rooted on POSIX; on Windows a drive
     * letter or a UNC `//` root (a `C:/...` argument is relative on POSIX,
     * where `C:` is a legal directory name).
     */
    public function isAbsolute(string $normalizedPath): bool
    {
        if (str_starts_with($normalizedPath, '/')) {
            return true;
        }

        return DIRECTORY_SEPARATOR === '\\'
            && (preg_match('#^[[:alpha:]]:/#', $normalizedPath) === 1
                || str_starts_with($normalizedPath, '//'));
    }

    /**
     * composer path-repository URL pointing from the scaffolded app back to
     * the framework checkout. Computed from the LONGEST COMMON ANCESTOR so
     * every nesting depth resolves: target /apps/demo with the framework at
     * /zef-framework yields '../zef-framework', while target /tmp/demo with
     * the framework at /home/me/zef-framework yields the full
     * '../../home/me/zef-framework' climb instead of a broken basename.
     */
    public function relativeFrameworkRef(string $target): string
    {
        // LEXICAL root, deliberately: $target itself is lexical, and the
        // climb must stay in ONE namespace — on Windows realpath() expands
        // 8.3 short names (RUNNER~1 -> runneradmin), which would break the
        // common-ancestor arithmetic mid-path (issue #110).
        $rootForm = $this->normalize($this->root);

        $fromParts = $this->pathSegments($this->normalize($target));
        $toParts = $this->pathSegments($rootForm);

        $common = 0;
        $max = min(count($fromParts), count($toParts));
        while ($common < $max && $fromParts[$common] === $toParts[$common]) {
            ++$common;
        }

        $parts = [
            ...array_fill(0, count($fromParts) - $common, '..'),
            ...array_slice($toParts, $common),
        ];

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /**
     * Root prefixes that bound the `..` walk on Windows: a drive root (C:/)
     * or a UNC root (//server/share). POSIX paths never strip anything.
     *
     * @return array{string,string} [prefix, rest-of-path]
     */
    private function extractRootPrefix(string $path, bool $windows): array
    {
        $prefix = '';
        if ($windows && preg_match('#^([[:alpha:]]:)(/|$)#', $path) === 1) {
            // Drive root (C:/): the `..` walk must never pop past it.
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif ($windows && str_starts_with($path, '//') && strlen($path) > 2) {
            // UNC root (//server/share): the server+share pair is the root.
            $segments = explode('/', substr($path, 2), 3);
            if ($segments[0] !== '') {
                $prefix = '//' . $segments[0]
                    . (isset($segments[1]) && $segments[1] !== '' ? '/' . $segments[1] : '');
                $path = substr($path, strlen($prefix));
            }
        }
        // Neither drive nor UNC root: nothing to strip (POSIX or relative).

        return [$prefix, $path];
    }

    /**
     * One `..` segment: pops the previous segment when there is one to pop;
     * a relative path keeps the `..`; an absolute path drops it (the `..`
     * would otherwise pop past the root prefix stripped above).
     *
     * @param list<string> $parts
     */
    private function popParentSegment(array &$parts, bool $absolute): void
    {
        if ($parts !== [] && end($parts) !== '..') {
            array_pop($parts);
        } elseif (!$absolute) {
            $parts[] = '..';
        }
        // Absolute: `..` at the root is dropped — it can never pop past
        // the drive/UNC/`/` root that bounds the path.
    }

    /** Split an absolute normalized path into segments (root `/` → []). */
    /** @return list<string> */
    private function pathSegments(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        // @var list<string>
        return explode('/', ltrim($path, '/'));
    }
}
