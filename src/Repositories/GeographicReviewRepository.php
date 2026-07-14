<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Support\Clock;
use PDO;

final class GeographicReviewRepository
{
    public const ENTITY_TYPES = ['commune', 'point_chaud', 'trajet'];
    public const STATUSES = ['to_review', 'verified'];

    public function __construct(private readonly PDO $pdo) {}

    public function list(int $tenantId, ?string $entityType = null): array
    {
        $sql = 'SELECT * FROM geographic_reviews WHERE tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];
        if ($entityType !== null) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map([$this, 'present'], $statement->fetchAll());
    }

    public function upsert(int $tenantId, string $entityType, string $externalId, string $status, ?string $note, ?int $userId): array
    {
        $existing = $this->find($tenantId, $entityType, $externalId);
        $now = Clock::now();
        $reviewedAt = $status === 'verified' ? $now : null;

        if ($existing) {
            $statement = $this->pdo->prepare('UPDATE geographic_reviews SET status = :status, note = :note, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                'status' => $status,
                'note' => $note,
                'reviewed_by' => $userId,
                'reviewed_at' => $reviewedAt,
                'updated_at' => $now,
                'id' => $existing['id'],
            ]);
        } else {
            $statement = $this->pdo->prepare('INSERT INTO geographic_reviews (tenant_id, entity_type, external_id, status, note, reviewed_by, reviewed_at, created_at, updated_at) VALUES (:tenant_id, :entity_type, :external_id, :status, :note, :reviewed_by, :reviewed_at, :created_at, :updated_at)');
            $statement->execute([
                'tenant_id' => $tenantId,
                'entity_type' => $entityType,
                'external_id' => $externalId,
                'status' => $status,
                'note' => $note,
                'reviewed_by' => $userId,
                'reviewed_at' => $reviewedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->present($this->find($tenantId, $entityType, $externalId));
    }

    private function find(int $tenantId, string $entityType, string $externalId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM geographic_reviews WHERE tenant_id = :tenant_id AND entity_type = :entity_type AND external_id = :external_id LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'entity_type' => $entityType, 'external_id' => $externalId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'entityType' => $row['entity_type'],
            'externalId' => (string) $row['external_id'],
            'status' => $row['status'],
            'note' => $row['note'],
            'reviewedBy' => $row['reviewed_by'] !== null ? (string) $row['reviewed_by'] : null,
            'reviewedAt' => $row['reviewed_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
