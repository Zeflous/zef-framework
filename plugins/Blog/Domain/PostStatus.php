<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Domain;

/** The only valid post lifecycle states. */
enum PostStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Review,
            self::Review => $next === self::Draft || $next === self::Published,
            self::Published => $next === self::Archived,
            self::Archived => $next === self::Draft,
        };
    }
}
