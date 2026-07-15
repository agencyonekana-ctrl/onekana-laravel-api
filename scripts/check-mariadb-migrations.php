<?php

use Onekana\Api\Database\Connection;
use Onekana\Api\Database\Schema;

$basePath = dirname(__DIR__);
require $basePath.'/vendor/autoload.php';

$database = getenv('MARIADB_TEST_DATABASE') ?: 'onekana_ci_check';
if (! preg_match('/^onekana_ci_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Refusing unsafe test database name.\n");
    exit(2);
}
$host = getenv('MARIADB_TEST_HOST') ?: '127.0.0.1';
$port = getenv('MARIADB_TEST_PORT') ?: '3306';
$user = getenv('MARIADB_TEST_USERNAME') ?: 'root';
$password = getenv('MARIADB_TEST_PASSWORD') ?: '';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

try {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('DB_CONNECTION=mysql'); putenv('DB_HOST='.$host); putenv('DB_PORT='.$port);
    putenv('DB_DATABASE='.$database); putenv('DB_USERNAME='.$user); putenv('DB_PASSWORD='.$password);
    Connection::reset();
    $pdo = Connection::pdo();
    Schema::migrate($pdo);
    Schema::migrate($pdo);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM migration_versions WHERE version = '006_relational_finance'")->fetchColumn();
    if ($count !== 1) throw new RuntimeException('Finance migration was not applied exactly once.');
    echo "MariaDB migration check passed.\n";
} finally {
    Connection::reset();
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
