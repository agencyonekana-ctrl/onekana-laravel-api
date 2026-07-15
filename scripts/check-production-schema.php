<?php

use Onekana\Api\Database\Connection;
use Onekana\Api\Database\Schema;
use Onekana\Api\Support\Env;

$basePath = dirname(__DIR__);
require $basePath.'/vendor/autoload.php';
Env::load($basePath);
$pdo = Connection::pdo();
Schema::migrate($pdo);
Schema::migrate($pdo);

$required = ['accounting_accounts', 'accounting_entries', 'accounting_entry_lines', 'invoices', 'invoice_lines', 'payments', 'wallet_accounts', 'wallet_transactions'];
foreach ($required as $table) {
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $statement->execute(['table' => $table]);
    if (! $statement->fetchColumn()) {
        fwrite(STDERR, "Missing table: {$table}\n");
        exit(1);
    }
}
echo "MariaDB migrations are idempotent and the production schema is present.\n";
