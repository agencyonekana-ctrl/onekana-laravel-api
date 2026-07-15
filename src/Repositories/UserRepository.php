<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Support\Clock;
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

    public function listForTenant(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT id, tenant_id, name, email, is_active, created_at, updated_at FROM users WHERE tenant_id = :tenant_id ORDER BY name, id');
        $statement->execute(['tenant_id' => $tenantId]);

        return array_map(fn (array $user) => $this->payload($user), $statement->fetchAll() ?: []);
    }

    public function allRoles(): array
    {
        $rows = $this->pdo->query('SELECT id, name, `key` FROM roles ORDER BY name, id')->fetchAll() ?: [];
        return array_map(fn (array $role) => [
            'id' => (string) $role['id'],
            'name' => $role['name'],
            'key' => $role['key'],
        ], $rows);
    }

    public function roleExists(int $roleId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM roles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $roleId]);
        return (bool) $statement->fetchColumn();
    }

    public function roleKey(int $roleId): ?string
    {
        $statement = $this->pdo->prepare('SELECT `key` FROM roles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $roleId]);
        $key = $statement->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    public function createInvited(int $tenantId, string $name, string $email, int $roleId): array
    {
        $now = Clock::now();
        $statement = $this->pdo->prepare('INSERT INTO users (tenant_id, name, email, password, is_active, session_version, created_at, updated_at) VALUES (:tenant_id, :name, :email, :password, 1, 1, :created_at, :updated_at)');
        $statement->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower($email),
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->assignRole($userId, $roleId);

        return $this->findById($userId) ?? throw new \RuntimeException('Unable to create user.');
    }

    public function updateAdmin(int $tenantId, int $userId, array $attributes): ?array
    {
        $user = $this->findById($userId);
        if (! $user || (int) ($user['tenant_id'] ?? 0) !== $tenantId) {
            return null;
        }

        $updates = [];
        $bindings = ['id' => $userId, 'tenant_id' => $tenantId, 'updated_at' => Clock::now()];
        foreach (['name', 'is_active'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $updates[] = "{$field} = :{$field}";
                $bindings[$field] = $attributes[$field];
            }
        }
        $updates[] = 'updated_at = :updated_at';
        $statement = $this->pdo->prepare('UPDATE users SET '.implode(', ', $updates).' WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute($bindings);

        if (isset($attributes['role_id'])) {
            $this->assignRole($userId, (int) $attributes['role_id']);
        }

        return $this->findById($userId);
    }

    public function updatePassword(int $userId, string $password): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password = :password, session_version = session_version + 1, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'updated_at' => Clock::now(),
            'id' => $userId,
        ]);
    }

    public function countActiveAdmins(int $tenantId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN role_user ru ON ru.user_id = u.id INNER JOIN roles r ON r.id = ru.role_id WHERE u.tenant_id = :tenant_id AND u.is_active = 1 AND r.`key` = 'admin'");
        $statement->execute(['tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn();
    }

    public function payload(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'displayName' => $user['name'],
            'name' => $user['name'],
            'email' => $user['email'],
            'isActive' => $this->isActive($user),
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

    private function assignRole(int $userId, int $roleId): void
    {
        $this->pdo->prepare('DELETE FROM role_user WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $this->pdo->prepare('INSERT INTO role_user (role_id, user_id) VALUES (:role_id, :user_id)')->execute(['role_id' => $roleId, 'user_id' => $userId]);
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
