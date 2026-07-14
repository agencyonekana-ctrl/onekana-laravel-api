<?php

namespace Onekana\Api\Support;

use Onekana\Api\Http\Request;
use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(Request $request, int $status, string $requestId): void
    {
        if ($request->method === 'GET' && $status < 400) {
            return;
        }

        try {
            $user = $request->get('user');
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_logs (request_id, tenant_id, user_id, method, path, status, ip_address, created_at) VALUES (:request_id, :tenant_id, :user_id, :method, :path, :status, :ip, :created_at)'
            );
            $statement->execute([
                'request_id' => $requestId,
                'tenant_id' => is_array($user) ? ($user['tenant_id'] ?? null) : null,
                'user_id' => is_array($user) ? ($user['id'] ?? null) : null,
                'method' => $request->method,
                'path' => mb_substr($request->path, 0, 255),
                'status' => $status,
                'ip' => $request->ip(),
                'created_at' => Clock::now(),
            ]);
        } catch (\Throwable $exception) {
            error_log(Json::encode([
                'level' => 'error',
                'event' => 'audit_log_failed',
                'request_id' => $requestId,
                'message' => $exception->getMessage(),
            ]));
        }
    }
}
