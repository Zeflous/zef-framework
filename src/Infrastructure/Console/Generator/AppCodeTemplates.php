<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters).
 * Scaffold code templates extracted from AppSkeleton (php:S2042): the
 * executable app stubs (RoadRunner worker, Zef maker wrapper, bootstrap,
 * Home module) rendered for one target. Bodies are byte-identical
 * moves — no behavioural changes.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\TemplateLoader;

final readonly class AppCodeTemplates
{
    public function __construct(
        private TemplateLoader $templates = new TemplateLoader(),
    ) {}

    public function worker(): string
    {
        return $this->templates->render(<<<'PHP_WRAP'
            <?php

            /**
             * RoadRunner HTTP worker entrypoint (scaffolded by bin/zef make:app).
             * Requires spiral/roadrunner-http + nyholm/psr7 (composer install).
             * Run: vendor/bin/rr serve -c .rr.yaml
             */

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            if (PHP_VERSION_ID < 80400) {
                fwrite(STDERR, "This application requires PHP >= 8.4\n");
                exit(1);
            }

            if (!class_exists(Spiral\RoadRunner\Http\PSR7Worker::class)) {
                fwrite(STDERR, "RoadRunner bridge not installed.\n"
                    . "Run: composer require spiral/roadrunner-http nyholm/psr7\n");
                exit(1);
            }

            $psr17 = new Nyholm\Psr7\Factory\Psr17Factory();
            $worker = new Spiral\RoadRunner\Http\PSR7Worker(
                Spiral\RoadRunner\Worker::create(),
                $psr17,
                $psr17,
                $psr17,
            );

            $debug = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
            $app = App\Bootstrap::createApp($debug);
            $runtime = new Zef\Framework\Runtime\RoadRunnerRuntime(
                $app,
                new Zef\Framework\Runtime\RoadRunnerWorkerAdapter($worker),
                maxJobs: (int) (getenv('ZEF_WORKER_MAX_JOBS') ?: 0),
                memoryLimitBytes: (int) (getenv('ZEF_WORKER_MEMORY_LIMIT') ?: 0),
            );

            exit($runtime->run());

            PHP_WRAP);
    }

    public function appZef(): string
    {
        return $this->templates->render(<<<'PHP_WRAP'
            #!/usr/bin/env php
            <?php

            /**
             * ZEF Maker wrapper for this application (scaffolded by make:app).
             * Forwards `list` and `make:*` to the framework's ZefMaker with THIS
             * app as the root, so scaffolding lands in ./modules and ./src here.
             */

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            if (PHP_VERSION_ID < 80400) {
                fwrite(STDERR, "This application requires PHP >= 8.4\n");
                exit(1);
            }

            $args = $_SERVER['argv'] ?? [];
            $command = $args[1] ?? null;
            $io = new Zef\Framework\Console\ConsoleIO();
            $maker = new Zef\Framework\Console\ZefMaker(dirname(__DIR__), $io);

            if ($command === 'list' || ($command !== null && str_starts_with((string) $command, 'make:'))) {
                exit($maker->run($args));
            }

            fwrite(STDERR, "Unknown command '" . ($command ?? '') . "'. This wrapper supports"
                . " the maker commands only: run `php bin/zef list`.\n");
            exit(1);

            PHP_WRAP);
    }

    public function appBootstrap(string $pascal): string
    {
        $module = "Zef\\Module\\{$pascal}\\ConfigProvider";

        return $this->templates->render(<<<PHP
            <?php

            /**
             * Composition root (scaffolded by bin/zef make:app).
             * Register every module provider here — the kernel boots them in order.
             */

            declare(strict_types=1);

            namespace App;

            use Zef\\Framework\\Application;
            use Zef\\Middleware\\ConfigProvider as MiddlewareConfigProvider;
            use {$module} as HomeConfigProvider;

            final class Bootstrap
            {
                public static function createApp(
                    bool \$debug = false,
                    ?\\Psr\\Log\\LoggerInterface \$logger = null,
                ): Application {
                    \$app = new Application(\$debug, \$logger);
                    \$app->setTrustedHosts(['localhost', '127.0.0.1', '::1']);
                    \$app->addProvider(new MiddlewareConfigProvider(\$debug));
                    \$app->addProvider(new HomeConfigProvider());

                    return \$app;
                }
            }

            PHP);
    }

    public function homeConfigProvider(string $moduleNamespace, string $kebab): string
    {
        return $this->templates->render(<<<PHP
            <?php

            declare(strict_types=1);

            /*
             * Home module of the '{$kebab}' app (scaffolded by bin/zef make:app).
             */

            namespace {$moduleNamespace};

            use Zef\\Framework\\Config\\ConfigProviderInterface;

            final class ConfigProvider implements ConfigProviderInterface
            {
                #[\\Override]
                public function getModuleName(): string
                {
                    return 'home';
                }

                #[\\Override]
                public function getConfig(): array
                {
                    return [
                        'services' => [
                            'home.handler.index' => [
                                'factory' => static fn(): HomeHandler => new HomeHandler(),
                                'deps'    => [],
                            ],
                        ],
                        'routes' => [
                            [
                                'method' => 'GET',
                                'path' => '/',
                                'handler' => 'home.handler.index',
                                'priority' => 100,
                                'name' => 'home.index',
                            ],
                        ],
                    ];
                }
            }

            PHP);
    }

    public function homeHandler(string $moduleNamespace, string $kebab): string
    {
        return $this->templates->render(<<<PHP
            <?php

            declare(strict_types=1);

            /*
             * Home handler of the '{$kebab}' app (scaffolded by bin/zef make:app).
             */

            namespace {$moduleNamespace};

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
                        json_encode(['app' => '{$kebab}', 'status' => 'ok'], JSON_THROW_ON_ERROR),
                    );
                }
            }

            PHP);
    }
}
