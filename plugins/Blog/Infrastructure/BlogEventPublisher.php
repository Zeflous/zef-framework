<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Infrastructure;

/**
 * Plugin event port. Other plugins may subscribe through `blog.events`; a
 * production implementation should persist deliveries to an outbox first.
 */
final class BlogEventPublisher
{
    /** @var list<callable(array<string,mixed>):void> */ private array $subscribers = [];
    /** @param callable(array<string,mixed>):void $subscriber */ public function subscribe(callable $subscriber): void { $this->subscribers[] = $subscriber; }
    /** @param array<string,mixed> $event */ public function publish(array $event): void { foreach ($this->subscribers as $subscriber) { $subscriber($event); } }
}
