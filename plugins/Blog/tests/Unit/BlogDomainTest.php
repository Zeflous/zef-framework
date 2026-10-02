<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Plugin\Blog\Domain\BlogAuthorization;
use Zef\Plugin\Blog\Domain\PostStatus;
use Zef\Plugin\Blog\Domain\Role;

final class BlogDomainTest extends TestCase
{
    public function testLifecycleAndAuthorOwnershipAreEnforced(): void
    {
        self::assertTrue(PostStatus::Draft->canTransitionTo(PostStatus::Review));
        self::assertFalse(PostStatus::Published->canTransitionTo(PostStatus::Draft));
        $policy = new BlogAuthorization();
        self::assertTrue($policy->allows(Role::Author, 'update', ['authorId' => 'a'], 'a'));
        self::assertFalse($policy->allows(Role::Author, 'delete', ['authorId' => 'b'], 'a'));
    }
}
