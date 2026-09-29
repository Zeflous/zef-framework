<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold an application service inside an existing module,
 * with the container wiring snippet ready to paste.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class ServiceGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'service name');
        // Convention: services end in "Service". Never double-suffix a name
        // the caller already spelled correctly (InventoryService stays put).
        $class = str_ends_with($name, 'Service') ? $name : $name . 'Service';
        $module = 'core';
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--module=')) {
                $module = NamingRules::moduleName(substr((string) $arg, 9));
            }
        }

        $moduleDir = HandlerGenerator::resolveModuleDir($this->root, $module);
        if ($moduleDir === null) {
            $this->io->err("Module '{$module}' does not exist (modules/{$module}). Create it first with make:module.");

            return 1;
        }

        $modulePascal = basename($moduleDir);
        $ns = "Zef\\Module\\{$modulePascal}";
        $snake = NamingRules::snake($name);
        $this->writer->writeFile("{$moduleDir}/{$class}.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — application service (scaffolded by bin/zef make:service).
             */

            namespace {$ns};

            final class {$class}
            {
                public function __construct()
                {
                    // The scaffold intentionally stops here: the constructor is
                    // empty until you declare the ports this service depends on.
                    // This TODO is a PLANNED extension point, not unpaid debt —
                    // it is surfaced by `bin/zef make:service` in generated
                    // projects and is not a marker of unfinished work here.
                    // TODO: promote constructor properties for the ports this service
                    // depends on, and mirror them in the container 'deps' list below.
                }
            }

            PHP);
        $this->io->out(<<<TXT

            Next steps — wire it into modules/{$modulePascal}/ConfigProvider.php:

              '{$module}.service.{$snake}' => [
                  'factory'  => static fn(): {$class} => new {$class}(),
                  'deps'     => [],
                  // 'lifetime' => ServiceLifetime::SINGLETON,
              ],

            TXT);

        return 0;
    }
}
