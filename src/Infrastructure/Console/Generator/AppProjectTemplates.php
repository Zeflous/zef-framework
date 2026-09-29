<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters).
 * Scaffold file templates extracted from AppSkeleton (php:S2042): the
 * non-executable project files (env example, RoadRunner config, README,
 * web entrypoint) rendered for one target. Bodies are byte-identical
 * moves — no behavioural changes.
 */

namespace Zef\Framework\Console\Generator;

final readonly class AppProjectTemplates
{
    public function envExample(string $address): string
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

    public function rrYaml(string $address): string
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

    public function readme(string $kebab, string $address): string
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

    public function publicIndex(): string
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
}
