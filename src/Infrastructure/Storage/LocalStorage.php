<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: object-storage adapter over the local filesystem.
 *
 * Safety model:
 * - Every key goes through StorageKeys::assertValidKey() — traversal
 *   segments (`.`/`..`), absolute keys and control characters can never
 *   reach the path builder (defense layer 1).
 * - The root is canonicalised once (realpath). Every component beneath
 *   it is checked for symbolic links before object I/O, including missing
 *   targets of dangling links. Listings omit links and do not follow them.
 * - The root and its ancestors must remain trusted: these path-based PHP
 *   operations cannot prevent concurrent filesystem replacement between
 *   validation and I/O.
 * - Writes are atomic: contents land in a unique temp file inside the
 *   destination directory, then rename(2) flips it into place, so readers
 *   observe either the previous object or the complete new one.
 */

namespace Zef\Framework\Storage;

/**
 * Filesystem-backed {@see ObjectStorageInterface} (dev, single-node, local
 * disk volumes). Keys map 1:1 to files under the root directory; empty
 * directories are invisible to the port (the port models objects, not
 * folders) and are left behind by delete() on purpose.
 */
final readonly class LocalStorage implements ObjectStorageInterface
{
    private string $root;

    public function __construct(
        string $root,
        private int $maxObjectBytes = 67_108_864,
    ) {
        if ($maxObjectBytes < 1) {
            throw new \InvalidArgumentException('Max object size must be positive.');
        }
        if (!is_dir($root) && !@mkdir($root, 0o777, true) && !is_dir($root)) {
            throw new StorageException("Unable to create storage root '{$root}'.");
        }
        $canonical = realpath($root);
        if ($canonical === false) {
            throw new StorageException("Storage root '{$root}' cannot be resolved.");
        }
        $this->root = $canonical;
    }

    #[\Override]
    public function put(string $key, string $contents): void
    {
        StorageKeys::assertValidKey($key);
        if (strlen($contents) > $this->maxObjectBytes) {
            throw new StorageException('Object size exceeds the configured limit.');
        }
        $target = $this->pathFor($key);
        $this->ensureDirectory(dirname($target));
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
        $written = @file_put_contents($tmp, $contents);
        if ($written === false || $written < strlen($contents)) {
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use (temp file we just created)

            throw new StorageException("Failed to write object '{$key}'.");
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use (temp file we just created)

            throw new StorageException("Failed to persist object '{$key}'.");
        }
    }

    #[\Override]
    public function get(string $key): string
    {
        StorageKeys::assertValidKey($key);
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            throw ObjectNotFoundException::forKey($key);
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new StorageException("Failed to read object '{$key}'.");
        }

        return $contents;
    }

    #[\Override]
    public function delete(string $key): void
    {
        StorageKeys::assertValidKey($key);
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return; // idempotent by contract
        }
        if (!@unlink($path)) { // nosemgrep: php.lang.security.unlink-use (inside our own root)
            throw new StorageException("Failed to delete object '{$key}'.");
        }
    }

    #[\Override]
    public function exists(string $key): bool
    {
        StorageKeys::assertValidKey($key);

        return is_file($this->pathFor($key));
    }

    #[\Override]
    public function stat(string $key): ?ObjectStat
    {
        StorageKeys::assertValidKey($key);
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            throw new StorageException("Failed to stat object '{$key}'.");
        }

        return new ObjectStat($key, $size, $mtime * 1_000_000_000);
    }

    #[\Override]
    public function list(string $prefix = '', int $limit = 1000): array
    {
        StorageKeys::assertValidPrefix($prefix);
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Listing limit must be 1..1000.');
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || $item->isLink() || !$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($this->root) + 1));
            if (str_starts_with($relative, $prefix)) {
                $found[] = $relative;
            }
        }
        sort($found);

        return array_slice($found, 0, $limit);
    }

    private function pathFor(string $key): string
    {
        $path = $this->root;
        foreach (explode('/', $key) as $segment) {
            $path .= '/' . $segment;
            // Recheck the filesystem on each operation, including dangling
            // links that file_exists() and realpath() would treat as absent.
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new StorageException("Object key '{$key}' contains a symbolic link.");
            }
        }

        return $path;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new StorageException("Unable to create object directory '{$directory}'.");
        }
    }
}
