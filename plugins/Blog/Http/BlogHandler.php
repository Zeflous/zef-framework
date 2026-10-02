<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Plugin\Blog\Application\BlogService;
use Zef\Plugin\Blog\Domain\BlogAuthorization;
use Zef\Plugin\Blog\Domain\Role;

/** HTTP adapter: validates transport data then delegates to the application layer. */
final class BlogHandler implements RequestHandlerInterface
{
    public function __construct(private readonly BlogService $service, private readonly BlogAuthorization $authorization, private readonly RateLimiterInterface $limiter) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $type = (string) $request->getAttribute('resource'); $id = $request->getAttribute('id'); $action = $this->action($request);
            [$actor, $role] = $this->actor($request); $method = $request->getMethod();
            if ($type === 'comments' && $method === 'POST') { $decision = $this->limiter->check('blog:comment:' . $actor, 10, 60); if (!$decision->allowed) return $this->json(429, ['error' => 'Comment rate limit exceeded.']); }
            $required = $method === 'GET' ? 'read' : ($action === 'moderate' ? 'moderate' : ($method === 'POST' ? 'create' : ($method === 'DELETE' ? 'delete' : 'update')));
            $existing = is_string($id) ? $this->tryFind($type, $id) : null;
            if (!$this->authorization->allows($role, $required, $existing, $actor)) return $this->json(403, ['error' => 'Forbidden.']);
            $this->service->publishScheduled();
            if ($method === 'GET' && $action === 'revisions' && is_string($id)) return $this->json(200, ['items' => $this->service->revisions($id)]);
            if ($method === 'POST' && $action === 'restore' && is_string($id)) return $this->json(200, $this->service->restore($id, (int) $this->body($request)['version'], $actor));
            if ($method === 'POST' && $action === 'transition' && is_string($id)) return $this->json(200, $this->service->transition($id, (string) $this->body($request)['status'], $actor));
            if ($method === 'POST' && $action === 'moderate' && is_string($id)) return $this->json(200, $this->service->moderate($id, (string) $this->body($request)['status'], $actor));
            if ($method === 'GET') return is_string($id) ? $this->json(200, $this->service->find($type, $id)) : $this->json(200, $this->service->list($type, $request->getQueryParams()));
            if ($method === 'POST') return $this->json(201, $this->service->create($type, $this->body($request), $actor));
            if ($method === 'PATCH' && is_string($id)) return $this->json(200, $this->service->update($type, $id, $this->body($request), $actor));
            if ($method === 'DELETE' && is_string($id)) { $this->service->delete($type, $id, $actor); return new Response(204); }
            return $this->json(405, ['error' => 'Method not allowed.']);
        } catch (\OutOfBoundsException) { return $this->json(404, ['error' => 'Not found.']); } catch (\InvalidArgumentException|\DomainException $e) { return $this->json(422, ['error' => $e->getMessage()]); }
    }
    /** @return array{string,Role} */ private function actor(ServerRequestInterface $request): array { $identity = $request->getAttribute('blog.actor', []); $id = is_array($identity) ? (string) ($identity['id'] ?? 'anonymous') : 'anonymous'; $name = is_array($identity) ? (string) ($identity['role'] ?? 'viewer') : 'viewer'; return [$id, Role::tryFrom($name) ?? Role::Viewer]; }
    /** @return array<string,mixed> */ private function body(ServerRequestInterface $request): array { $body = $request->getParsedBody(); if (!is_array($body)) throw new \InvalidArgumentException('JSON object body is required.'); return $body; }
    private function tryFind(string $type, string $id): ?array { try { return $this->service->find($type, $id); } catch (\OutOfBoundsException) { return null; } }
    private function action(ServerRequestInterface $request): ?string { $path = $request->getUri()->getPath(); foreach (['revisions', 'restore', 'transition', 'moderate'] as $action) if (str_ends_with($path, '/' . $action)) return $action; return null; }
    /** @param array<string,mixed> $payload */ private function json(int $status, array $payload): Response { return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
}
