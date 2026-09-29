<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters).
 * Entry-point script templates extracted from AppCodeTemplates
 * (php:S2042): the executable bin/ stubs (RoadRunner worker, Zef maker
 * wrapper) rendered for one target. Bodies are byte-identical moves — no
 * behavioural changes.
 */

namespace Zef\Framework\Console\Generator;

final readonly class AppEntryTemplates
{
    public function worker(): string
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

    public function appZef(): string
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
}
