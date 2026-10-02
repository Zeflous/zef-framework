<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog;

use Psr\Container\ContainerInterface;
use Zef\Framework\Cache\DefaultCacheKeyNormalizer;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Plugin\Blog\Application\BlogService;
use Zef\Plugin\Blog\Domain\BlogAuthorization;
use Zef\Plugin\Blog\Http\BlogHandler;
use Zef\Plugin\Blog\Infrastructure\BlogObservability;
use Zef\Plugin\Blog\Infrastructure\BlogEventPublisher;
use Zef\Plugin\Blog\Infrastructure\InMemoryBlogRepository;

/** Plugin manifest. All dependencies are explicit and can be replaced by host adapters. */
final class ConfigProvider implements ConfigProviderInterface
{
    #[\Override] public function getModuleName(): string { return 'blog'; }
    #[\Override] public function getConfig(): array
    {
        return ['services' => [
            'blog.repository' => ['factory' => static fn (): InMemoryBlogRepository => new InMemoryBlogRepository(), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.cache' => ['factory' => static fn (): InMemoryCache => new InMemoryCache(new InMemoryCacheStore(), new DefaultCacheKeyNormalizer()), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.observability' => ['factory' => static fn (): BlogObservability => new BlogObservability(), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.events' => ['factory' => static fn (): BlogEventPublisher => new BlogEventPublisher(), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.limiter' => ['factory' => static fn (): InMemoryRateLimiter => new InMemoryRateLimiter(), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.authorization' => ['factory' => static fn (): BlogAuthorization => new BlogAuthorization(), 'deps' => [], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.service' => ['factory' => static fn (ContainerInterface $c, InMemoryBlogRepository $r, InMemoryCache $cache, BlogObservability $o, BlogEventPublisher $e): BlogService => new BlogService($r, $cache, $o, $e), 'deps' => ['blog.repository', 'blog.cache', 'blog.observability', 'blog.events'], 'lifetime' => ServiceLifetime::SINGLETON],
            'blog.handler' => ['factory' => static fn (ContainerInterface $c, BlogService $s, BlogAuthorization $a, InMemoryRateLimiter $l): BlogHandler => new BlogHandler($s, $a, $l), 'deps' => ['blog.service', 'blog.authorization', 'blog.limiter'], 'lifetime' => ServiceLifetime::SINGLETON],
        ], 'routes' => [
            ['method' => 'GET', 'path' => '/blog/v1/{resource}', 'handler' => 'blog.handler', 'priority' => 100], ['method' => 'POST', 'path' => '/blog/v1/{resource}', 'handler' => 'blog.handler', 'priority' => 100],
            ['method' => 'GET', 'path' => '/blog/v1/{resource}/{id}', 'handler' => 'blog.handler', 'priority' => 100], ['method' => 'PATCH', 'path' => '/blog/v1/{resource}/{id}', 'handler' => 'blog.handler', 'priority' => 100], ['method' => 'DELETE', 'path' => '/blog/v1/{resource}/{id}', 'handler' => 'blog.handler', 'priority' => 100],
            ['method' => 'GET', 'path' => '/blog/v1/posts/{id}/revisions', 'handler' => 'blog.handler', 'priority' => 200, 'defaults' => ['action' => 'revisions']], ['method' => 'POST', 'path' => '/blog/v1/posts/{id}/restore', 'handler' => 'blog.handler', 'priority' => 200, 'defaults' => ['action' => 'restore']], ['method' => 'POST', 'path' => '/blog/v1/posts/{id}/transition', 'handler' => 'blog.handler', 'priority' => 200, 'defaults' => ['action' => 'transition']], ['method' => 'POST', 'path' => '/blog/v1/comments/{id}/moderate', 'handler' => 'blog.handler', 'priority' => 200, 'defaults' => ['action' => 'moderate']],
        ]];
    }
}
