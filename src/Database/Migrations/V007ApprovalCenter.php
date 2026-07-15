<?php

namespace Onekana\Api\Database\Migrations;

use PDO;

final class V007ApprovalCenter
{
    public static function up(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $timestamp = $mysql ? 'TIMESTAMP NULL' : 'TEXT NULL';
        $json = $mysql ? 'JSON NULL' : 'TEXT NULL';

        $pdo->exec("CREATE TABLE IF NOT EXISTS approval_subjects (
            id {$id}, tenant_id {$fk} NOT NULL, source_system VARCHAR(32) NOT NULL,
            resource_type VARCHAR(32) NOT NULL, external_id VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) NULL,
            company_external_id VARCHAR(100) NULL, company_name VARCHAR(255) NULL,
            snapshot {$json}, source_created_at {$timestamp}, first_seen_at {$timestamp}, last_seen_at {$timestamp},
            UNIQUE (tenant_id, source_system, resource_type, external_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS approval_cases (
            id {$id}, tenant_id {$fk} NOT NULL, subject_id {$fk} NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending', priority VARCHAR(16) NOT NULL DEFAULT 'normal',
            assigned_to {$fk} NULL, due_at {$timestamp}, decision_reason TEXT NULL,
            sync_status VARCHAR(24) NOT NULL DEFAULT 'local_only', version INTEGER NOT NULL DEFAULT 1,
            created_by {$fk} NULL, decided_by {$fk} NULL, decided_at {$timestamp},
            created_at {$timestamp}, updated_at {$timestamp},
            UNIQUE (tenant_id, subject_id), FOREIGN KEY (tenant_id) REFERENCES tenants(id),
            FOREIGN KEY (subject_id) REFERENCES approval_subjects(id), FOREIGN KEY (assigned_to) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (decided_by) REFERENCES users(id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS approval_comments (
            id {$id}, tenant_id {$fk} NOT NULL, case_id {$fk} NOT NULL, user_id {$fk} NOT NULL,
            body TEXT NOT NULL, created_at {$timestamp}, FOREIGN KEY (tenant_id) REFERENCES tenants(id),
            FOREIGN KEY (case_id) REFERENCES approval_cases(id), FOREIGN KEY (user_id) REFERENCES users(id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS approval_events (
            id {$id}, tenant_id {$fk} NOT NULL, case_id {$fk} NOT NULL, user_id {$fk} NULL,
            event_type VARCHAR(40) NOT NULL, previous_values {$json}, new_values {$json}, reason TEXT NULL,
            created_at {$timestamp}, FOREIGN KEY (tenant_id) REFERENCES tenants(id),
            FOREIGN KEY (case_id) REFERENCES approval_cases(id), FOREIGN KEY (user_id) REFERENCES users(id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS approval_settings (
            id {$id}, tenant_id {$fk} NOT NULL, request_due_hours INTEGER NOT NULL DEFAULT 24,
            campaign_due_hours INTEGER NOT NULL DEFAULT 24, user_due_hours INTEGER NOT NULL DEFAULT 48,
            document_due_hours INTEGER NOT NULL DEFAULT 48, import_since_days INTEGER NOT NULL DEFAULT 30,
            last_import_at {$timestamp}, import_unavailable {$json}, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        )");

        if ($mysql) {
            $pdo->exec('CREATE INDEX approval_case_queue_index ON approval_cases (tenant_id, status, priority, due_at)');
            $pdo->exec('CREATE INDEX approval_case_assignee_index ON approval_cases (tenant_id, assigned_to, status)');
            $pdo->exec('CREATE INDEX approval_event_case_index ON approval_events (tenant_id, case_id, id)');
            return;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS approval_case_queue_index ON approval_cases (tenant_id, status, priority, due_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS approval_case_assignee_index ON approval_cases (tenant_id, assigned_to, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS approval_event_case_index ON approval_events (tenant_id, case_id, id)');
    }
}
