<?php

namespace Onekana\Api\Database\Migrations;

use PDO;

final class V006RelationalFinance
{
    private const LEGACY_TABLES = [
        'accounting_accounts', 'accounting_journals', 'accounting_entries',
        'accounting_trial_balance', 'wallet_accounts', 'wallet_transactions',
        'invoices', 'payments',
    ];

    public static function up(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (self::LEGACY_TABLES as $table) {
            if (self::tableExists($pdo, $table, $mysql) && self::columnExists($pdo, $table, 'payload', $mysql)) {
                $legacy = 'legacy_'.$table;
                if (! self::tableExists($pdo, $legacy, $mysql)) {
                    $pdo->exec($mysql ? "RENAME TABLE {$table} TO {$legacy}" : "ALTER TABLE {$table} RENAME TO {$legacy}");
                }
            }
        }

        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tenant = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $fk = $mysql ? 'BIGINT UNSIGNED NULL' : 'INTEGER NULL';
        $requiredFk = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $timestamp = $mysql ? 'TIMESTAMP NULL' : 'TEXT NULL';
        $date = $mysql ? 'DATE NOT NULL' : 'TEXT NOT NULL';
        $decimal = $mysql ? 'DECIMAL(18,2)' : 'NUMERIC';
        $bool = $mysql ? 'TINYINT(1)' : 'INTEGER';

        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_accounts (id {$id}, tenant_id {$tenant}, code VARCHAR(32) NOT NULL, label VARCHAR(255) NOT NULL, class SMALLINT NOT NULL, type VARCHAR(32) NOT NULL, is_active {$bool} NOT NULL DEFAULT 1, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, code), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_journals (id {$id}, tenant_id {$tenant}, code VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(32) NOT NULL, is_active {$bool} NOT NULL DEFAULT 1, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, code), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_periods (id {$id}, tenant_id {$tenant}, label VARCHAR(255) NOT NULL, starts_on {$date}, ends_on {$date}, status VARCHAR(16) NOT NULL DEFAULT 'open', closed_at {$timestamp}, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, starts_on, ends_on), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_entries (id {$id}, tenant_id {$tenant}, journal_id {$requiredFk}, period_id {$requiredFk}, reference VARCHAR(100) NOT NULL, entry_date {$date}, label VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'posted', posted_at {$timestamp}, reversed_entry_id {$fk}, created_by {$fk}, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, reference), FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (journal_id) REFERENCES accounting_journals(id), FOREIGN KEY (period_id) REFERENCES accounting_periods(id), FOREIGN KEY (reversed_entry_id) REFERENCES accounting_entries(id), FOREIGN KEY (created_by) REFERENCES users(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_entry_lines (id {$id}, entry_id {$requiredFk}, account_id {$requiredFk}, label VARCHAR(255) NULL, debit {$decimal} NOT NULL DEFAULT 0, credit {$decimal} NOT NULL DEFAULT 0, created_at {$timestamp}, FOREIGN KEY (entry_id) REFERENCES accounting_entries(id), FOREIGN KEY (account_id) REFERENCES accounting_accounts(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS finance_settings (id {$id}, tenant_id {$tenant}, sales_account_id {$fk}, receivable_account_id {$fk}, tax_account_id {$fk}, bank_account_id {$fk}, wallet_account_id {$fk}, expense_account_id {$fk}, configured_at {$timestamp}, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (id {$id}, tenant_id {$tenant}, number VARCHAR(64) NOT NULL, client_name VARCHAR(255) NOT NULL, client_reference VARCHAR(100) NULL, campaign_reference VARCHAR(100) NULL, issue_date {$date}, due_date ".($mysql ? 'DATE NULL' : 'TEXT NULL').", currency CHAR(3) NOT NULL DEFAULT 'USD', subtotal {$decimal} NOT NULL, tax {$decimal} NOT NULL DEFAULT 0, total {$decimal} NOT NULL, balance {$decimal} NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', posted_entry_id {$fk}, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, number), FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (posted_entry_id) REFERENCES accounting_entries(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_lines (id {$id}, invoice_id {$requiredFk}, description VARCHAR(255) NOT NULL, quantity {$decimal} NOT NULL DEFAULT 1, unit_price {$decimal} NOT NULL, tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0, line_total {$decimal} NOT NULL, created_at {$timestamp}, FOREIGN KEY (invoice_id) REFERENCES invoices(id))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_accounts (id {$id}, tenant_id {$tenant}, name VARCHAR(255) NOT NULL, code VARCHAR(32) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'USD', status VARCHAR(20) NOT NULL DEFAULT 'active', created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, code), FOREIGN KEY (tenant_id) REFERENCES tenants(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (id {$id}, tenant_id {$tenant}, wallet_account_id {$requiredFk}, type VARCHAR(16) NOT NULL, amount {$decimal} NOT NULL, source VARCHAR(100) NULL, reference VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'posted', occurred_at {$timestamp}, posted_entry_id {$fk}, idempotency_key VARCHAR(100) NOT NULL, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, idempotency_key), FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (wallet_account_id) REFERENCES wallet_accounts(id), FOREIGN KEY (posted_entry_id) REFERENCES accounting_entries(id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (id {$id}, tenant_id {$tenant}, invoice_id {$requiredFk}, wallet_account_id {$fk}, amount {$decimal} NOT NULL, method VARCHAR(32) NOT NULL, reference VARCHAR(100) NOT NULL, paid_at {$timestamp}, status VARCHAR(20) NOT NULL DEFAULT 'posted', posted_entry_id {$fk}, idempotency_key VARCHAR(100) NOT NULL, created_at {$timestamp}, updated_at {$timestamp}, UNIQUE (tenant_id, idempotency_key), FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (invoice_id) REFERENCES invoices(id), FOREIGN KEY (wallet_account_id) REFERENCES wallet_accounts(id), FOREIGN KEY (posted_entry_id) REFERENCES accounting_entries(id))");

        foreach (['accounting_accounts', 'accounting_journals', 'accounting_periods', 'accounting_entries', 'invoices', 'wallet_accounts', 'wallet_transactions', 'payments'] as $table) {
            $index = substr('idx_'.$table.'_tenant', 0, 60);
            if ($mysql) {
                if (! self::indexExists($pdo, $table, $index)) $pdo->exec("CREATE INDEX {$index} ON {$table} (tenant_id, id)");
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} (tenant_id, id)");
            }
        }
        if ($mysql) {
            if (! self::indexExists($pdo, 'accounting_entry_lines', 'idx_entry_lines_entry')) $pdo->exec('CREATE INDEX idx_entry_lines_entry ON accounting_entry_lines (entry_id, account_id)');
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entry_lines_entry ON accounting_entry_lines (entry_id, account_id)');
        }
    }

    private static function tableExists(PDO $pdo, string $table, bool $mysql): bool
    {
        if ($mysql) {
            $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        } else {
            $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        }
        $statement->execute(['table' => $table]);
        return (bool) $statement->fetchColumn();
    }

    private static function columnExists(PDO $pdo, string $table, string $column, bool $mysql): bool
    {
        if ($mysql) {
            $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
            $statement->execute(['table' => $table, 'column' => $column]);
            return (bool) $statement->fetchColumn();
        }
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $row) {
            if (($row['name'] ?? null) === $column) return true;
        }
        return false;
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name');
        $statement->execute(['table' => $table, 'index_name' => $index]);
        return (bool) $statement->fetchColumn();
    }
}
