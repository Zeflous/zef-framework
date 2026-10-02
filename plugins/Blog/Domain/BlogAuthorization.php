<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Domain;

/**
 * Pure RBAC policy. Handlers call this policy before every application action;
 * authorization is deliberately never delegated to a UI client.
 */
final class BlogAuthorization
{
    public function allows(Role $role, string $action, ?array $resource, string $actorId): bool
    {
        if ($role === Role::Admin) {
            return true;
        }
        if ($action === 'read') {
            return true;
        }
        if ($role === Role::Viewer) {
            return false;
        }
        if ($role === Role::Editor) {
            return $action !== 'moderate';
        }
        if ($role !== Role::Author) {
            return false;
        }

        return in_array($action, ['create', 'update', 'delete'], true)
            && ($resource === null || ($resource['authorId'] ?? null) === $actorId);
    }
}
