<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Support\Clock;
use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}

    public function tooManyAttempts(string $email, string $ip, int $max = 5, int $decaySeconds = 60): bool
    {
        $this->clearOld($decaySeconds);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = :email AND ip_address = :ip');
        $statement->execute(['email' => mb_strtolower($email), 'ip' => $ip]);

        return (int) $statement->fetchColumn() >= $max;
    }

    public function hit(string $email, string $ip): void
    {
        $statement = $this->pdo->prepare('INSERT INTO login_attempts (email, ip_address, created_at) VALUES (:email, :ip, :created_at)');
        $statement->execute([
            'email' => mb_strtolower($email),
            'ip' => $ip,
            'created_at' => Clock::now(),
        ]);
    }

    public function clear(string $email, string $ip): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip');
        $statement->execute(['email' => mb_strtolower($email), 'ip' => $ip]);
    }

    private function clearOld(int $decaySeconds): void
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - $decaySeconds);
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE created_at < :threshold');
        $statement->execute(['threshold' => $threshold]);
    }
}
