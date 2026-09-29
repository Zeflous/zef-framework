<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold plugins/<Name>/ with a ConfigProvider (service +
 * route demo) — the plugin equivalent of make:module.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class PluginGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'plugin name');
        $dir = "{$this->root}/plugins/{$name}";

        if (is_dir($dir)) {
            $this->io->err("Plugin directory already exists: {$dir}");

            return 1;
        }

        $module = NamingRules::kebab($name);
        $ns = "Zef\\Plugin\\{$name}";
        $snake = NamingRules::snake($name);
        $this->writer->writeFile("{$dir}/ConfigProvider.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — plugin '{$module}' (scaffolded by bin/zef make:plugin).
             */

            namespace {$ns};

            use Psr\\Container\\ContainerInterface;
            use Zef\\Framework\\Config\\ConfigProviderInterface;
            use Zef\\Framework\\Container\\ServiceLifetime;
            use Zef\\Framework\\Http\\Response;
            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;

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
                            '{$module}.service.{$snake}' => [
                                'factory'  => static fn(): {$name}Service => new {$name}Service(),
                                'deps'     => [],
                                'lifetime' => ServiceLifetime::SINGLETON,
                            ],
                            '{$module}.handler.index' => [
                                'factory' => static fn (ContainerInterface \$c, {$name}Service \$svc): {$name}Handler => new {$name}Handler(\$svc),
                                'deps'    => ['{$module}.service.{$snake}'],
                            ],
                        ],
                        'routes' => [
                            ['method' => 'GET', 'path' => '/{$module}', 'handler' => '{$module}.handler.index', 'priority' => 100],
                        ],
                    ];
                }
            }

            PHP);
        $this->writer->writeFile("{$dir}/{$name}Service.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — plugin '{$module}' (scaffolded by bin/zef make:plugin).
             */

            namespace {$ns};

            final class {$name}Service
            {
                public function describe(): string
                {
                    return '{$module} service';
                }
            }

            PHP);
        $this->writer->writeFile("{$dir}/{$name}Handler.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — plugin '{$module}' (scaffolded by bin/zef make:plugin).
             */

            namespace {$ns};

            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;
            use Zef\\Framework\\Http\\Response;

            final class {$name}Handler implements RequestHandlerInterface
            {
                public function __construct(
                    private readonly {$name}Service \$service,
                ) {
                }

                #[\\Override]
                public function handle(ServerRequestInterface \$request): ResponseInterface
                {
                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode(['plugin' => \$this->service->describe(), 'status' => 'ok'], JSON_THROW_ON_ERROR),
                    );
                }
            }

            PHP);
        $this->io->out(<<<TXT

            Next steps:
              1. Register the plugin in src/Bootstrap.php:
                   use {$ns}\\ConfigProvider as {$name}ConfigProvider;
                   \$app->addProvider(new {$name}ConfigProvider());
              2. Regenerate the zero-composer classmap:
                   composer dump-autoload

            TXT);

        return 0;
    }
}
