<?php

namespace Onekana\Api\Database\Migrations;

use Onekana\Api\Database\Schema;
use PDO;

final class V004RelationalIntegrity
{
    public static function up(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (Schema::RESOURCE_TABLES as $table) {
            $index = substr('idx_'.$table.'_tenant', 0, 60);
            if ($mysql) {
                if (! self::indexExists($pdo, $table, $index)) {
                    $pdo->exec("CREATE INDEX {$index} ON {$table} (tenant_id, id)");
                }
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} (tenant_id, id)");
            }
        }

        if (! $mysql) {
            return;
        }

        $pdo->exec('ALTER TABLE role_user MODIFY role_id BIGINT UNSIGNED NOT NULL, MODIFY user_id BIGINT UNSIGNED NOT NULL');
        $pdo->exec('ALTER TABLE permission_role MODIFY permission_id BIGINT UNSIGNED NOT NULL, MODIFY role_id BIGINT UNSIGNED NOT NULL');
        $pdo->exec('ALTER TABLE module_tenant MODIFY module_id BIGINT UNSIGNED NOT NULL, MODIFY tenant_id BIGINT UNSIGNED NOT NULL');

        self::foreignKey($pdo, 'users', 'users_tenant_fk', 'tenant_id', 'tenants', 'id');
        self::foreignKey($pdo, 'role_user', 'role_user_role_fk', 'role_id', 'roles', 'id');
        self::foreignKey($pdo, 'role_user', 'role_user_user_fk', 'user_id', 'users', 'id');
        self::foreignKey($pdo, 'permission_role', 'permission_role_permission_fk', 'permission_id', 'permissions', 'id');
        self::foreignKey($pdo, 'permission_role', 'permission_role_role_fk', 'role_id', 'roles', 'id');
        self::foreignKey($pdo, 'module_tenant', 'module_tenant_module_fk', 'module_id', 'modules', 'id');
        self::foreignKey($pdo, 'module_tenant', 'module_tenant_tenant_fk', 'tenant_id', 'tenants', 'id');
        self::foreignKey($pdo, 'refresh_tokens', 'refresh_tokens_user_fk', 'user_id', 'users', 'id');
        self::foreignKey($pdo, 'private_files', 'private_files_tenant_fk', 'tenant_id', 'tenants', 'id');
    }

    private static function foreignKey(PDO $pdo, string $table, string $name, string $column, string $referenceTable, string $referenceColumn): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = :table AND constraint_name = :name LIMIT 1');
        $statement->execute(['table' => $table, 'name' => $name]);
        if ($statement->fetchColumn()) {
            return;
        }

        $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES {$referenceTable}({$referenceColumn})");
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name LIMIT 1');
        $statement->execute(['table' => $table, 'index_name' => $index]);

        return (bool) $statement->fetchColumn();
    }
}
