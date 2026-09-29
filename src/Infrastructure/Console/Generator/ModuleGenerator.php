<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold modules/<Pascal>/ with a ConfigProvider + HomeHandler.
 * Behavioural port of the v2.8.0 `bin/zef make:module` implementation.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class ModuleGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $module = NamingRules::moduleName($rawName);
        $pascal = NamingRules::pascal($module);
        $dir = "{$this->root}/modules/{$pascal}";

        if (is_dir($dir)) {
            $this->io->err("Module directory already exists: {$dir}");

            return 1;
        }

        $ns = "Zef\\Module\\{$pascal}";
        $this->writer->writeFiles([
            "{$dir}/ConfigProvider.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — module '{$module}' (scaffolded by bin/zef make:module).
                 */

                namespace {$ns};

                use Psr\\Http\\Message\\ResponseInterface;
                use Psr\\Http\\Message\\ServerRequestInterface;
                use Psr\\Http\\Server\\RequestHandlerInterface;
                use Zef\\Framework\\Config\\ConfigProviderInterface;
                use Zef\\Framework\\Http\\Response;

                final class ConfigProvider implements ConfigProviderInterface
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
                            'services' => [
                                '{$module}.handler.home' => [
                                    'factory' => static fn(): HomeHandler => new HomeHandler(),
                                    'deps'    => [],
                                ],
                            ],
                            'routes' => [
                                ['method' => 'GET', 'path' => '/{$module}', 'handler' => '{$module}.handler.home', 'priority' => 100, 'name' => '{$module}.home'],
                            ],
                        ];
                    }
                }

                PHP,
            "{$dir}/HomeHandler.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — module '{$module}' (scaffolded by bin/zef make:module).
                 */

                namespace {$ns};

                use Psr\\Http\\Message\\ResponseInterface;
                use Psr\\Http\\Message\\ServerRequestInterface;
                use Psr\\Http\\Server\\RequestHandlerInterface;
                use Zef\\Framework\\Http\\Response;

                final class HomeHandler implements RequestHandlerInterface
                {
                    #[\\Override]
                    public function handle(ServerRequestInterface \$request): ResponseInterface
                    {
                        return new Response(
                            200,
                            ['Content-Type' => 'application/json'],
                            json_encode(['module' => '{$module}', 'status' => 'ok'], JSON_THROW_ON_ERROR),
                        );
                    }
                }

                PHP,
        ]);
        $this->io->out(<<<TXT

            Next steps:
              1. Register the module in src/Bootstrap.php:
                   use {$ns}\\ConfigProvider as {$pascal}ConfigProvider;
                   \$app->addProvider(new {$pascal}ConfigProvider());
              2. Regenerate the zero-composer classmap:
                   composer dump-autoload

            TXT);

        return 0;
    }
}
