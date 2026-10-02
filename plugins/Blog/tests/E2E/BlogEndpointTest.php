<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Zef\App\Bootstrap;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;

final class BlogEndpointTest extends TestCase
{
    public function testPostCrudEndpointRequiresServerSideRole(): void
    {
        $app = Bootstrap::createApp();
        $request = new ServerRequest('POST', new Uri('http://localhost/blog/v1/posts', ['localhost']), parsedBody: ['title' => 'Safe post'], attributes: ['blog.actor' => ['id' => 'editor-1', 'role' => 'editor']]);
        self::assertSame(201, $app->handle($request)->getStatusCode());
        $denied = new ServerRequest('POST', new Uri('http://localhost/blog/v1/posts', ['localhost']), parsedBody: ['title' => 'Nope']);
        self::assertSame(403, $app->handle($denied)->getStatusCode());
    }
}
