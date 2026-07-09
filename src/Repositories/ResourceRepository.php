<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Support\Clock;
use Onekana\Api\Support\Json;
use PDO;
use RuntimeException;

final class ResourceRepository
{
    public const TABLES = [
        'employees' => 'employees',
        'departments' => 'departments',
        'documents' => 'documents',
        'schedules' => 'schedules',
        'materials' => 'materials',
        'materialTypes' => 'material_types',
        'reservations' => 'reservations',
        'reservationTypes' => 'reservation_types',
        'jobTitles' => 'job_titles',
        'employeeStatuses' => 'employee_statuses',
        'oohSites' => 'ooh_sites',
        'oohSupports' => 'ooh_supports',
        'oohEmplacements' => 'ooh_emplacements',
        'oohAssets' => 'ooh_assets',
        'oohPricingRules' => 'ooh_pricing_rules',
        'oohCampaigns' => 'ooh_campaigns',
        'oohCampaignLines' => 'ooh_campaign_lines',
        'oohTasks' => 'ooh_tasks',
        'packsCommerciaux' => 'packs',
        'optionsComplementaires' => 'options',
        'contactMessages' => 'contact_messages',
        'campaignTypes' => 'campaign_types',
        'campaignPrices' => 'campaign_prices',
        'communes' => 'communes',
        'quartiers' => 'quartiers',
        'pointsChauds' => 'points_chauds',
        'transportRoutes' => 'transport_routes',
        'routeCoordinates' => 'route_coordinates',
        'agendaEvents' => 'agenda_events',
        'notifications' => 'notifications',
        'roadmap' => 'roadmap',
        'accountingAccounts' => 'accounting_accounts',
        'accountingJournals' => 'accounting_journals',
        'accountingEntries' => 'accounting_entries',
        'trialBalance' => 'accounting_trial_balance',
        'walletAccounts' => 'wallet_accounts',
        'walletTransactions' => 'wallet_transactions',
        'invoices' => 'invoices',
        'payments' => 'payments',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function list(string $resource, ?int $tenantId = null, int $limit = 0): array
    {
        $table = $this->table($resource);
        $params = [];
        $sql = "SELECT * FROM {$table}";

        if ($tenantId) {
            $sql .= ' WHERE tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $sql .= ' ORDER BY id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT '.min(max($limit, 1), 100);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(fn (array $row) => $this->present($row), $statement->fetchAll());
    }

    public function count(string $table, ?int $tenantId = null): int
    {
        $params = [];
        $sql = "SELECT COUNT(*) FROM {$table}";
        if ($tenantId) {
            $sql .= ' WHERE tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function find(string $resource, int $id, ?int $tenantId = null): ?array
    {
        $row = $this->findRow($this->table($resource), $id, $tenantId);

        return $row ? $this->present($row) : null;
    }

    public function create(string $resource, array $payload, ?int $tenantId = null): array
    {
        $table = $this->table($resource);
        $now = Clock::now();
        $statement = $this->pdo->prepare("INSERT INTO {$table} (tenant_id, payload, created_at, updated_at) VALUES (:tenant_id, :payload, :created_at, :updated_at)");
        $statement->execute([
            'tenant_id' => $tenantId,
            'payload' => Json::encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->present($this->findRow($table, (int) $this->pdo->lastInsertId(), $tenantId));
    }

    public function update(string $resource, int $id, array $payload, ?int $tenantId = null): ?array
    {
        $table = $this->table($resource);
        $row = $this->findRow($table, $id, $tenantId);
        if (! $row) {
            return null;
        }

        $merged = array_merge(Json::decode($row['payload'] ?? null), $payload);
        $statement = $this->pdo->prepare("UPDATE {$table} SET payload = :payload, updated_at = :updated_at WHERE id = :id");
        $statement->execute([
            'payload' => Json::encode($merged),
            'updated_at' => Clock::now(),
            'id' => $id,
        ]);

        return $this->present($this->findRow($table, $id, $tenantId));
    }

    public function delete(string $resource, int $id, ?int $tenantId = null): bool
    {
        $table = $this->table($resource);
        if (! $this->findRow($table, $id, $tenantId)) {
            return false;
        }

        $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $id]);

        return true;
    }

    public function markNotificationRead(int $id, ?int $tenantId = null): ?array
    {
        return $this->update('notifications', $id, [
            'readAt' => Clock::iso(),
            'read_at' => Clock::iso(),
        ], $tenantId);
    }

    public function table(string $resource): string
    {
        if (! isset(self::TABLES[$resource])) {
            throw new RuntimeException('Ressource API inconnue.');
        }

        return self::TABLES[$resource];
    }

    private function findRow(string $table, int $id, ?int $tenantId = null): ?array
    {
        $params = ['id' => $id];
        $sql = "SELECT * FROM {$table} WHERE id = :id";
        if ($tenantId) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $statement = $this->pdo->prepare($sql.' LIMIT 1');
        $statement->execute($params);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function present(?array $row): array
    {
        if (! $row) {
            return [];
        }

        return array_merge(Json::decode($row['payload'] ?? null), [
            'id' => (string) $row['id'],
            'tenantId' => $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
