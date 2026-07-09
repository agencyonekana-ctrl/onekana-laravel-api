<?php

namespace Tests;

use Onekana\Api\App;
use Onekana\Api\Database\AdminSeeder;
use Onekana\Api\Database\Connection;
use Onekana\Api\Database\Schema;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class ApiTestCase extends BaseTestCase
{
    protected PDO $pdo;
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('APP_ENV=testing');
        putenv('APP_DEBUG=true');
        putenv('APP_URL=http://localhost');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('ADMIN_EMAIL=admin.testing@example.test');
        putenv('ADMIN_PASSWORD=TestAdminPassword12');
        putenv('ADMIN_NAME=Admin Test');
        putenv('JWT_SECRET=test-jwt-secret-at-least-32-characters');
        putenv('JWT_TTL=60');
        putenv('SYSTEM_API_TOKEN=test-system-token');
        putenv('FRONTEND_URLS=http://localhost:5173,http://127.0.0.1:5173');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        Connection::reset($this->pdo);
        Schema::migrate($this->pdo);

        $this->app = new App(dirname(__DIR__), $this->pdo);
    }

    protected function seedAdmin(): void
    {
        AdminSeeder::run($this->pdo);
    }

    protected function request(string $method, string $path, array $body = [], array $headers = [], array $files = [], array $query = []): Response
    {
        return $this->app->handle(Request::fake($method, $path, $body, $headers, $files, $query));
    }

    protected function loginToken(): string
    {
        $response = $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => getenv('ADMIN_PASSWORD'),
        ]);

        return $response->payload['data']['access_token'];
    }

    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
