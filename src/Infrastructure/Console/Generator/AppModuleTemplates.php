<?php

declare(strict_types=1);

/*
 * ZEF Framework — Infrastructure layer (outbound adapters).
 * App/Home-module code templates extracted from AppCodeTemplates
 * (php:S2042): the composition-root bootstrap and the scaffolded Home
 * module (ConfigProvider + handler) rendered for one target. Bodies are
 * byte-identical moves — no behavioural changes.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\TemplateLoader;

final readonly class AppModuleTemplates
{
    public function __construct(
        private TemplateLoader $templates = new TemplateLoader(),
    ) {}

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
