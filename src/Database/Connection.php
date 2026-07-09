<?php

namespace Onekana\Api\Database;

use Onekana\Api\Support\Env;
use PDO;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }

        $connection = Env::get('DB_CONNECTION', 'mysql');

        if ($connection === 'sqlite') {
            $database = Env::get('DB_DATABASE', ':memory:') ?? ':memory:';
            self::$pdo = new PDO('sqlite:'.$database);
        } else {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $database = Env::get('DB_DATABASE', 'onekana');
            $charset = Env::get('DB_CHARSET', 'utf8mb4');
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            self::$pdo = new PDO($dsn, Env::get('DB_USERNAME', 'root'), Env::get('DB_PASSWORD', ''));
        }

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$pdo;
    }

    public static function reset(?PDO $pdo = null): void
    {
        self::$pdo = $pdo;
    }
}
