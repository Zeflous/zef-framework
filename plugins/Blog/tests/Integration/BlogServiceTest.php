<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\DefaultCacheKeyNormalizer;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Plugin\Blog\Application\BlogService;
use Zef\Plugin\Blog\Infrastructure\BlogObservability;
use Zef\Plugin\Blog\Infrastructure\InMemoryBlogRepository;

final class BlogServiceTest extends TestCase
{
    public function testPostRevisionRestoreAndAudit(): void
    {
        $service = new BlogService(new InMemoryBlogRepository(), new InMemoryCache(new InMemoryCacheStore(), new DefaultCacheKeyNormalizer()), new BlogObservability());
        $post = $service->create('posts', ['title' => 'Hello'], 'author-1'); $service->update('posts', $post['id'], ['title' => 'Changed'], 'author-1');
        $restored = $service->restore($post['id'], 1, 'author-1');
        self::assertSame('Hello', $restored['title']); self::assertNotEmpty($service->audit()); self::assertCount(2, $service->revisions($post['id']));
    }
}
