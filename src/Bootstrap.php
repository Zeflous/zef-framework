<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\App;

use Psr\Log\LoggerInterface;
use Zef\Framework\Application;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Middleware\ConfigProvider as MiddlewareConfigProvider;
use Zef\Module\Core\ConfigProvider as CoreConfigProvider;
use Zef\Module\Health\ConfigProvider as HealthConfigProvider;
use Zef\Plugin\Blog\ConfigProvider as BlogConfigProvider;
use Zef\Plugin\Toko\ConfigProvider as TokoConfigProvider;

final class Bootstrap
{
    public static function createApp(
        bool $debug = false,
        ?LoggerInterface $logger = null,
        ?int $maxBodyBytes = null,
    ): Application {
        if ($maxBodyBytes === null) {
            $rawLimit = getenv('ZEF_MAX_BODY_BYTES');
            $maxBodyBytes = ($rawLimit !== false && ctype_digit((string) $rawLimit))
                ? (int) $rawLimit
                : null;
        }
        $app = new Application(
            $debug,
            $logger,
            $maxBodyBytes === null ? null : new RequestBodyPolicy($maxBodyBytes),
        );
        $trusted = getenv('ZEF_TRUSTED_HOSTS');
        $hosts = $trusted !== false && trim($trusted) !== ''
            ? array_map(trim(...), explode(',', $trusted))
            : ['localhost', '127.0.0.1', '::1', 'zef.test'];
        $app->setTrustedHosts($hosts);
        $app->addProvider(new MiddlewareConfigProvider($debug));
        $app->addProvider(new CoreConfigProvider());
        $app->addProvider(new TokoConfigProvider());
        $app->addProvider(new BlogConfigProvider());
        $app->addProvider(new HealthConfigProvider());

        return $app;
    }
}
