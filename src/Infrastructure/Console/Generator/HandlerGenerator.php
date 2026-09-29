<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a PSR-15 handler inside an existing module.
 * Behavioural port of the v2.8.0 `bin/zef make:handler` implementation.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class HandlerGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    /**
     * Case-insensitively resolve modules/<name> to its on-disk directory.
     */
    public static function resolveModuleDir(string $root, string $module): ?string
    {
        $entries = [];
        if (is_dir("{$root}/modules")) {
            $scanned = scandir("{$root}/modules");
            if ($scanned !== false) {
                $entries = $scanned;
            }
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strtolower($entry) === strtolower($module) && is_dir("{$root}/modules/{$entry}")) {
                return "{$root}/modules/{$entry}";
            }
        }

        return null;
    }

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName);
        $module = 'core';
        $path = null;
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--module=')) {
                $module = NamingRules::moduleName(substr((string) $arg, 9));
            }
            if (str_starts_with((string) $arg, '--path=')) {
                $path = substr((string) $arg, 7);
            }
        }

        $moduleDir = self::resolveModuleDir($this->root, $module);
        if ($moduleDir === null) {
            $this->io->err("Module '{$module}' does not exist (modules/{$module}). Create it first with make:module.");

            return 1;
        }

        $modulePascal = basename($moduleDir);
        $ns = "Zef\\Module\\{$modulePascal}";
        $file = "{$moduleDir}/{$name}Handler.php";
        $serviceId = "{$module}.handler." . NamingRules::snake($name);
        $routePath = $path ?? '/' . $module . '/' . NamingRules::kebab($name);
        $this->writer->writeFile($file, <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — handler scaffolded by bin/zef make:handler.
             */

            namespace {$ns};

            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;
            use Zef\\Framework\\Http\\Response;

            final class {$name}Handler implements RequestHandlerInterface
            {
                #[\\Override]
                public function handle(ServerRequestInterface \$request): ResponseInterface
                {
                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode(['handler' => '{$name}', 'status' => 'ok'], JSON_THROW_ON_ERROR),
                    );
                }
            }

            PHP);
        $this->io->out(<<<TXT

            Next steps — wire it into modules/{$modulePascal}/ConfigProvider.php:

              'services' => [
                  ...
                  '{$serviceId}' => [
                      'factory' => static fn(): {$name}Handler => new {$name}Handler(),
                      'deps'    => [],
                  ],
              ],
              'routes' => [
                  ...
                  ['method' => 'GET', 'path' => '{$routePath}', 'handler' => '{$serviceId}', 'priority' => 100],
              ],

            TXT);

        return 0;
    }
}
