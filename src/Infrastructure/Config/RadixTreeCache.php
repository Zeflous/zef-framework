<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.23.0 (issue #60 P2: OPcache-friendly serialization
 * of the built radix index — production boots skip the index reconstruction
 * when the value tree is unchanged).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * Disk cache for the {@see ConfigRadixTree} pattern-query index.
 *
 * Why: the index is built once per boot from the merged values. Building it
 * is O(n) in configuration leaves — microseconds for typical apps, but a
 * compiled production boot (`CompiledConfigSource`) already pays zero for
 * parsing/validation/secrets, so the tree build is the remaining fixed cost.
 * {@see ConfigCompiler} removes the source pipeline; this class removes the
 * index rebuild the same way. Storing the tree with stdlib `serialize()` is
 * OPcache-neutral by design (issue #60: "the win is skipping the build, not
 * the parse") — `unserialize()` materialises plain nested arrays/objects,
 * no PHP compilation involved, and the payload stays valid across releases
 * for as long as the tree's class shape is stable.
 *
 * Entry format (a single serialized envelope, no PHP code): a map with the
 * framework `version` stamp, a SHA-256 `fingerprint` of the serialized value
 * tree, and the `tree` itself.
 *
 * Invalidation is two-fold and checked on every read: the framework version
 * guards against class-shape/format changes between releases, and the value
 * fingerprint guarantees the cached tree answers queries exactly as a
 * freshly built one would. A miss on either — or a corrupt/missing/unreadable
 * file — is a soft miss: the caller rebuilds.
 *
 * SECURITY: the cached tree contains the RESOLVED configuration, including
 * secret values baked in as plaintext — the same sensitivity as a compiled
 * `config.php`. Writes therefore follow the {@see ConfigCompiler} contract:
 * atomic temp-file + rename, restrictive permissions applied to the temp
 * file BEFORE the rename (default 0600, no world-readable window), and the
 * recommended target directory (`var/cache/`) is already covered by the
 * shipped `.gitignore`. Never point this at a path inside version control.
 */
final readonly class RadixTreeCache
{
    public function __construct(private int $fileMode = 0o600)
    {
        if ($fileMode < 0 || ($fileMode & ~0o777) !== 0) {
            throw new \InvalidArgumentException(
                'Radix tree cache file mode must be a permission mask between 0 and 0777, got '
                . $fileMode . '.'
            );
        }
    }

    /**
     * One-shot boot helper: reuse the cached index when valid, otherwise
     * build it fresh — and wrap the values plus index in a {@see Config}.
     * Never writes; pair with {@see store()} in the compile pipeline when
     * the miss should be persisted for the next boot.
     *
     * @param array<array-key,mixed> $values
     */
    public function hydrate(array $values, string $cacheFile): Config
    {
        $tree = $this->read($cacheFile, $values);

        return new Config($values, $tree ?? new ConfigRadixTree($values));
    }

    /**
     * Build the index for `$values`, persist it to `$cacheFile` and return
     * the resulting {@see Config}. The compile pipeline calls this right
     * after {@see ConfigCompiler::export()} so the next production boot hits
     * the cache. A valid fresh entry for the same values is left untouched.
     *
     * @param array<array-key,mixed> $values
     */
    public function store(array $values, string $cacheFile): Config
    {
        $cached = $this->read($cacheFile, $values);
        if ($cached instanceof ConfigRadixTree) {
            return new Config($values, $cached);
        }
        $tree = new ConfigRadixTree($values);
        $this->write($tree, $values, $cacheFile);

        return new Config($values, $tree);
    }

    /**
     * Cached index for exactly these `$values`, or null on miss (file absent,
     * unreadable, corrupt, stale version or fingerprint mismatch). Corruption
     * never throws: a cache that cannot be trusted is a cache that is not
     * used.
     *
     * @param array<array-key,mixed> $values
     */
    public function read(string $cacheFile, array $values): ?ConfigRadixTree
    {
        $entry = $this->decodeCacheEntry($cacheFile);
        if ($entry === null) {
            return null;
        }
        $tree = $entry['tree'] ?? null;
        if (!$tree instanceof ConfigRadixTree || !$this->entryMatches($entry, $values)) {
            return null;
        }

        return $tree;
    }

    /**
     * Decode the serialized cache envelope, or null on a soft miss: file
     * absent, unreadable, or a corrupt payload.
     *
     * @return null|array<string, mixed>
     */
    private function decodeCacheEntry(string $cacheFile): ?array
    {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        // file_get_contents() is guarded by the is_file()/is_readable()
        // checks above; a false return can only stem from a microsecond
        // TOCTOU race with a concurrent writer's atomic rename — a soft
        // miss either way.
        $blob = file_get_contents($cacheFile);
        $entry = $blob === false ? null : $this->unserializeEntry($blob);

        return is_array($entry) ? $entry : null;
    }

    /**
     * Unserialize the cache payload, or null when it is corrupt or not an
     * envelope array. Corruption never throws out of the read path: a cache
     * that cannot be trusted is a cache that is not used.
     *
     * @return null|array<string, mixed>
     */
    private function unserializeEntry(string $blob): ?array
    {
        // Scoped error handler: a corrupt payload warns (and/or throws) —
        // both are a soft miss below, never a boot failure. The handler
        // answers every diagnostic itself, so nothing leaks to the engine's
        // default reporting.
        set_error_handler(static fn (int $errno, string $message): bool => true);

        try {
            // Provenance and blast radius, for the register
            // (docs/security/php-sast.md §7, row 48): the blob is a cache
            // file this class itself wrote via the atomic rename + 0600
            // contract, and the accepted-classes list is closed over two
            // final readonly data classes with no magic methods — no
            // object-injection gadget chain can start, and a tampered
            // payload still has to survive the version stamp, the SHA-256
            // fingerprint and the instanceof check in read().
            $entry = unserialize($blob, ['allowed_classes' => [ // nosemgrep: php.lang.security.unserialize-use
                ConfigRadixTree::class,
                ConfigRadixNode::class,
            ]]);
        } catch (\Throwable) {
            return null; // corrupt payload — soft miss
        } finally {
            restore_error_handler();
        }

        return is_array($entry) ? $entry : null;
    }

    /**
     * Version-and-fingerprint half of the cache validity contract.
     *
     * @param array<string, mixed> $entry
     * @param array<array-key, mixed> $values
     */
    private function entryMatches(array $entry, array $values): bool
    {
        return ($entry['version'] ?? null) === ZefVersion::VERSION
            && ($entry['fingerprint'] ?? null) === $this->fingerprint($values);
    }

    /**
     * Atomic publish: temp file in the target directory (permissions applied
     * BEFORE the rename), then rename over the final name.
     *
     * @param array<array-key,mixed> $values
     */
    private function write(ConfigRadixTree $tree, array $values, string $cacheFile): void
    {
        $directory = dirname($cacheFile);
        $basename = basename($cacheFile);
        if (in_array($basename, ['', '.', '..'], true)) {
            throw new InvalidConfigurationException("Invalid radix cache target '{$cacheFile}'.");
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InvalidConfigurationException(
                "Radix cache target directory is not writable: '{$directory}'."
            );
        }
        $entry = serialize([
            'version' => ZefVersion::VERSION,
            'fingerprint' => $this->fingerprint($values),
            'tree' => $tree,
        ]);
        $tmp = $directory . '/.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp';
        // file_put_contents() is guarded by the is_dir()/is_writable()
        // checks above; a false return can only stem from a microsecond
        // TOCTOU race with a concurrent writer.
        if (file_put_contents($tmp, $entry) === false) {
            throw new InvalidConfigurationException("Failed to write radix cache temp file '{$tmp}'.");
        }
        // POSIX-only mode contract — mirrors ConfigCompiler::export(): PHP's
        // Windows DACL emulation breaks the atomic rename publish for masks
        // without owner-write (issue #110).
        if (DIRECTORY_SEPARATOR === '/') {
            // Best-effort restrictive mode before the rename; a chmod
            // failure leaves the default mode in place, which the rename
            // below then publishes — the historically tolerated behaviour.
            chmod($tmp, $this->fileMode);
        }
        if (!rename($tmp, $cacheFile)) {
            // Cleanup of $tmp, a name this method generated itself
            // ('.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp'). No
            // request input reaches the argument; this runs only when the
            // rename immediately above failed. Registered as an accepted
            // suppression: docs/security/php-sast.md §7, row 49.
            if (is_file($tmp)) {
                unlink($tmp); // nosemgrep: php.lang.security.unlink-use
            }

            throw new InvalidConfigurationException("Failed to publish radix cache '{$cacheFile}'.");
        }
    }

    /**
     * Content fingerprint of the value tree: SHA-256 over the serialized
     * values. Order-sensitive by construction — merged values come from the
     * deterministic loader pipeline, so identical configurations hash
     * identically.
     *
     * @param array<array-key,mixed> $values
     */
    private function fingerprint(array $values): string
    {
        return hash('sha256', serialize($values));
    }
}
