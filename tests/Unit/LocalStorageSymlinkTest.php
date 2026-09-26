<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Storage\LocalStorage;
use Zef\Framework\Storage\StorageException;

/**
 * @internal
 */
final class LocalStorageSymlinkTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zef-storage-links-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/root/nested', 0o777, true);
        mkdir($this->base . '/outside', 0o777, true);
        file_put_contents($this->base . '/outside/object.txt', 'outside');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use (test sandbox)
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->base);
    }

    /**
     * @param 'delete'|'exists'|'get'|'put'|'stat' $operation
     *
     * @dataProvider symlinkOperationProvider
     */
    public function testObjectOperationsRejectSymlinks(string $operation, string $kind): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $target = match ($kind) {
            'file' => $this->base . '/outside/object.txt',
            'dangling' => $this->base . '/outside/missing.txt',
            'internal' => $this->base . '/root/nested',
            default => $this->base . '/outside',
        };
        $this->createLink($target, $this->base . '/root/nested/link');
        $key = match ($kind) {
            'file', 'dangling' => 'nested/link',
            default => 'nested/link/object.txt',
        };

        try {
            match ($operation) {
                'put' => $storage->put($key, 'replacement'),
                'get' => $storage->get($key),
                'delete' => $storage->delete($key),
                'exists' => $storage->exists($key),
                'stat' => $storage->stat($key),
            };
            self::fail('Object paths containing symlinks must be rejected.');
        } catch (StorageException $error) {
            self::assertSame("Object key '{$key}' contains a symbolic link.", $error->getMessage());
        } finally {
            self::assertSame('outside', file_get_contents($this->base . '/outside/object.txt'));
            self::assertFileDoesNotExist($this->base . '/outside/missing.txt');
            self::assertTrue(is_link($this->base . '/root/nested/link'));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function symlinkOperationProvider(): iterable
    {
        foreach (['put', 'get', 'delete', 'exists', 'stat'] as $operation) {
            foreach (['directory', 'file', 'dangling', 'internal'] as $kind) {
                yield $operation . '-' . $kind => [$operation, $kind];
            }
        }
    }

    public function testPutDoesNotCreateDirectoriesThroughSymlink(): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $this->createLink($this->base . '/outside', $this->base . '/root/link');

        try {
            $storage->put('link/new/deep/object.txt', 'outside write');
            self::fail('A symlink must be rejected before creating parent directories.');
        } catch (StorageException $error) {
            self::assertSame("Object key 'link/new/deep/object.txt' contains a symbolic link.", $error->getMessage());
        } finally {
            self::assertDirectoryDoesNotExist($this->base . '/outside/new');
        }
    }

    public function testPreviouslyAccessedDirectoryCannotBeReplacedWithSymlink(): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $storage->put('nested/object.txt', 'inside');
        self::assertSame('inside', $storage->get('nested/object.txt'));
        unlink($this->base . '/root/nested/object.txt'); // nosemgrep: php.lang.security.unlink-use (test sandbox)
        rmdir($this->base . '/root/nested');
        $this->createLink($this->base . '/outside', $this->base . '/root/nested');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Object key 'nested/object.txt' contains a symbolic link.");
        $storage->get('nested/object.txt');
    }

    public function testListSkipsSymlinksAndKeepsOrdinaryObjects(): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $storage->put('nested/normal.txt', 'inside');
        $this->createLink($this->base . '/outside', $this->base . '/root/directory-link');
        $this->createLink($this->base . '/outside/object.txt', $this->base . '/root/file-link');
        $this->createLink($this->base . '/outside/missing.txt', $this->base . '/root/dangling-link');
        $this->createLink($this->base . '/root/nested/normal.txt', $this->base . '/root/internal-link');

        self::assertSame(['nested/normal.txt'], $storage->list());
        self::assertSame([], $storage->list('file-link'));
        self::assertSame('inside', $storage->get('nested/normal.txt'));
    }

    private function createLink(string $target, string $link): void
    {
        if (!\function_exists('symlink') || !@symlink($target, $link)) {
            self::markTestSkipped('Symbolic links are unavailable on this filesystem.');
        }
    }
}
