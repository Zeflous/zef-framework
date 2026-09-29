<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * Scaffold file templates extracted from AppGenerator (php:S2042): the
 * composer.json blueprint and every stub file body (env example, RR
 * config, README, entrypoints, Home module) rendered for one target.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Foundation\ZefVersion;

final class AppSkeleton
{
    /** @return array<string,string> absolute path => file contents */
    public function blueprint(
        string $target,
        string $kebab,
        string $pascal,
        string $address,
        string $frameworkRef,
    ): array {
        $moduleNamespace = "Zef\\Module\\{$pascal}";

        // The path repository below carries an explicit `versions` pin derived
        // from ZefVersion::VERSION. Without it, Composer resolves the path repo
        // as `dev-main` (this repo has no `version` composer field), which never
        // satisfies a tagged constraint and breaks `composer install` on a
        // freshly scaffolded app (issue #210).
        $composer = [
            'name' => "{$kebab}/app",
            'description' => "Standalone ZEF Framework application '{$kebab}' (scaffolded by bin/zef make:app).",
            'type' => 'project',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.4',
                'mbetixz/zef-framework' => '^' . ZefVersion::VERSION,
                'spiral/roadrunner-http' => '^4.1',
                'nyholm/psr7' => '^1.8',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $frameworkRef,
                    'options' => [
                        'symlink' => true,
                        'versions' => ['mbetixz/zef-framework' => ZefVersion::VERSION],
                    ],
                ],
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                    'Zef\Module\\' => 'modules/',
                    'Zef\Plugin\\' => 'plugins/',
                ],
            ],
            'scripts' => [
                'serve' => '@php -S ' . $address . ' public/index.php',
                'rr:serve' => 'rr serve -c .rr.yaml',
                'zef' => '@php bin/zef',
            ],
        ];
        $composerJson = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            "{$target}/composer.json" => $composerJson . "\n",
            "{$target}/.env.example" => $this->envExample($address),
            "{$target}/.rr.yaml" => $this->rrYaml($address),
            "{$target}/README.md" => $this->readme($kebab, $address),
            "{$target}/public/index.php" => $this->publicIndex(),
            "{$target}/bin/worker.php" => $this->worker(),
            "{$target}/bin/zef" => $this->appZef(),
            "{$target}/app/Bootstrap.php" => $this->appBootstrap($pascal),
            "{$target}/modules/{$pascal}/ConfigProvider.php" => $this->homeConfigProvider($moduleNamespace, $kebab),
            "{$target}/modules/{$pascal}/HomeHandler.php" => $this->homeHandler($moduleNamespace, $kebab),
        ];
    }

    private function envExample(string $address): string
    {
        return <<<ENV
            # ZEF runtime knobs (copy to .env or export in your shell).
            ZEF_ENV=dev
            ZEF_DEBUG=0
            ZEF_HTTP_ADDRESS={$address}
            ZEF_WORKER_MAX_JOBS=0
            ZEF_WORKER_MEMORY_LIMIT=0

            ENV;
    }

    private function rrYaml(string $address): string
    {
        return <<<YAML
            # RoadRunner v2025.1 configuration (scaffolded by bin/zef make:app).
            # Run: vendor/bin/rr serve -c .rr.yaml

            version: "2025.1"

            server:
              command: "php bin/worker.php"
              relay: "pipes"

            http:
              address: {$address}
              middleware: [ "gzip" ]
              pool:
                num_workers: 4
                max_jobs: 0
                supervisor:
                  max_worker_memory: 512

            logs:
              mode: development
              level: info
              encoding: console

            YAML;
    }

    private function readme(string $kebab, string $address): string
    {
        return <<<MD
            # {$kebab}

            Standalone ZEF Framework application (hexagonal · PSR-15 · RoadRunner · PHP 8.4+).

            ## Quickstart

            ```bash
            composer install
            composer serve                  # PHP built-in dev server on {$address}
            vendor/bin/rr serve -c .rr.yaml # production-style RoadRunner runtime
            ```

            ## Layout (hexagonal)

            - `app/Bootstrap.php` — composition root (registers providers/modules)
            - `modules/Home/` — first module: `ConfigProvider` + PSR-15 handler
            - `public/index.php` — web SAPI entrypoint
            - `bin/worker.php` — RoadRunner worker entrypoint
            - `bin/zef` — ZEF Maker wrapper (scaffold modules, commands, queries…)
            - `.rr.yaml` — RoadRunner pool/HTTP configuration

            ## Scaffold more modules

            ```bash
            composer zef -- make:module Orders
            composer zef -- make:command PlaceOrder --module=orders
            composer zef -- list
            ```

            Read docs/TUTORIAL-CQRS-101.md in the framework repo for the
            full Zero-to-Hero walkthrough.

            ## Environment

            Copy `.env.example` and adjust the `ZEF_*` knobs. `ZEF_DEBUG=1`
            enables verbose error surfaces during development only.

            MD;
    }

    private function publicIndex(): string
    {
        return <<<'PHP_WRAP'
            <?php

            /**
             * Web SAPI entrypoint (scaffolded by bin/zef make:app).
             * Dev server: composer serve / php -S 0.0.0.0:8080 public/index.php
             */

            declare(strict_types=1);

            require __DIR__ . '/../vendor/autoload.php';

            if (PHP_VERSION_ID < 80400) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                exit("This application requires PHP >= 8.4\n");
            }

            $debug = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);

            try {
                $app = App\Bootstrap::createApp($debug);
                $response = $app->handleGlobals();
                $app->emit($response);
            } catch (Throwable $e) {
                if (!headers_sent()) {
                    http_response_code(500);
                }
                header('Content-Type: text/plain; charset=utf-8');
                echo $debug ? get_class($e) . ': ' . $e->getMessage() : 'Internal Server Error';
            }

            PHP_WRAP;
    }

    private function worker(): string
    {
        return <<<'PHP_WRAP'
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

            PHP_WRAP;
    }

    private function appZef(): string
    {
        return <<<'PHP_WRAP'
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

            PHP_WRAP;
    }

    private function appBootstrap(string $pascal): string
    {
        $module = "Zef\\Module\\{$pascal}\\ConfigProvider";

        return <<<PHP
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

            PHP;
    }

    private function homeConfigProvider(string $moduleNamespace, string $kebab): string
    {
        return <<<PHP
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

            PHP;
    }

    private function homeHandler(string $moduleNamespace, string $kebab): string
    {
        return <<<PHP
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

            PHP;
    }
}
