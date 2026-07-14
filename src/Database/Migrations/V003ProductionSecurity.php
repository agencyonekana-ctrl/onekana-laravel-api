<?php

namespace Onekana\Api\Database\Migrations;

use PDO;

final class V003ProductionSecurity
{
    public static function up(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (! self::columnExists($pdo, 'users', 'is_active')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
        }

        if ($mysql) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS refresh_tokens (token_hash CHAR(64) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, revoked_at TIMESTAMP NULL, created_at TIMESTAMP NULL, INDEX refresh_user_index (user_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limit_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, key_name VARCHAR(100) NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at TIMESTAMP NULL, INDEX rate_limit_index (key_name, ip_address, created_at))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id VARCHAR(64) NOT NULL, tenant_id BIGINT UNSIGNED NULL, user_id BIGINT UNSIGNED NULL, method VARCHAR(10) NOT NULL, path VARCHAR(255) NOT NULL, status SMALLINT NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at TIMESTAMP NULL, INDEX audit_created_index (created_at), INDEX audit_user_index (tenant_id, user_id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS private_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, path VARCHAR(500) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL, UNIQUE KEY private_file_path_unique (path), INDEX private_file_tenant_index (tenant_id, id))');
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS refresh_tokens (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires_at INTEGER NOT NULL, revoked_at TEXT NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS refresh_user_index ON refresh_tokens (user_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limit_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name TEXT NOT NULL, ip_address TEXT NOT NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS rate_limit_index ON rate_limit_attempts (key_name, ip_address, created_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT NOT NULL, tenant_id INTEGER NULL, user_id INTEGER NULL, method TEXT NOT NULL, path TEXT NOT NULL, status INTEGER NOT NULL, ip_address TEXT NOT NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS audit_created_index ON audit_logs (created_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS private_files (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, path TEXT NOT NULL UNIQUE, original_name TEXT NOT NULL, mime_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_by INTEGER NULL, created_at TEXT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS private_file_tenant_index ON private_files (tenant_id, id)');
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
            $statement->execute(['table' => $table, 'column' => $column]);
            return (bool) $statement->fetchColumn();
        }

        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }
        return false;
    }
}
