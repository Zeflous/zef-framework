<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Domain;

/** Server-side roles accepted by the Blog authorization policy. */
enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';
    case Viewer = 'viewer';
}
