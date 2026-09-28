<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regresi I-7 (issue #175): the route cache carries a
 * staleness fingerprint.
 *
 * RouteCache had no staleness fingerprint and zero integration: a stale
 * cache loaded verbatim, so a route change that forgot a re-compile
 * served the OLD table with no signal. The compiled file is now an
 * envelope (framework version stamp + SHA-256 fingerprint of the route
 * table + the table itself, mirroring RadixTreeCache): load() is
 * fail-closed (legacy/cross-release/corrupt payloads throw) and
 * loadIfFresh() is the soft-miss read that also compares against the
 * CURRENT route table — a stale cache is never restored.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Router\RouteCache;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class RouteCacheStalenessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = (string) tempnam(sys_get_temp_dir(), 'zefrcs');
        unlink($dir); // nosemgrep: php.lang.security.unlink-use
        mkdir($dir, 0o777, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $leftovers = glob($this->dir . '/*');
        if ($leftovers !== false) {
            foreach ($leftovers as $leftover) {
                unlink($leftover); // nosemgrep: php.lang.security.unlink-use
            }
        }
        rmdir($this->dir);
    }

    /**
     * Regresi I-7 (issue #175): a cache written for exactly the current
     * route table is fresh — loadIfFresh() restores a frozen, matching
     * router (identical tables hash identically: the export is sorted and
     * deterministic).
     */
    public function testLoadIfFreshRestoresCacheForIdenticalRouteTable(): void
    {
        $path = $this->dir . '/routes.cache.php';
        RouteCache::write($this->sourceRouter(), $path);

        $restored = RouteCache::loadIfFresh($path, $this->sourceRouter());

        self::assertInstanceOf(Router::class, $restored);
        self::assertTrue($restored->isFrozen(), 'the restored router is pre-compiled and frozen');
        self::assertSame('i.show', $restored->match('GET', '/api/items/AB1234')['handler']);
        self::assertSame('/api/items/{code:sku}', $restored->patternFor('api.items.show'));
    }

    /**
     * Regresi I-7 (issue #175): the staleness trap itself — a cache whose
     * route table no longer matches the current router is a soft miss
     * (null), never a verbatim restore of the old table.
     */
    public function testLoadIfFreshMissesWhenTheRouteTableDrifted(): void
    {
        $path = $this->dir . '/routes.cache.php';
        RouteCache::write($this->sourceRouter(), $path);

        $changed = $this->sourceRouter();
        $changed->add('GET', '/extra', 'i.extra');

        self::assertNull(RouteCache::loadIfFresh($path, $changed), 'an added route must invalidate the cache');
        self::assertNull(RouteCache::loadIfFresh($path, new Router()), 'a different table must invalidate the cache');
    }

    /**
     * Regresi I-7 (issue #175): soft misses never throw — absent,
     * unreadable and syntactically corrupt files are all null.
     */
    public function testLoadIfFreshSoftMissesOnAbsentAndCorruptFiles(): void
    {
        self::assertNull(RouteCache::loadIfFresh($this->dir . '/nope.php', new Router()));

        $corrupt = $this->dir . '/corrupt.php';
        file_put_contents($corrupt, "<?php\n\n\$broken = ;\n");
        self::assertNull(RouteCache::loadIfFresh($corrupt, new Router()), 'a file that cannot be included is a soft miss');

        $nonEnvelope = $this->dir . '/non-envelope.php';
        file_put_contents($nonEnvelope, "<?php\n\nreturn 42;\n");
        self::assertNull(RouteCache::loadIfFresh($nonEnvelope, new Router()), 'a non-array payload is a soft miss');
    }

    /**
     * Regresi I-7 (issue #175): a legacy pre-fingerprint cache (the bare
     * var_export route array the old write() produced) never loads
     * verbatim — load() fails closed and loadIfFresh() soft-misses.
     */
    public function testLegacyBareRouteArrayNeverLoadsVerbatim(): void
    {
        $path = $this->dir . '/legacy.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export($this->sourceRouter()->exportRoutes(), true) . ";\n");

        try {
            RouteCache::load($path);
            self::fail('a legacy bare route array must be rejected by load()');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('no version stamp (legacy pre-v2.31.0 bare route array)', $e->getMessage());
            self::assertStringContainsString('Regenerate it with RouteCache::write()', $e->getMessage());
        }
        self::assertNull(RouteCache::loadIfFresh($path, $this->sourceRouter()), 'the legacy table matching the current one is STILL a miss — no stamp, no trust');
    }

    /**
     * Regresi I-7 (issue #175): the version stamp guards cross-release
     * staleness — a cache compiled by another framework version is
     * rejected even when its route table is unchanged.
     */
    public function testCrossReleaseCacheIsRejected(): void
    {
        $path = $this->dir . '/foreign.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => '0.0.0',
            'fingerprint' => 'irrelevant-but-well-formed',
            'routes' => $this->sourceRouter()->exportRoutes(),
        ], true) . ";\n");

        try {
            RouteCache::load($path);
            self::fail('a cache from another framework version must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("framework version '0.0.0'", $e->getMessage());
            self::assertStringContainsString('running ' . ZefVersion::VERSION, $e->getMessage());
        }
        self::assertNull(RouteCache::loadIfFresh($path, $this->sourceRouter()));
    }

    /**
     * Regresi I-7 (issue #175): the fingerprint guards the payload itself
     * — a hand-edited or corrupted envelope (fingerprint no longer matches
     * the route table it carries) fails closed.
     */
    public function testTamperedFingerprintFailsClosed(): void
    {
        $path = $this->dir . '/tampered.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'version' => ZefVersion::VERSION,
            'fingerprint' => 'deadbeef',
            'routes' => $this->sourceRouter()->exportRoutes(),
        ], true) . ";\n");

        try {
            RouteCache::load($path);
            self::fail('a fingerprint mismatch must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('failed its staleness fingerprint check', $e->getMessage());
        }
        self::assertNull(RouteCache::loadIfFresh($path, $this->sourceRouter()));
    }

    /**
     * Regresi I-7 (issue #175): the happy path keeps working — write()
     * then load() round-trips routes, names and custom constraints
     * through the new envelope.
     */
    public function testWriteLoadRoundTripThroughTheEnvelope(): void
    {
        $path = $this->dir . '/routes.cache.php';
        RouteCache::write($this->sourceRouter(), $path);

        $restored = RouteCache::load($path);

        self::assertSame('i.show', $restored->match('GET', '/api/items/AB1234')['handler']);
        self::assertSame('AB1234', $restored->match('GET', '/api/items/AB1234')['params']['code']);
        self::assertSame('/api/items/{code:sku}', $restored->patternFor('api.items.show'));
        self::assertSame('i.free', $restored->match('GET', '/api/free')['handler']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Deterministic source table: identical add() sequences produce
     * identical exports (and therefore identical fingerprints).
     */
    private function sourceRouter(): Router
    {
        $router = new Router();
        $router->addConstraint('sku', '/^[A-Z]{2}\d{4}$/');
        $router->group(['prefix' => '/api', 'name' => 'api.'], static function (Router $router): void {
            $router->add('GET', '/items/{code:sku}', 'i.show', priority: 10, name: 'items.show');
            $router->add('GET', '/free', 'i.free', name: 'items.free');
        });

        return $router;
    }
}
