<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Support\Clock;
use PDO;
use Throwable;

final class PasswordResetStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->invalidateForUser($userId);
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (token_hash, user_id, expires_at, used_at, created_at) VALUES (:hash, :user_id, :expires_at, NULL, :created_at)'
        );
        $statement->execute([
            'hash' => hash('sha256', $token),
            'user_id' => $userId,
            'expires_at' => time() + 1800,
            'created_at' => Clock::now(),
        ]);

        return $token;
    }

    public function consume(string $token): ?int
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now LIMIT 1');
            $statement->execute(['hash' => $hash, 'now' => time()]);
            $userId = $statement->fetchColumn();
            if ($userId === false) {
                $this->pdo->rollBack();
                return null;
            }

            $update = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE token_hash = :hash AND used_at IS NULL');
            $update->execute(['used_at' => Clock::now(), 'hash' => $hash]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->commit();
            return (int) $userId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function invalidateForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL');
        $statement->execute(['used_at' => Clock::now(), 'user_id' => $userId]);
    }
}
