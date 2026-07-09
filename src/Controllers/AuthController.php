<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Auth\AuthManager;
use Onekana\Api\Auth\JwtService;
use Onekana\Api\Auth\RateLimiter;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Http\Validator;
use Onekana\Api\Repositories\UserRepository;

final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly JwtService $jwt,
        private readonly RateLimiter $rateLimiter,
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
            throw new HttpException(429, 'Too many login attempts.');
        }

        try {
            $this->auth->assertAdminEmail($email);
            $user = $this->users->findByEmail($email);

            if (! $user || ! password_verify((string) $data['password'], (string) $user['password'])) {
                throw new HttpException(401, 'Identifiants invalides.');
            }
        } catch (HttpException $exception) {
            if ($exception->status === 401) {
                $this->rateLimiter->hit($email, $request->ip());
            }

            throw $exception;
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
        $user = $request->get('user');
        $this->auth->revokeRequestToken($request);

        return $this->tokenResponse($user);
    }

    public function logout(Request $request): Response
    {
        $this->auth->revokeRequestToken($request);

        return Response::json(['data' => ['message' => 'Déconnexion réussie.']]);
    }

    private function tokenResponse(array $user): Response
    {
        $token = $this->jwt->issue($user);

        return Response::json([
            'data' => [
                'access_token' => $token['token'],
                'token_type' => 'bearer',
                'expires_in' => $token['ttl'],
                'user' => $this->users->payload($user),
            ],
        ]);
    }
}
