<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a ConfigProvider class inside an existing module —
 * the framework's real configuration mechanism (config is aggregated from
 * providers, never from loose files).
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class ConfigGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'config name');
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
        $this->writer->writeFile("{$moduleDir}/{$name}ConfigProvider.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — config provider '{$module}.{$snake}' (scaffolded by bin/zef make:config).
             */

            namespace {$ns};

            use Zef\\Framework\\Config\\ConfigProviderInterface;

            final class {$name}ConfigProvider implements ConfigProviderInterface
            {
                #[\\Override]
                public function getModuleName(): string
                {
                    return '{$module}';
                }

                #[\\Override]
                public function getConfig(): array
                {
                    return [
                        '{$module}' => [
                            '{$snake}' => [
                                // 'enabled' => true,
                            ],
                        ],
                    ];
                }
            }

            PHP);
        $this->io->out(<<<TXT

            Next steps:
              1. Register the provider in src/Bootstrap.php (after the module's own provider
                 so its values win the aggregation order):
                   use {$ns}\\{$name}ConfigProvider;
                   \$app->addProvider(new {$name}ConfigProvider());
              2. Read the values anywhere through the container/aggregator:
                   \$aggregator->get('{$module}.{$snake}');

            TXT);

        return 0;
    }
}
