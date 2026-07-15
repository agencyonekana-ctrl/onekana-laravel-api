<?php

namespace Onekana\Api\Database\Migrations;

use PDO;

final class V005IdentityAndRecovery
{
    public static function up(PDO $pdo): void
    {
        if (! self::columnExists($pdo, 'users', 'session_version')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
        }

        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens (token_hash CHAR(64) PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, used_at TIMESTAMP NULL, created_at TIMESTAMP NULL, INDEX password_reset_user_index (user_id), CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id))');
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires_at INTEGER NOT NULL, used_at TEXT NULL, created_at TEXT NULL, FOREIGN KEY (user_id) REFERENCES users(id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS password_reset_user_index ON password_reset_tokens (user_id)');
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
