<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Repositories\UserRepository;
use Onekana\Api\Support\Env;

final class AuthManager
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly TokenRevocationStore $revocations,
        private readonly UserRepository $users,
    ) {}

    public function userFromRequest(Request $request): array
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $payload = $this->jwt->verify($token);
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '' || $this->revocations->isRevoked($jti)) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $user = $this->users->findById((int) ($payload['sub'] ?? 0));
        if (! $user) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $request->set('jwt_payload', $payload);
        $request->set('jwt_token', $token);
        $request->set('user', $user);

        return $user;
    }

    public function assertAdminEmail(string $email): void
    {
        $adminEmail = Env::get('ADMIN_EMAIL', '');
        if ($adminEmail === '' || mb_strtolower($email) !== mb_strtolower($adminEmail)) {
            throw new HttpException(401, 'Identifiants invalides.');
        }
    }

    public function revokeRequestToken(Request $request): void
    {
        $payload = $request->get('jwt_payload');
        if (is_array($payload) && isset($payload['jti'], $payload['exp'])) {
            $this->revocations->revoke((string) $payload['jti'], (int) $payload['exp']);
        }
    }
}
