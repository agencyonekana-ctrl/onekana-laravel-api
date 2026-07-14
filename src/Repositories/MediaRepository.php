<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Support\Clock;
use Onekana\Api\Support\Env;
use PDO;

final class MediaRepository
{
    public const ENTITY_RESOURCES = [
        'ooh_site' => 'oohSites',
        'ooh_support' => 'oohSupports',
        'ooh_emplacement' => 'oohEmplacements',
        'material' => 'materials',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function list(int $tenantId, string $entityType, ?int $entityId = null): array
    {
        $sql = 'SELECT * FROM media WHERE tenant_id = :tenant_id AND entity_type = :entity_type';
        $params = ['tenant_id' => $tenantId, 'entity_type' => $entityType];
        if ($entityId !== null) {
            $sql .= ' AND entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }
        $sql .= ' ORDER BY entity_id ASC, is_cover DESC, sort_order ASC, id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map([$this, 'present'], $statement->fetchAll());
    }

    public function count(int $tenantId, string $entityType, int $entityId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM media WHERE tenant_id = :tenant_id AND entity_type = :entity_type AND entity_id = :entity_id');
        $statement->execute(['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId]);

        return (int) $statement->fetchColumn();
    }

    public function create(int $tenantId, string $entityType, int $entityId, string $path, string $mimeType, ?string $altText, bool $isCover, int $sortOrder, ?int $userId): array
    {
        $now = Clock::now();
        $this->pdo->beginTransaction();
        try {
            if ($isCover) {
                $this->clearCover($tenantId, $entityType, $entityId);
            }

            $statement = $this->pdo->prepare('INSERT INTO media (tenant_id, entity_type, entity_id, path, mime_type, alt_text, is_cover, sort_order, created_by, created_at, updated_at) VALUES (:tenant_id, :entity_type, :entity_id, :path, :mime_type, :alt_text, :is_cover, :sort_order, :created_by, :created_at, :updated_at)');
            $statement->execute([
                'tenant_id' => $tenantId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'path' => $path,
                'mime_type' => $mimeType,
                'alt_text' => $altText,
                'is_cover' => $isCover ? 1 : 0,
                'sort_order' => $sortOrder,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $this->present($this->findRow($id, $tenantId));
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function update(int $id, int $tenantId, ?string $altText, ?bool $isCover, ?int $sortOrder): ?array
    {
        $row = $this->findRow($id, $tenantId);
        if (! $row) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            if ($isCover === true) {
                $this->clearCover($tenantId, $row['entity_type'], (int) $row['entity_id']);
            }

            $statement = $this->pdo->prepare('UPDATE media SET alt_text = :alt_text, is_cover = :is_cover, sort_order = :sort_order, updated_at = :updated_at WHERE id = :id AND tenant_id = :tenant_id');
            $statement->execute([
                'alt_text' => $altText ?? $row['alt_text'],
                'is_cover' => $isCover === null ? (int) $row['is_cover'] : ($isCover ? 1 : 0),
                'sort_order' => $sortOrder ?? (int) $row['sort_order'],
                'updated_at' => Clock::now(),
                'id' => $id,
                'tenant_id' => $tenantId,
            ]);
            $this->pdo->commit();

            return $this->present($this->findRow($id, $tenantId));
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function delete(int $id, int $tenantId): ?array
    {
        $row = $this->findRow($id, $tenantId);
        if (! $row) {
            return null;
        }

        $statement = $this->pdo->prepare('DELETE FROM media WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId]);

        if ((bool) $row['is_cover']) {
            $next = $this->pdo->prepare('SELECT id FROM media WHERE tenant_id = :tenant_id AND entity_type = :entity_type AND entity_id = :entity_id ORDER BY sort_order ASC, id ASC LIMIT 1');
            $next->execute(['tenant_id' => $tenantId, 'entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id']]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                $this->pdo->prepare('UPDATE media SET is_cover = 1, updated_at = :updated_at WHERE id = :id')->execute(['updated_at' => Clock::now(), 'id' => $nextId]);
            }
        }

        return $this->present($row);
    }

    public function deleteForEntity(int $tenantId, string $entityType, int $entityId): array
    {
        $rows = $this->list($tenantId, $entityType, $entityId);
        $statement = $this->pdo->prepare('DELETE FROM media WHERE tenant_id = :tenant_id AND entity_type = :entity_type AND entity_id = :entity_id');
        $statement->execute(['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId]);

        return $rows;
    }

    public function find(int $id, int $tenantId): ?array
    {
        $row = $this->findRow($id, $tenantId);

        return $row ? $this->present($row) : null;
    }

    private function clearCover(int $tenantId, string $entityType, int $entityId): void
    {
        $statement = $this->pdo->prepare('UPDATE media SET is_cover = 0 WHERE tenant_id = :tenant_id AND entity_type = :entity_type AND entity_id = :entity_id');
        $statement->execute(['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId]);
    }

    private function findRow(int $id, int $tenantId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM media WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function present(array $row): array
    {
        $baseUrl = rtrim((string) Env::get('APP_URL', ''), '/');
        $relativeUrl = '/storage/'.ltrim($row['path'], '/');

        return [
            'id' => (string) $row['id'],
            'entityType' => $row['entity_type'],
            'entityId' => (string) $row['entity_id'],
            'path' => $row['path'],
            'publicUrl' => $baseUrl !== '' ? $baseUrl.$relativeUrl : $relativeUrl,
            'mimeType' => $row['mime_type'],
            'altText' => $row['alt_text'],
            'isCover' => (bool) $row['is_cover'],
            'sortOrder' => (int) $row['sort_order'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
