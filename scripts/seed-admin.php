<?php

use Onekana\Api\Database\AdminSeeder;
use Onekana\Api\Database\Connection;
use Onekana\Api\Support\Env;

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

Env::load($basePath);
AdminSeeder::run(Connection::pdo());

echo "Admin account is ready.\n";
