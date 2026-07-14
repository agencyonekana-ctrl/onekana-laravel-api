<?php

namespace Onekana\Api\Database\Migrations;

use Onekana\Api\Database\Schema;
use PDO;

final class V001InitialSchema
{
    public static function up(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $json = $mysql ? 'JSON NULL' : 'TEXT NULL';
        $fkId = $mysql ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL';
        $pivotId = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $timestamp = $mysql ? 'TIMESTAMP NULL' : 'TEXT NULL';

        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (id {$id}, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, settings {$json}, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id {$id}, tenant_id {$fkId}, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE, email_verified_at {$timestamp}, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at {$timestamp}, updated_at {$timestamp}, FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS modules (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_user (role_id {$pivotId}, user_id {$pivotId}, PRIMARY KEY (role_id, user_id), FOREIGN KEY (role_id) REFERENCES roles(id), FOREIGN KEY (user_id) REFERENCES users(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permission_role (permission_id {$pivotId}, role_id {$pivotId}, PRIMARY KEY (permission_id, role_id), FOREIGN KEY (permission_id) REFERENCES permissions(id), FOREIGN KEY (role_id) REFERENCES roles(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_tenant (module_id {$pivotId}, tenant_id {$pivotId}, PRIMARY KEY (module_id, tenant_id), FOREIGN KEY (module_id) REFERENCES modules(id), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id {$id}, email VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS revoked_tokens (jti VARCHAR(64) PRIMARY KEY, expires_at INTEGER NOT NULL, created_at {$timestamp})");

        foreach (Schema::RESOURCE_TABLES as $table) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id {$id}, tenant_id {$fkId}, payload {$json}, created_at {$timestamp}, updated_at {$timestamp})");
        }
    }
}
