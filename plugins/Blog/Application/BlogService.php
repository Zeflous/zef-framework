<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Application;

use Zef\Framework\Cache\CacheInterface;
use Zef\Plugin\Blog\Domain\PostStatus;
use Zef\Plugin\Blog\Infrastructure\BlogObservability;
use Zef\Plugin\Blog\Infrastructure\BlogEventPublisher;
use Zef\Plugin\Blog\Infrastructure\InMemoryBlogRepository;

/** Application boundary for blog use cases; HTTP handlers contain no business rules. */
final class BlogService
{
    /** @var list<string> */
    public const RESOURCES = ['posts', 'categories', 'tags', 'authors', 'comments', 'media'];

    public function __construct(
        private readonly InMemoryBlogRepository $repository,
        private readonly CacheInterface $cache,
        private readonly BlogObservability $observability,
        private readonly BlogEventPublisher $events = new BlogEventPublisher(),
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(string $type, array $input, string $actorId): array
    {
        $this->assertType($type);
        $record = $this->normalize($type, $input) + [
            'id' => bin2hex(random_bytes(12)), 'authorId' => $actorId, 'createdAt' => gmdate(DATE_ATOM),
        ];
        if ($type === 'posts') {
            $record += ['status' => PostStatus::Draft->value, 'locale' => 'en', 'slug' => $this->slug((string) ($record['title'] ?? 'post'))];
            $this->revision($record, $actorId, 'created');
        }
        if ($type === 'comments') { $record += ['status' => 'pending']; }
        $this->repository->save($type, $record);
        $this->mutated('created', $type, $record['id'], $actorId, null, $record);
        return $record;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $type, string $id, array $input, string $actorId): array
    {
        $before = $this->required($type, $id);
        $record = array_replace($before, $this->normalize($type, $input), ['updatedAt' => gmdate(DATE_ATOM)]);
        if ($type === 'posts') { $this->revision($before, $actorId, 'updated'); }
        $this->repository->save($type, $record);
        $this->mutated('updated', $type, $id, $actorId, $before, $record);
        return $record;
    }

    /** @return array<string,mixed> */
    public function transition(string $id, string $status, string $actorId): array
    {
        $post = $this->required('posts', $id);
        $next = PostStatus::from($status); $current = PostStatus::from((string) $post['status']);
        if (!$current->canTransitionTo($next)) { throw new \DomainException('Invalid post lifecycle transition.'); }
        $this->revision($post, $actorId, 'transition');
        $post['status'] = $next->value; $post['publishedAt'] = $next === PostStatus::Published ? gmdate(DATE_ATOM) : ($post['publishedAt'] ?? null);
        $this->repository->save('posts', $post); $this->mutated('published', 'posts', $id, $actorId, null, $post);
        return $post;
    }

    /** @return array<string,mixed> */
    public function moderate(string $id, string $status, string $actorId): array
    {
        if (!in_array($status, ['approved', 'spam'], true)) { throw new \InvalidArgumentException('Invalid comment moderation status.'); }
        $before = $this->required('comments', $id); $comment = $before; $comment['status'] = $status; $comment['updatedAt'] = gmdate(DATE_ATOM);
        $this->repository->save('comments', $comment); $this->mutated('updated', 'comments', $id, $actorId, $before, $comment);
        return $comment;
    }

    /** Publishes due reviewed posts; call this from a scheduler or worker. */
    public function publishScheduled(string $actorId = 'scheduler'): int
    {
        $count = 0;
        foreach ($this->repository->all('posts') as $post) {
            if (($post['status'] ?? null) === PostStatus::Review->value && isset($post['scheduledAt']) && strtotime((string) $post['scheduledAt']) <= time()) {
                $this->transition($post['id'], PostStatus::Published->value, $actorId); ++$count;
            }
        }
        return $count;
    }

    /** @return array<string,mixed> */
    public function restore(string $id, int $version, string $actorId): array
    {
        $revisions = $this->repository->revisions($id); $revision = $revisions[$version - 1] ?? null;
        if ($revision === null) { throw new \OutOfBoundsException('Revision not found.'); }
        $before = $this->required('posts', $id); $post = $revision['snapshot']; $post['updatedAt'] = gmdate(DATE_ATOM);
        $this->revision($before, $actorId, 'restored'); $this->repository->save('posts', $post); $this->mutated('updated', 'posts', $id, $actorId, $before, $post);
        return $post;
    }

    /** @return list<array<string,mixed>> */ public function revisions(string $id): array { return $this->repository->revisions($id); }
    public function delete(string $type, string $id, string $actorId): void { $before = $this->required($type, $id); $this->repository->delete($type, $id); $this->mutated('deleted', $type, $id, $actorId, $before, null); }
    /** @param array<string,string> $query @return array{items:list<array<string,mixed>>,meta:array<string,int>} */
    public function list(string $type, array $query): array
    {
        $this->assertType($type); $key = 'blog:list:' . $type . ':' . hash('sha256', json_encode($query, JSON_THROW_ON_ERROR));
        $cached = $this->cache->get($key); if (is_array($cached)) { return $cached; }
        $items = array_filter($this->repository->all($type), function (array $row) use ($query): bool {
            foreach (['status', 'authorId', 'locale', 'categoryId', 'tag'] as $field) { if (($query[$field] ?? '') !== '' && (string) ($row[$field] ?? '') !== $query[$field]) return false; }
            $term = mb_strtolower((string) ($query['q'] ?? '')); return $term === '' || str_contains(mb_strtolower(json_encode($row, JSON_THROW_ON_ERROR)), $term);
        });
        $sort = $query['sort'] ?? 'createdAt'; if (!in_array(ltrim($sort, '-'), ['createdAt', 'updatedAt', 'title', 'slug'], true)) throw new \InvalidArgumentException('Unsupported sort field.');
        usort($items, static fn (array $a, array $b): int => (($a[ltrim($sort, '-')] ?? '') <=> ($b[ltrim($sort, '-')] ?? '')) * (str_starts_with($sort, '-') ? -1 : 1));
        $page = max(1, (int) ($query['page'] ?? 1)); $limit = min(100, max(1, (int) ($query['limit'] ?? 20))); $result = ['items' => array_values(array_slice($items, ($page - 1) * $limit, $limit)), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => count($items)]];
        $this->cache->set($key, $result, 60); return $result;
    }
    /** @return array<string,mixed> */ public function find(string $type, string $id): array { return $this->required($type, $id); }
    /** @return list<array<string,mixed>> */ public function audit(): array { return $this->observability->auditEntries(); }
    private function required(string $type, string $id): array { $this->assertType($type); return $this->repository->find($type, $id) ?? throw new \OutOfBoundsException('Resource not found.'); }
    private function assertType(string $type): void { if (!in_array($type, self::RESOURCES, true)) throw new \InvalidArgumentException('Unknown blog resource.'); }
    /** @param array<string,mixed> $input @return array<string,mixed> */ private function normalize(string $type, array $input): array { unset($input['id'], $input['authorId'], $input['status']); foreach (['title', 'body', 'name', 'alt'] as $field) if (isset($input[$field])) $input[$field] = trim(strip_tags((string) $input[$field])); return $input; }
    private function slug(string $title): string { $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)), '-'); return $slug === '' ? 'post' : $slug; }
    /** @param array<string,mixed> $snapshot */ private function revision(array $snapshot, string $actorId, string $reason): void { $this->repository->addRevision($snapshot['id'], ['version' => count($this->repository->revisions($snapshot['id'])) + 1, 'actorId' => $actorId, 'reason' => $reason, 'at' => gmdate(DATE_ATOM), 'snapshot' => $snapshot]); }
    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */ private function mutated(string $action, string $type, string $id, string $actorId, ?array $before, ?array $after): void { $this->cache->clear(); $this->observability->count('blog_' . $action . '_total'); $event = ['name' => 'blog.' . $action, 'action' => $action, 'resource' => $type, 'id' => $id, 'actorId' => $actorId, 'at' => gmdate(DATE_ATOM), 'before' => $before, 'after' => $after]; $this->observability->audit($event); $this->events->publish($event); }
}
