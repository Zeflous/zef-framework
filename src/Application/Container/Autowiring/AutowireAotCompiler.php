<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Application layer (autowiring AOT compilation).
 *
 * Turns AutowireMetadata into pure PHP factory code:
 *
 *     static fn ($ctx, $d0, $d1) => new \Acme\Service($d0, $d1, 'lit')
 *
 * Generated code contains no Reflection* calls whatsoever. Factories are
 * created once during the compile pass (eval of self-generated code) and are
 * embedded verbatim in exported AOT files, so a cold-start process can build
 * the whole container with `loadDefinitions()` + `bootContainer()` without
 * running the reflection-based extractor at all.
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;

final class AutowireAotCompiler
{
    public static function generateFactory(AutowireMetadata $metadata): string
    {
        $args = [];
        foreach ($metadata->argumentPlan as [$kind, $payload]) {
            $args[] = $kind === 'dep'
                ? '$d' . $payload
                : $payload; // pre-rendered PHP literal / constant name
        }
        $deps = $metadata->dependencies;
        $params = [];
        foreach (array_keys($deps) as $index) {
            $params[] = '$d' . $index;
        }
        $paramsList = $params === [] ? '$ctx' : '$ctx, ' . implode(', ', $params);
        $class = '\\' . ltrim($metadata->className, '\\');
        $argsList = implode(', ', $args);

        return "static fn ({$paramsList}) => new {$class}({$argsList})";
    }

    /**
     * One array entry of the exported AOT file for a single service.
     */
    public static function generateServiceEntry(AutowireMetadata $metadata, string $factoryCode): string
    {
        $id = var_export($metadata->serviceId, true);
        $deps = [];
        foreach ($metadata->dependencies as $dep) {
            $deps[] = var_export($dep, true);
        }
        $depsList = $deps === [] ? '[]' : '[' . implode(', ', $deps) . ']';
        $lifetime = var_export($metadata->lifetime, true);
        $shared = $metadata->lifetime === ServiceLifetime::SINGLETON ? 'true' : 'false';
        $module = $metadata->module === null
            ? 'null'
            : var_export($metadata->module, true);

        return <<<PHP
            {$id} => [
                        'factory' => {$factoryCode},
                        'dependencies' => {$depsList},
                        'lifetime' => {$lifetime},
                        'shared' => {$shared},
                        'module' => {$module},
                    ],

            PHP;
    }

    /**
     * Export a whole compile-pass result as a loadable PHP file.
     *
     * @param string         $path    destination file (parent dir must exist)
     * @param string         $header  comment banner embedded in the file
     *
     * @throws InvalidConfigurationException when any value is not exportable
     */
    public static function export(
        AutowireResult $result,
        string $path,
        string $header = 'ZEF AOT autowire compilation',
    ): void {
        $entries = '';
        foreach ($result->metadata as $serviceId => $metadata) {
            $entries .= '        ' . self::generateServiceEntry($metadata, $result->factoryCode[$serviceId]);
        }
        $code = "<?php\n\n/* {$header} — generated file, do not edit. */\n\nreturn [\n{$entries}];\n";
        // @infection-ignore-all Concat,ConcatOperandRemoval — ekuivalen: nama tmp hanya
        // terlihat sebelum rename atomik; komposisi tak terobservasi
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $code, \LOCK_EX) === false) {
            throw new InvalidConfigurationException("Cannot write AOT export file '{$path}'.");
        }
        if (!rename($tmp, $path)) {
            // Cleanup of $tmp, a name this method generated itself
            // ($path . '.tmp.' . getmypid()). No request input reaches the
            // argument; this runs only when the rename immediately above failed.
            // Registered as an accepted suppression: docs/security/php-sast.md §7.
            if (is_file($tmp)) {
                unlink($tmp); // nosemgrep: php.lang.security.unlink-use
            }

            throw new InvalidConfigurationException("Cannot finalise AOT export file '{$path}'.");
        }
    }

    /**
     * Load exported definitions (pure include — no reflection, no extractor).
     *
     * @return array<string,ServiceDefinition>
     */
    public static function loadDefinitions(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidConfigurationException("AOT file '{$path}' does not exist.");
        }
        // Deliberately a plain require (not require_once): the AOT export is a
        // side-effect-free `<?php return [...];` data artifact, and repeated
        // in-process loads (e.g. booting a second container from the same
        // export) must keep returning the definitions array — require_once
        // would hand back true on the second load and break the array
        // contract below (same rationale as RouteCache's include envelope).
        $services = require $path;
        if (!is_array($services)) {
            throw new InvalidConfigurationException("AOT file '{$path}' must return an array.");
        }
        $definitions = [];
        foreach ($services as $id => $entry) {
            if (!is_string($id) || !is_array($entry)) {
                throw self::malformedEntry($path, $id);
            }
            $shapeComplete = isset($entry['factory'], $entry['dependencies'], $entry['lifetime']);
            if (!$shapeComplete) {
                throw self::malformedEntry($path, $id);
            }
            if (!is_callable($entry['factory']) || !is_array($entry['dependencies']) || !is_string($entry['lifetime'])) {
                throw self::malformedEntry($path, $id);
            }
            $definitions[$id] = new ServiceDefinition(
                id: $id,
                factory: $entry['factory'],
                dependencies: array_values($entry['dependencies']),
                module: isset($entry['module']) && is_string($entry['module']) ? $entry['module'] : null,
                lifetime: $entry['lifetime'],
                shared: (bool) ($entry['shared'] ?? ($entry['lifetime'] === ServiceLifetime::SINGLETON)),
            );
        }

        return $definitions;
    }

    /**
     * Register every exported definition into a fresh container.
     */
    public static function bootContainer(Container $container, string $path): void
    {
        foreach (self::loadDefinitions($path) as $definition) {
            $container->registerDefinition($definition);
        }
    }

    /**
     * Evaluate one generated factory expression into a \Closure.
     * Compile-time only; the resulting closure carries no reflection.
     *
     * The eval below is triaged: the input is code this compiler generated
     * itself (trusted), the same pattern Symfony DI uses for container dumps.
     */
    public static function evalFactory(string $factoryCode): \Closure
    {
        $closure = eval('return ' . $factoryCode . ';'); // nosemgrep: php.lang.security.eval-use
        if (!$closure instanceof \Closure) {
            throw new InvalidConfigurationException('Generated factory did not produce a closure.');
        }

        return $closure;
    }

    private static function malformedEntry(string $path, int|string $id): InvalidConfigurationException
    {
        return new InvalidConfigurationException("AOT file '{$path}' has a malformed entry for service '{$id}'.");
    }
}
