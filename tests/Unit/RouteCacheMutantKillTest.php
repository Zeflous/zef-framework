<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299 (consolidated escaped
 * mutants). Hotspot: src/Adapters/Router/RouteCache.php (18 escaped).
 *
 * These tests pin the SHAPE of the written artifact, the temp-file naming,
 * the directory mode and the error paths that the behavioural staleness
 * tests never asserted.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\RouteCacheException;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Router\RouteCache;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class RouteCacheMutantKillTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = (string) tempnam(sys_get_temp_dir(), 'zefrcmk');
        unlink($dir); // nosemgrep: php.lang.security.unlink-use
        mkdir($dir, 0o777, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use
        }
        rmdir($this->dir);
    }

    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');
        $router->add('GET', '/b', 'h.b', name: 'b');

        return $router;
    }

    /** RouteCache::export() must be public static (kills PublicVisibility:37). */
    public function testExportIsPublicStatic(): void
    {
        $method = new \ReflectionMethod(RouteCache::class, 'export');
        self::assertTrue($method->isPublic(), 'export() must stay public');
        self::assertTrue($method->isStatic(), 'export() must stay static');
        self::assertSame($this->router()->exportRoutes(), RouteCache::export($this->router()));
    }

    /** write() creates the parent directory with mode 0777 (kills Increment/DecrementInteger:56). */
    public function testWriteCreatesDirectoryWith0777Mode(): void
    {
        $old = umask(0);

        try {
            RouteCache::write($this->router(), $this->dir . '/nested/deep/routes.php');
        } finally {
            umask($old);
        }

        self::assertSame(0o777, fileperms($this->dir . '/nested') & 0o777, 'mkdir mode must be 0777');
        self::assertSame(0o777, fileperms($this->dir . '/nested/deep') & 0o777, 'mkdir mode must be 0777');
    }

    /** The written artifact is the documented envelope (kills Concat/ConcatOperandRemoval:59/112). */
    public function testWrittenArtifactShape(): void
    {
        $path = $this->dir . '/routes.php';
        RouteCache::write($this->router(), $path);

        $raw = (string) file_get_contents($path);
        self::assertStringContainsString('// Compiled ZEF route cache', $raw);
        self::assertStringContainsString('// Regenerate with RouteCache::write() after every route change.', $raw);
        self::assertStringContainsString('return array (', $raw);
        self::assertStringContainsString("'version' =>", $raw);
        self::assertStringContainsString("'fingerprint' =>", $raw);
        self::assertStringContainsString("'routes' =>", $raw);
        self::assertSame([], glob($this->dir . '/*.tmp') ?: [], 'no temp residue after a successful write');
    }

    /** The temp file lives next to the target and is cleaned up (kills Concat*:59). */
    public function testTempFileIsSiblingAndRemovedOnSuccess(): void
    {
        $path = $this->dir . '/routes.php';
        RouteCache::write($this->router(), $path);

        $entries = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        self::assertSame(['routes.php'], $entries, 'only the final artifact remains in the directory');
    }

    /** load() rejects an envelope with no route table (kills ReturnRemoval/LogicalOr:155/159/160). */
    public function testLoadRejectsMissingRouteTable(): void
    {
        $path = $this->dir . '/noroutes.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'x',
        ], true) . ";\n");

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('has no route table');
        RouteCache::load($path);
    }

    /** load() rejects a non-array payload (kills ReturnRemoval:155). */
    public function testLoadRejectsNonArrayPayload(): void
    {
        $path = $this->dir . '/scalar.php';
        file_put_contents($path, "<?php\n\nreturn 42;\n");

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('did not return an array');
        RouteCache::load($path);
    }

    /** load() rejects a missing file (kills the is_file guard). */
    public function testLoadRejectsMissingFile(): void
    {
        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('does not exist');
        RouteCache::load($this->dir . '/absent.php');
    }

    /** loadIfFresh() soft-misses a directory path (kills LogicalOr:179). */
    public function testLoadIfFreshSoftMissesDirectoryPath(): void
    {
        self::assertNull(RouteCache::loadIfFresh($this->dir, $this->router()));
    }

    /** loadIfFresh() soft-misses a non-array payload (kills the is_array guard). */
    public function testLoadIfFreshSoftMissesNonArrayPayload(): void
    {
        $path = $this->dir . '/scalar.php';
        file_put_contents($path, "<?php\n\nreturn 42;\n");
        self::assertNull(RouteCache::loadIfFresh($path, $this->router()));
    }

    /** loadIfFresh() soft-misses an envelope whose stored fingerprint is not a string. */
    public function testLoadIfFreshSoftMissesNonStringFingerprint(): void
    {
        $path = $this->dir . '/badfp.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 123,
            'routes' => $this->router()->exportRoutes(),
        ], true) . ";\n");
        self::assertNull(RouteCache::loadIfFresh($path, $this->router()));
    }
}
