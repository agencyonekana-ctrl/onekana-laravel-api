<?php

namespace Onekana\Api\Database;

use Onekana\Api\Database\Migrations\V001InitialSchema;
use Onekana\Api\Database\Migrations\V002MediaAndGeography;
use Onekana\Api\Database\Migrations\V003ProductionSecurity;
use Onekana\Api\Database\Migrations\V004RelationalIntegrity;
use Onekana\Api\Database\Migrations\V005IdentityAndRecovery;
use Onekana\Api\Database\Migrations\V006RelationalFinance;
use Onekana\Api\Support\Clock;
use PDO;

final class MigrationRunner
{
    private const MIGRATIONS = [
        '001_initial_schema' => V001InitialSchema::class,
        '002_media_and_geography' => V002MediaAndGeography::class,
        '003_production_security' => V003ProductionSecurity::class,
        '004_relational_integrity' => V004RelationalIntegrity::class,
        '005_identity_and_recovery' => V005IdentityAndRecovery::class,
        '006_relational_finance' => V006RelationalFinance::class,
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migration_versions (version VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $applied = $this->pdo->query('SELECT version FROM migration_versions')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach (self::MIGRATIONS as $version => $migration) {
            if (in_array($version, $applied, true)) {
                continue;
            }

            $migration::up($this->pdo);
            $statement = $this->pdo->prepare('INSERT INTO migration_versions (version, applied_at) VALUES (:version, :applied_at)');
            $statement->execute(['version' => $version, 'applied_at' => Clock::now()]);
        }
    }
}
