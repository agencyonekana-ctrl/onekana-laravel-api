<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\Approvals\ApprovalImporter;
use Onekana\Api\Database\Connection;
use Onekana\Api\Database\Schema;
use Onekana\Api\Repositories\ApprovalRepository;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Support\Env;

$basePath = dirname(__DIR__);
Env::load($basePath);
if (! Env::bool('ENABLE_APPROVAL_CENTER', false)) {
    fwrite(STDERR, "Le Centre de validation est désactivé.\n");
    exit(1);
}

$pdo = Connection::pdo();
Schema::migrate($pdo);
$repository = new ApprovalRepository($pdo);
$importer = new ApprovalImporter($repository, new ResourceRepository($pdo), AgencyApiClient::fromEnv());
$tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY id')->fetchAll() ?: [];
$failed = false;

foreach ($tenants as $tenant) {
    try {
        $result = $importer->import((int) $tenant['id']);
        printf("%s: %d indexés, %d créés, %d existants, indisponibles: %s\n", $tenant['name'], $result['indexed'], $result['created'], $result['existing'], implode(', ', $result['unavailable']) ?: 'aucun');
    } catch (Throwable $exception) {
        $failed = true;
        fwrite(STDERR, $tenant['name'].": synchronisation échouée.\n");
    }
}

exit($failed ? 1 : 0);
