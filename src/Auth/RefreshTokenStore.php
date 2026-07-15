<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Support\Clock;
use Onekana\Api\Support\Env;
use PDO;

final class RefreshTokenStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function issue(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + (Env::int('JWT_REFRESH_TTL', 20160) * 60);
        $statement = $this->pdo->prepare(
            'INSERT INTO refresh_tokens (token_hash, user_id, expires_at, revoked_at, created_at) VALUES (:hash, :user_id, :expires_at, NULL, :created_at)'
        );
        $statement->execute([
            'hash' => hash('sha256', $token),
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'created_at' => Clock::now(),
        ]);

        $this->cleanup();

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function consume(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT user_id FROM refresh_tokens WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at > :now LIMIT 1'
            );
            $statement->execute(['hash' => $hash, 'now' => time()]);
            $userId = $statement->fetchColumn();

            if ($userId === false) {
                $this->pdo->rollBack();
                return null;
            }

            $update = $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = :revoked_at WHERE token_hash = :hash AND revoked_at IS NULL');
            $update->execute(['revoked_at' => Clock::now(), 'hash' => $hash]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->commit();
            return (int) $userId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }

        $statement = $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = :revoked_at WHERE token_hash = :hash AND revoked_at IS NULL');
        $statement->execute(['revoked_at' => Clock::now(), 'hash' => hash('sha256', $token)]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = :revoked_at WHERE user_id = :user_id AND revoked_at IS NULL');
        $statement->execute(['revoked_at' => Clock::now(), 'user_id' => $userId]);
    }

    private function cleanup(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM refresh_tokens WHERE expires_at < :now');
        $statement->execute(['now' => time()]);
    }
}
