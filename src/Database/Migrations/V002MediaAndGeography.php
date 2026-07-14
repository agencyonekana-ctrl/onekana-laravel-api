<?php

namespace Onekana\Api\Database\Migrations;

use PDO;

final class V002MediaAndGeography
{
    public static function up(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS geographic_reviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, entity_type VARCHAR(32) NOT NULL, external_id VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'to_review', note TEXT NULL, reviewed_by BIGINT UNSIGNED NULL, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE KEY geographic_review_unique (tenant_id, entity_type, external_id))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS media (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL, path VARCHAR(500) NOT NULL, mime_type VARCHAR(100) NOT NULL, alt_text VARCHAR(255) NULL, is_cover TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX media_entity_index (tenant_id, entity_type, entity_id))");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS geographic_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, entity_type TEXT NOT NULL, external_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'to_review', note TEXT NULL, reviewed_by INTEGER NULL, reviewed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL, UNIQUE (tenant_id, entity_type, external_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS media (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, path TEXT NOT NULL, mime_type TEXT NOT NULL, alt_text TEXT NULL, is_cover INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS media_entity_index ON media (tenant_id, entity_type, entity_id)');
    }
}
