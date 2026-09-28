<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a standalone ZEF application skeleton at an arbitrary
 * path (`bin/zef make:app <path>`). This is the in-repo substitute for a
 * separate `composer create-project` installer package: one command produces
 * a self-sufficient project (composer.json + composition root + Home module +
 * RR config + worker/web entrypoints) that boots, serves and passes PHPStan.
 *
 * Path safety rules:
 *   - absolute paths are used as-is; relative paths resolve against the
 *     FRAMEWORK root (deterministic, independent of the caller's cwd) and
 *     are lexically normalized (`..` segments are collapsed, so `../demo`
 *     escapes the framework root instead of being misread as nested);
 *     "absolute" follows the HOST platform: `/`-rooted on POSIX, drive or
 *     UNC roots on Windows, where both separator styles are accepted
 *     (issue #110: sys_get_temp_dir() returns backslash-separated paths);
 *   - the target must not exist, or must be an empty directory;
 *   - the target must live OUTSIDE the framework root (a standalone app
 *     nested inside the framework would break composer/path-repo layout) —
 *     checked against BOTH the lexical and the realpath'd form of the root,
 *     because Windows realpath() expands 8.3 short names (RUNNER~1 ->
 *     runneradmin) and a POSIX symlinked root aliases the same way;
 *   - the generated composer.json references the framework through a path
 *     repository computed from the LONGEST COMMON ANCESTOR of target and
 *     framework root (not merely `basename`), so deeply nested checkouts
 *     still resolve.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\InvalidNameException;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;
use Zef\Framework\Foundation\ZefVersion;

final readonly class AppGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $targetArg = $this->stringOption($argv, 'path') ?? $rawName;
        if ($targetArg === null || trim($targetArg) === '') {
            $this->io->err('Usage: bin/zef make:app <path> [--name=<project>] [--address=host:port]');

            return 1;
        }

        $target = $this->resolveTarget(rtrim($targetArg, '/'));
        if ($target === null) {
            return 1;
        }

        $kebab = $this->stringOption($argv, 'name') ?? basename($target);

        try {
            $kebab = str_replace('_', '-', NamingRules::moduleName($kebab));
        } catch (InvalidNameException $e) {
            $this->io->err(str_replace('module name', 'project name', $e->getMessage()));

            return 1;
        }

        $envAddress = getenv('ZEF_HTTP_ADDRESS');
        $address = $this->stringOption($argv, 'address')
            ?? (is_string($envAddress) && $envAddress !== '' ? $envAddress : '0.0.0.0:8080');
        if (preg_match('/^[0-9.]+:[0-9]{1,5}$/', $address) !== 1 && preg_match('/^\[[0-9a-f:]+\]:[0-9]{1,5}$/', $address) !== 1) {
            $this->io->err("Invalid --address '{$address}'. Expected host:port (e.g. 0.0.0.0:8080).");

            return 1;
        }

        $pascal = NamingRules::pascal($kebab);
        $frameworkRef = $this->relativeFrameworkRef($target);
        $files = $this->blueprint($target, $kebab, $pascal, $address, $frameworkRef);

        try {
            $this->writer->writeFiles($files);
        } finally {
            $this->io->out('');
        }

        $this->io->out(<<<TXT

            Standalone ZEF app '{$kebab}' scaffolded at {$target}.

            Next steps:
              1. composer install
              2. composer serve            # dev server on {$address}
                 vendor/bin/rr serve       # or RoadRunner production runtime
              3. curl http://localhost:8080/   (the Home module answers JSON)

            The skeleton is self-sufficient: app/Bootstrap.php is the composition
            root, modules/{$pascal} is your first hexagonal module. Read
            docs/TUTORIAL-CQRS-101.md in the framework repo for the full tour.

            TXT);

        return 0;
    }

    /**
     * Lexically normalize a path: collapse `.`, `..` and duplicate slashes
     * WITHOUT touching the filesystem (the target may not exist yet). An
     * absolute path never escapes above its root — `/` on POSIX, the drive
     * or UNC prefix on Windows; a relative path keeps leading `..` segments.
     * On Windows both separator styles are accepted (PHP itself mixes them:
     * sys_get_temp_dir() returns backslashes); on POSIX a backslash stays a
     * legal filename character, so the swap is platform-gated.
     */
    private function normalize(string $path): string
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        if ($windows) {
            $path = str_replace('\\', '/', $path);
        }

        $prefix = '';
        if ($windows && preg_match('#^([A-Za-z]:)(/|$)#', $path) === 1) {
            // Drive root (C:/): the `..` walk must never pop past it.
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif ($windows && str_starts_with($path, '//') && strlen($path) > 2) {
            // UNC root (//server/share): the server+share pair is the root.
            $segments = explode('/', substr($path, 2), 3);
            if ($segments[0] !== '') {
                $prefix = '//' . $segments[0]
                    . (isset($segments[1]) && $segments[1] !== '' ? '/' . $segments[1] : '');
                $path = substr($path, strlen($prefix));
            }
        }

        $absolute = $prefix !== '' || str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif (!$absolute) {
                    $parts[] = '..';
                }

                continue;
            }
            $parts[] = $segment;
        }

        $normalized = implode('/', $parts);
        if ($absolute) {
            return $prefix . '/' . $normalized;
        }

        return $normalized === '' ? '.' : $normalized;
    }

    /**
     * Absolute in the HOST's terms: `/`-rooted on POSIX; on Windows a drive
     * letter or a UNC `//` root (a `C:/...` argument is relative on POSIX,
     * where `C:` is a legal directory name).
     */
    private function isAbsolute(string $normalizedPath): bool
    {
        if (str_starts_with($normalizedPath, '/')) {
            return true;
        }

        return DIRECTORY_SEPARATOR === '\\'
            && (preg_match('#^[A-Za-z]:/#', $normalizedPath) === 1
                || str_starts_with($normalizedPath, '//'));
    }

    /** Resolve + validate the scaffold target; null means "error already reported". */
    private function resolveTarget(string $raw): ?string
    {
        $normalized = $this->normalize($raw);
        $target = $this->isAbsolute($normalized)
            ? $normalized
            : $this->normalize(rtrim($this->root, '/') . '/' . ltrim($normalized, '/'));

        // Both the LEXICAL and the realpath'd root forms must reject the
        // target: on Windows realpath() expands 8.3 short names
        // (RUNNER~1 -> runneradmin) so a lexically-inside target would
        // otherwise compare unequal to the canonicalized root; on POSIX a
        // symlinked root aliases the same way (the reverse of #110).
        $rootForms = [$this->normalize($this->root)];
        $canonicalRoot = (string) realpath($this->root);
        if ($canonicalRoot !== '') {
            $rootForms[] = $this->normalize($canonicalRoot);
        }

        foreach ($rootForms as $rootForm) {
            if ($target === $rootForm || str_starts_with($target, $rootForm . '/')) {
                $this->io->err("Refusing to scaffold INSIDE the framework root ({$target}). Choose a path outside it.");

                return null;
            }
        }

        if (is_dir($target)) {
            $entries = scandir($target);
            if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
                $this->io->err("Target directory exists and is not empty: {$target}");

                return null;
            }
        } elseif (is_file($target)) {
            $this->io->err("Target path is a file, not a directory: {$target}");

            return null;
        }

        return $target;
    }

    /**
     * composer path-repository URL pointing from the scaffolded app back to
     * the framework checkout. Computed from the LONGEST COMMON ANCESTOR so
     * every nesting depth resolves: target /apps/demo with the framework at
     * /zef-framework yields '../zef-framework', while target /tmp/demo with
     * the framework at /home/me/zef-framework yields the full
     * '../../home/me/zef-framework' climb instead of a broken basename.
     */
    private function relativeFrameworkRef(string $target): string
    {
        // LEXICAL root, deliberately: $target itself is lexical, and the
        // climb must stay in ONE namespace — on Windows realpath() expands
        // 8.3 short names (RUNNER~1 -> runneradmin), which would break the
        // common-ancestor arithmetic mid-path (issue #110).
        $rootForm = $this->normalize($this->root);

        $fromParts = $this->pathSegments($this->normalize($target));
        $toParts = $this->pathSegments($rootForm);

        $common = 0;
        $max = min(count($fromParts), count($toParts));
        while ($common < $max && $fromParts[$common] === $toParts[$common]) {
            ++$common;
        }

        $parts = [
            ...array_fill(0, count($fromParts) - $common, '..'),
            ...array_slice($toParts, $common),
        ];

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /** Split an absolute normalized path into segments (root `/` → []). */
    /** @return list<string> */
    private function pathSegments(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        // @var list<string>
        return explode('/', ltrim($path, '/'));
    }

    /** @return array<string,string> absolute path => file contents */
    private function blueprint(string $target, string $kebab, string $pascal, string $address, string $frameworkRef): array
    {
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
                fwrite(STDERR, "RoadRunner bridge not installed.\nRun: composer require spiral/roadrunner-http nyholm/psr7\n");
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

            fwrite(STDERR, "Unknown command '" . ($command ?? '') . "'. This wrapper supports the maker commands only: run `php bin/zef list`.\n");
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
                            ['method' => 'GET', 'path' => '/', 'handler' => 'home.handler.index', 'priority' => 100, 'name' => 'home.index'],
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

    /** Extract the value of `--key=value` style options from argv. */
    /**
     * @param array<int, mixed> $argv
     */
    private function stringOption(array $argv, string $key): ?string
    {
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, "--{$key}=")) {
                $value = substr($arg, strlen($key) + 3);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
