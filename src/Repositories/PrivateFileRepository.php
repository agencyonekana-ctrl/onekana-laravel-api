<?php

namespace Onekana\Api\Repositories;

use Onekana\Api\Support\Clock;
use PDO;

final class PrivateFileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $tenantId, string $path, string $originalName, string $mimeType, int $size, ?int $userId): array
    {
        $statement = $this->pdo->prepare('INSERT INTO private_files (tenant_id, path, original_name, mime_type, size_bytes, created_by, created_at) VALUES (:tenant_id, :path, :original_name, :mime_type, :size_bytes, :created_by, :created_at)');
        $statement->execute([
            'tenant_id' => $tenantId,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'created_by' => $userId,
            'created_at' => Clock::now(),
        ]);

        return $this->find((int) $this->pdo->lastInsertId(), $tenantId) ?? [];
    }

    public function find(int $id, int $tenantId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM private_files WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function delete(int $id, int $tenantId): ?array
    {
        $row = $this->find($id, $tenantId);
        if (! $row) {
            return null;
        }
        $statement = $this->pdo->prepare('DELETE FROM private_files WHERE id = :id AND tenant_id = :tenant_id');
        $statement->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $row;
    }
}
