<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo plugin
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Plugin\Toko;

use Psr\Container\ContainerInterface;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ServiceLifetime;

final class ConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'toko';
    }

    #[\Override]
    public function getConfig(): array
    {
        return [
            'services' => [
                'toko.service.produk' => [
                    'factory' => static fn (): ProdukService => new ProdukService(),
                    'deps' => [],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
                'toko.handler.index' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                        ProdukService $svc,
                    ): TokoHandler => new TokoHandler($svc),
                    'deps' => ['toko.service.produk'],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
                'toko.handler.detail' => [
                    'factory' => static fn (
                        ContainerInterface $c,
                        ProdukService $svc,
                    ): ProdukDetailHandler => new ProdukDetailHandler($svc),
                    'deps' => ['toko.service.produk'],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
            ],
            'aliases' => ['toko.produk' => 'toko.service.produk'],
            'routes' => [
                ['method' => 'GET', 'path' => '/toko', 'handler' => 'toko.handler.index', 'priority' => 100],
                [
                    'method' => 'GET',
                    'path' => '/toko/produk/{id:int}',
                    'handler' => 'toko.handler.detail',
                    'priority' => 100,
                ],
            ],
        ];
    }
}
