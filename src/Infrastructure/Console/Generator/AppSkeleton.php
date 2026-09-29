<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * Assembles the scaffolded application file map (php:S2042): the
 * composer.json blueprint plus every stub file body, rendered through
 * the AppProjectTemplates / AppCodeTemplates renderers.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\TemplateLoader;
use Zef\Framework\Foundation\ZefVersion;

final readonly class AppSkeleton
{
    public function __construct(
        private TemplateLoader $templates = new TemplateLoader(),
    ) {}

    /** @return array<string,string> absolute path => file contents */
    public function blueprint(
        string $target,
        string $kebab,
        string $pascal,
        string $address,
        string $frameworkRef,
    ): array {
        $moduleNamespace = "Zef\\Module\\{$pascal}";
        $projectFiles = new AppProjectTemplates($this->templates);
        $codeFiles = new AppCodeTemplates($this->templates);

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
            "{$target}/.env.example" => $projectFiles->envExample($address),
            "{$target}/.rr.yaml" => $projectFiles->rrYaml($address),
            "{$target}/README.md" => $projectFiles->readme($kebab, $address),
            "{$target}/public/index.php" => $projectFiles->publicIndex(),
            "{$target}/bin/worker.php" => $codeFiles->worker(),
            "{$target}/bin/zef" => $codeFiles->appZef(),
            "{$target}/app/Bootstrap.php" => $codeFiles->appBootstrap($pascal),
            "{$target}/modules/{$pascal}/ConfigProvider.php" => $codeFiles->homeConfigProvider($moduleNamespace, $kebab),
            "{$target}/modules/{$pascal}/HomeHandler.php" => $codeFiles->homeHandler($moduleNamespace, $kebab),
        ];
    }
}
