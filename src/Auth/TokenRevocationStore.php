<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Support\Clock;
use PDO;

final class TokenRevocationStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function revoke(string $jti, int $expiresAt): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $this->pdo->prepare('REPLACE INTO revoked_tokens (jti, expires_at, created_at) VALUES (:jti, :expires_at, :created_at)');
        } else {
            $statement = $this->pdo->prepare('INSERT OR REPLACE INTO revoked_tokens (jti, expires_at, created_at) VALUES (:jti, :expires_at, :created_at)');
        }

        $statement->execute([
            'jti' => $jti,
            'expires_at' => $expiresAt,
            'created_at' => Clock::now(),
        ]);
    }

    public function isRevoked(string $jti): bool
    {
        $this->cleanup();
        $statement = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = :jti LIMIT 1');
        $statement->execute(['jti' => $jti]);

        return (bool) $statement->fetchColumn();
    }

    private function cleanup(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM revoked_tokens WHERE expires_at < :now');
        $statement->execute(['now' => time()]);
    }
}
