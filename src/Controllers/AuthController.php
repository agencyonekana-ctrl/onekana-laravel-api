<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Auth\AuthManager;
use Onekana\Api\Auth\JwtService;
use Onekana\Api\Auth\RateLimiter;
use Onekana\Api\Auth\RefreshTokenStore;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Http\Validator;
use Onekana\Api\Repositories\UserRepository;
use Onekana\Api\Support\Env;

final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly JwtService $jwt,
        private readonly RateLimiter $rateLimiter,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly UserRepository $users,
    ) {}

    public function login(Request $request): Response
    {
        $data = $request->input();
        Validator::require($data, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = trim((string) $data['email']);
        if ($this->rateLimiter->tooManyAttempts($email, $request->ip())) {
            throw new HttpException(429, 'Trop de tentatives de connexion.');
        }

        $user = $this->users->findByEmail($email);
        if (! $user || ! $this->users->isActive($user) || ! password_verify((string) $data['password'], (string) $user['password'])) {
            $this->rateLimiter->hit($email, $request->ip());
            throw new HttpException(401, 'Identifiants invalides.');
        }

        $this->rateLimiter->clear($email, $request->ip());

        return $this->tokenResponse($user);
    }

    public function me(Request $request): Response
    {
        return Response::json(['data' => $this->users->payload($request->get('user'))]);
    }

    public function refresh(Request $request): Response
    {
        $userId = $this->refreshTokens->consume((string) $request->cookie($this->cookieName(), ''));
        $user = $userId ? $this->users->findById($userId) : null;
        if (! $user || ! $this->users->isActive($user)) {
            throw new HttpException(401, 'Session expiree.');
        }

        return $this->tokenResponse($user);
    }

    public function logout(Request $request): Response
    {
        $this->refreshTokens->revoke((string) $request->cookie($this->cookieName(), ''));
        $this->auth->revokeBearerIfValid($request);

        return Response::json(
            ['data' => ['message' => 'Deconnexion reussie.']],
            200,
            ['Set-Cookie' => $this->expiredCookie()]
        );
    }

    private function tokenResponse(array $user): Response
    {
        $token = $this->jwt->issue($user);
        $refresh = $this->refreshTokens->issue((int) $user['id']);

        return Response::json(
            [
                'data' => [
                    'access_token' => $token['token'],
                    'token_type' => 'bearer',
                    'expires_in' => $token['ttl'],
                    'user' => $this->users->payload($user),
                ],
            ],
            200,
            ['Set-Cookie' => $this->refreshCookie($refresh['token'], $refresh['expires_at'])]
        );
    }

    private function refreshCookie(string $token, int $expiresAt): string
    {
        $parts = [
            $this->cookieName().'='.rawurlencode($token),
            'Expires='.gmdate('D, d M Y H:i:s T', $expiresAt),
            'Max-Age='.max(0, $expiresAt - time()),
            'Path=/api/auth',
            'HttpOnly',
            'SameSite=Strict',
        ];
        if (Env::get('APP_ENV', 'production') === 'production') {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function expiredCookie(): string
    {
        $parts = [
            $this->cookieName().'=',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'Max-Age=0',
            'Path=/api/auth',
            'HttpOnly',
            'SameSite=Strict',
        ];
        if (Env::get('APP_ENV', 'production') === 'production') {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function cookieName(): string
    {
        return 'onekana_refresh';
    }
}
