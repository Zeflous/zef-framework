<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests (round 2) for issue #299.
 * Hotspot: src/Adapters/Router/RouteCache.php — pins the temp-file name
 * shape, the full error messages and the readFreshEnvelope guards.
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
final class RouteCacheMutantKill2Test extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = (string) tempnam(sys_get_temp_dir(), 'zefrc2');
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
            if ($item->isDir()) {
                chmod($item->getPathname(), 0o777);
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use
            }
        }
        rmdir($this->dir);
    }

    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');

        return $router;
    }

    /** The temp file is "<path>.<12 hex>.tmp" (kills Concat/ConcatOperandRemoval/Increment/DecrementInteger:59). */
    public function testTempFileNamePatternOnWriteFailure(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('permission-based test requires a non-root user');
        }
        $ro = $this->dir . '/ro';
        mkdir($ro, 0o777, true);
        chmod($ro, 0o555);

        try {
            RouteCache::write($this->router(), $ro . '/routes.php');
            self::fail('writing into a read-only directory must fail');
        } catch (RouteCacheException $e) {
            self::assertMatchesRegularExpression(
                "#^Cannot write route cache '.*/routes\.php\.[0-9a-f]{12}\.tmp'\.$#",
                $e->getMessage(),
            );
        } finally {
            chmod($ro, 0o777);
        }
    }

    /** The "no route table" message carries the regeneration suffix (kills Concat/ConcatOperandRemoval:107). */
    public function testLoadNoRouteTableFullMessage(): void
    {
        $path = $this->dir . '/noroutes.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'x',
        ], true) . ";\n");

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage("Route cache file '{$path}' has no route table. Regenerate it with RouteCache::write().");
        RouteCache::load($path);
    }

    /** The fingerprint-mismatch message carries the regeneration suffix (kills Concat/ConcatOperandRemoval:112). */
    public function testLoadFingerprintMismatchFullMessage(): void
    {
        $path = $this->dir . '/badfp.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'not-the-real-fingerprint',
            'routes' => $this->router()->exportRoutes(),
        ], true) . ";\n");

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage("Route cache file '{$path}' failed its staleness fingerprint check (corrupt or hand-edited). Regenerate it with RouteCache::write().");
        RouteCache::load($path);
    }

    /** A wrong version stamp soft-misses even when the fingerprint matches (kills ReturnRemoval:160, LogicalOr:159). */
    public function testLoadIfFreshRejectsWrongVersion(): void
    {
        $path = $this->dir . '/routes.php';
        RouteCache::write($this->router(), $path);

        // Tamper only the version stamp, keeping the (valid) fingerprint.
        $data = include $path;
        $data['version'] = '0.0.0-not-this-release';
        file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

        self::assertNull(RouteCache::loadIfFresh($path, $this->router()), 'a cross-release stamp must soft-miss');
    }

    /** A non-array route table soft-misses (kills LogicalOr:159). */
    public function testLoadIfFreshRejectsNonArrayRoutes(): void
    {
        $path = $this->dir . '/routes.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'x',
            'routes' => 'not-an-array',
        ], true) . ";\n");

        self::assertNull(RouteCache::loadIfFresh($path, $this->router()));
    }
}
