<?php

namespace Onekana\Api\Auth;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Support\Env;
use Onekana\Api\Support\Json;
use RuntimeException;

final class JwtService
{
    public function issue(array $user): array
    {
        $ttl = Env::int('JWT_TTL', 60) * 60;
        $now = time();
        $jti = bin2hex(random_bytes(16));
        $payload = [
            'iss' => Env::get('APP_URL', 'onekana-api'),
            'aud' => Env::get('JWT_AUDIENCE', 'onekana-business-manager'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'sub' => (string) $user['id'],
            'email' => $user['email'],
            'sv' => (int) ($user['session_version'] ?? 1),
            'jti' => $jti,
        ];

        return [
            'token' => $this->encode($payload),
            'ttl' => $ttl,
            'jti' => $jti,
            'expires_at' => $payload['exp'],
        ];
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $this->secret(), true));

        if (! hash_equals($signature, $encodedSignature)) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $header = $this->base64UrlDecodeJson($encodedHeader);
        $payload = $this->base64UrlDecodeJson($encodedPayload);

        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new HttpException(401, 'Unauthenticated.');
        }

        if (($payload['iss'] ?? null) !== Env::get('APP_URL', 'onekana-api')) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        if (($payload['aud'] ?? null) !== Env::get('JWT_AUDIENCE', 'onekana-business-manager')) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $now = time();
        if (($payload['nbf'] ?? 0) > $now || ($payload['exp'] ?? 0) <= $now) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $payload;
    }

    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(Json::encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = $this->base64UrlEncode(Json::encode($payload));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $this->secret(), true));

        return $header.'.'.$body.'.'.$signature;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecodeJson(string $value): array
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $data;
    }

    private function secret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if (! $secret) {
            throw new RuntimeException('JWT_SECRET must be configured.');
        }

        return $secret;
    }
}
