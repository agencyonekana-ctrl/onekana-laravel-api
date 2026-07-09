<?php

namespace Onekana\Api\Database;

use PDO;

final class Schema
{
    public const RESOURCE_TABLES = [
        'employees',
        'departments',
        'documents',
        'schedules',
        'materials',
        'material_types',
        'reservations',
        'reservation_types',
        'job_titles',
        'employee_statuses',
        'ooh_sites',
        'ooh_supports',
        'ooh_emplacements',
        'ooh_assets',
        'ooh_pricing_rules',
        'ooh_campaigns',
        'ooh_campaign_lines',
        'ooh_tasks',
        'packs',
        'options',
        'contact_messages',
        'campaign_types',
        'campaign_prices',
        'communes',
        'quartiers',
        'points_chauds',
        'transport_routes',
        'route_coordinates',
        'agenda_events',
        'notifications',
        'roadmap',
        'accounting_accounts',
        'accounting_journals',
        'accounting_entries',
        'accounting_trial_balance',
        'wallet_accounts',
        'wallet_transactions',
        'invoices',
        'payments',
    ];

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $json = $driver === 'mysql' ? 'JSON NULL' : 'TEXT NULL';
        $fkId = $driver === 'mysql' ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL';
        $timestamp = $driver === 'mysql' ? 'TIMESTAMP NULL' : 'TEXT NULL';

        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (id {$id}, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL UNIQUE, settings {$json}, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id {$id}, tenant_id {$fkId}, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE, email_verified_at {$timestamp}, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS modules (id {$id}, name VARCHAR(255) NOT NULL, `key` VARCHAR(255) NOT NULL UNIQUE, created_at {$timestamp}, updated_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_user (role_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (role_id, user_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS permission_role (permission_id INTEGER NOT NULL, role_id INTEGER NOT NULL, PRIMARY KEY (permission_id, role_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS module_tenant (module_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, PRIMARY KEY (module_id, tenant_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id {$id}, email VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at {$timestamp})");
        $pdo->exec("CREATE TABLE IF NOT EXISTS revoked_tokens (jti VARCHAR(64) PRIMARY KEY, expires_at INTEGER NOT NULL, created_at {$timestamp})");

        foreach (self::RESOURCE_TABLES as $table) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id {$id}, tenant_id {$fkId}, payload {$json}, created_at {$timestamp}, updated_at {$timestamp})");
        }
    }
}
