<?php

namespace Onekana\Api\Database;

use Onekana\Api\Support\Clock;
use Onekana\Api\Support\Env;
use PDO;
use RuntimeException;

final class AdminSeeder
{
    private const MODULES = [
        'dashboard',
        'sales',
        'inventory',
        'operations',
        'team',
        'finance',
        'administration',
        'settings',
    ];

    private const PERMISSIONS = [
        '*',
        'dashboard.view',
        'dashboard.manage',
        'sales.view',
        'sales.manage',
        'inventory.view',
        'inventory.manage',
        'operations.view',
        'operations.manage',
        'team.view',
        'team.manage',
        'finance.view',
        'finance.manage',
        'administration.view',
        'administration.manage',
        'settings.manage',
    ];

    public static function run(PDO $pdo): void
    {
        Schema::migrate($pdo);

        $now = Clock::now();
        $tenantId = self::upsert($pdo, 'tenants', 'slug', 'onekana', [
            'name' => 'ONEKANA',
            'settings' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $moduleIds = [];
        foreach (self::MODULES as $key) {
            $moduleIds[] = self::upsert($pdo, 'modules', 'key', $key, [
                'name' => ucfirst($key),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($moduleIds as $moduleId) {
            self::attach($pdo, 'module_tenant', ['module_id' => $moduleId, 'tenant_id' => $tenantId]);
        }

        $permissionIds = [];
        foreach (self::PERMISSIONS as $key) {
            $permissionIds[] = self::upsert($pdo, 'permissions', 'key', $key, [
                'name' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = self::upsert($pdo, 'roles', 'key', 'admin', [
            'name' => 'Administrateur',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($permissionIds as $permissionId) {
            self::attach($pdo, 'permission_role', ['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        $email = trim((string) Env::get('ADMIN_EMAIL', ''));
        $password = (string) Env::get('ADMIN_PASSWORD', '');
        $name = trim((string) Env::get('ADMIN_NAME', 'Admin Onekana'));

        if ($email === '') {
            throw new RuntimeException('ADMIN_EMAIL must be set before seeding the admin account.');
        }

        $existing = self::findBy($pdo, 'users', 'email', $email);
        if (! $existing && $password === '') {
            throw new RuntimeException('ADMIN_PASSWORD must be set when creating the initial admin account.');
        }

        $attributes = [
            'tenant_id' => $tenantId,
            'name' => $name !== '' ? $name : 'Admin Onekana',
            'updated_at' => $now,
        ];

        if ($password !== '') {
            $attributes['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        if ($existing) {
            self::update($pdo, 'users', (int) $existing['id'], $attributes);
            $userId = (int) $existing['id'];
        } else {
            $attributes['email'] = $email;
            $attributes['created_at'] = $now;
            $userId = self::insert($pdo, 'users', $attributes);
        }

        self::attach($pdo, 'role_user', ['role_id' => $roleId, 'user_id' => $userId]);
    }

    private static function upsert(PDO $pdo, string $table, string $column, string $value, array $attributes): int
    {
        $row = self::findBy($pdo, $table, $column, $value);
        if ($row) {
            self::update($pdo, $table, (int) $row['id'], $attributes);

            return (int) $row['id'];
        }

        $attributes[$column] = $value;

        return self::insert($pdo, $table, $attributes);
    }

    private static function findBy(PDO $pdo, string $table, string $column, string $value): ?array
    {
        $statement = $pdo->prepare("SELECT * FROM {$table} WHERE `{$column}` = :value LIMIT 1");
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private static function insert(PDO $pdo, string $table, array $attributes): int
    {
        $columns = array_keys($attributes);
        $quoted = implode(', ', array_map(fn (string $column) => "`{$column}`", $columns));
        $bindings = implode(', ', array_map(fn (string $column) => ":{$column}", $columns));

        $statement = $pdo->prepare("INSERT INTO {$table} ({$quoted}) VALUES ({$bindings})");
        $statement->execute($attributes);

        return (int) $pdo->lastInsertId();
    }

    private static function update(PDO $pdo, string $table, int $id, array $attributes): void
    {
        $sets = implode(', ', array_map(fn (string $column) => "`{$column}` = :{$column}", array_keys($attributes)));
        $statement = $pdo->prepare("UPDATE {$table} SET {$sets} WHERE id = :id");
        $statement->execute([...$attributes, 'id' => $id]);
    }

    private static function attach(PDO $pdo, string $table, array $attributes): void
    {
        $columns = array_keys($attributes);
        $where = implode(' AND ', array_map(fn (string $column) => "`{$column}` = :{$column}", $columns));
        $exists = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
        $exists->execute($attributes);

        if ($exists->fetchColumn()) {
            return;
        }

        self::insertPivot($pdo, $table, $attributes);
    }

    private static function insertPivot(PDO $pdo, string $table, array $attributes): void
    {
        $columns = array_keys($attributes);
        $quoted = implode(', ', array_map(fn (string $column) => "`{$column}`", $columns));
        $bindings = implode(', ', array_map(fn (string $column) => ":{$column}", $columns));

        $statement = $pdo->prepare("INSERT INTO {$table} ({$quoted}) VALUES ({$bindings})");
        $statement->execute($attributes);
    }
}
