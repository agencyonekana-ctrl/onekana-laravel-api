<?php

namespace Onekana\Api\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function isActive(array $user): bool
    {
        return ! array_key_exists('is_active', $user) || (bool) $user['is_active'];
    }

    public function payload(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'displayName' => $user['name'],
            'name' => $user['name'],
            'email' => $user['email'],
            'tenant' => $this->tenant((int) ($user['tenant_id'] ?? 0)),
            'roles' => $this->roles((int) $user['id']),
            'permissions' => $this->permissions((int) $user['id']),
            'modules' => $this->modules((int) ($user['tenant_id'] ?? 0)),
        ];
    }

    public function hasPermission(array $user, string $permission): bool
    {
        $permissions = $this->permissions((int) $user['id']);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function hasModule(array $user, string $module): bool
    {
        return in_array($module, $this->modules((int) ($user['tenant_id'] ?? 0)), true);
    }

    private function tenant(int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id, name, slug FROM tenants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tenantId]);
        $tenant = $statement->fetch();

        return $tenant ? [
            'id' => (string) $tenant['id'],
            'name' => $tenant['name'],
            'slug' => $tenant['slug'],
        ] : null;
    }

    private function roles(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT r.`key` FROM roles r INNER JOIN role_user ru ON ru.role_id = r.id WHERE ru.user_id = :id ORDER BY r.id');
        $statement->execute(['id' => $userId]);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function permissions(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT p.`key` FROM permissions p
             INNER JOIN permission_role pr ON pr.permission_id = p.id
             INNER JOIN role_user ru ON ru.role_id = pr.role_id
             WHERE ru.user_id = :id
             ORDER BY p.id'
        );
        $statement->execute(['id' => $userId]);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function modules(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare('SELECT m.`key` FROM modules m INNER JOIN module_tenant mt ON mt.module_id = m.id WHERE mt.tenant_id = :id ORDER BY m.id');
        $statement->execute(['id' => $tenantId]);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
