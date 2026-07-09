<?php

use Onekana\Api\Database\Connection;
use Onekana\Api\Database\Schema;
use Onekana\Api\Support\Env;

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

Env::load($basePath);
Schema::migrate(Connection::pdo());

echo "Database schema is ready.\n";
