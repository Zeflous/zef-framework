<?php

declare(strict_types=1);

/*
 * ZEF Framework — zero-composer classmap coverage guard (audit v24).
 *
 * autoload/zef_autoload.php is the hand-maintained static classmap that makes
 * the framework runnable WITHOUT Composer. A class added under src/ but never
 * added to that map resolves under Composer (PSR-4) and fatals on the
 * zero-composer path — the exact defect that made `php bin/zef route:list`
 * crash with "Class Zef\\Framework\\Console\\Inspector\\RouteLister not found"
 * in v2.36.0 while every CI job stayed green, because all of them run
 * `composer install` first.
 *
 * This test reads the map as text (not through the autoloader, so a broken
 * autoloader cannot mask the gap) and fails closed the moment one src/ class is
 * missing, so the drift can never land silently again.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ClassmapCoverageGuardTest extends TestCase
{
    public function testEverySourceClassIsDeclaredInTheStaticClassmap(): void
    {
        $root = dirname(__DIR__, 2);
        $autoload = (string) file_get_contents($root . '/autoload/zef_autoload.php');
        self::assertNotSame('', $autoload, 'autoload/zef_autoload.php must be readable.');

        $missing = [];
        foreach ($this->sourceClasses($root . '/src') as $class => $file) {
            if (!str_contains($autoload, '"' . str_replace('\\', '\\\\', $class) . '" => __DIR__')) {
                $missing[$class] = $file;
            }
        }

        self::assertSame([], $missing, sprintf(
            '%d class(es) under src/ are missing from autoload/zef_autoload.php: %s. Run: php scripts/dev/update_classmap.php --apply',
            count($missing),
            implode(', ', array_keys($missing)),
        ));
    }

    public function testTheStaticClassmapDeclaresAtLeastOneEntry(): void
    {
        $autoload = (string) file_get_contents(dirname(__DIR__, 2) . '/autoload/zef_autoload.php');
        $count = preg_match_all('/"[^"]+"\s*=>\s*__DIR__/', $autoload);

        self::assertIsInt($count);
        self::assertGreaterThan(0, $count);
    }

    /**
     * @return array<string, string> FQCN => repository-relative file path
     */
    private function sourceClasses(string $srcDir): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $source = (string) file_get_contents($path);
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            $namespace = trim($ns[1]);
            $relative = ltrim(str_replace($root . '/', '', str_replace('\\', '/', $path)), '/');
            foreach (['/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', '/^\s*interface\s+(\w+)/m', '/^\s*enum\s+(\w+)/m'] as $pattern) {
                $found = [];
                if (preg_match_all($pattern, $source, $found) > 0) {
                    foreach ($found[1] as $name) {
                        $classes[$namespace . '\\' . $name] = $relative;
                    }
                }
            }
        }

        return $classes;
    }
}
