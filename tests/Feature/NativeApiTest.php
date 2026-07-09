<?php

namespace Tests\Feature;

use Onekana\Api\Support\Clock;
use Tests\ApiTestCase;

final class NativeApiTest extends ApiTestCase
{
    public function test_admin_can_login_and_get_me_payload(): void
    {
        $this->seedAdmin();

        $login = $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => getenv('ADMIN_PASSWORD'),
        ]);

        $this->assertSame(200, $login->status);
        $this->assertSame('bearer', $login->payload['data']['token_type']);
        $this->assertSame('ONEKANA', $login->payload['data']['user']['tenant']['name']);
        $this->assertContains('admin', $login->payload['data']['user']['roles']);

        $me = $this->request('GET', '/api/auth/me', [], $this->bearer($login->payload['data']['access_token']));

        $this->assertSame(200, $me->status);
        $this->assertSame(getenv('ADMIN_EMAIL'), $me->payload['data']['email']);
        $this->assertContains('sales.view', $me->payload['data']['permissions']);
        $this->assertContains('sales', $me->payload['data']['modules']);
    }

    public function test_admin_login_rejects_invalid_password_and_rate_limits(): void
    {
        $this->seedAdmin();

        for ($i = 0; $i < 5; $i++) {
            $response = $this->request('POST', '/api/auth/login', [
                'email' => getenv('ADMIN_EMAIL'),
                'password' => 'wrong-password',
            ]);
            $this->assertSame(401, $response->status);
        }

        $limited = $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => 'wrong-password',
        ]);

        $this->assertSame(429, $limited->status);
    }

    public function test_public_register_route_is_not_available_and_me_requires_token(): void
    {
        $this->assertSame(404, $this->request('POST', '/api/auth/register')->status);
        $this->assertSame(401, $this->request('GET', '/api/auth/me')->status);
    }

    public function test_refresh_returns_valid_new_token_and_logout_invalidates_token(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();

        $refresh = $this->request('POST', '/api/auth/refresh', [], $this->bearer($token));
        $this->assertSame(200, $refresh->status);
        $this->assertNotSame($token, $refresh->payload['data']['access_token']);

        $this->assertSame(401, $this->request('GET', '/api/auth/me', [], $this->bearer($token))->status);

        $newToken = $refresh->payload['data']['access_token'];
        $logout = $this->request('POST', '/api/auth/logout', [], $this->bearer($newToken));
        $this->assertSame(200, $logout->status);
        $this->assertSame('Déconnexion réussie.', $logout->payload['data']['message']);
        $this->assertSame(401, $this->request('GET', '/api/auth/me', [], $this->bearer($newToken))->status);
    }

    public function test_system_api_requires_token_and_returns_summary(): void
    {
        $this->seedAdmin();
        $this->pdo->prepare('INSERT INTO contact_messages (tenant_id, payload, created_at, updated_at) VALUES (1, :payload, :created_at, :updated_at)')
            ->execute(['payload' => '{"name":"Client Demo"}', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);

        $this->assertSame(401, $this->request('GET', '/api/system/summary')->status);

        $summary = $this->request('GET', '/api/system/summary', [], ['X-System-Token' => 'test-system-token']);
        $this->assertSame(200, $summary->status);
        $this->assertSame(1, $summary->payload['data']['contactMessages']);
        $this->assertArrayHasKey('oohEmplacements', $summary->payload['data']);
    }

    public function test_protected_resource_crud_requires_jwt(): void
    {
        $this->seedAdmin();

        $this->assertSame(401, $this->request('GET', '/api/packs')->status);

        $token = $this->loginToken();
        $created = $this->request('POST', '/api/packs', ['name' => 'Pack Premium'], $this->bearer($token));
        $this->assertSame(201, $created->status);
        $this->assertSame('Pack Premium', $created->payload['data']['name']);

        $listed = $this->request('GET', '/api/packs', [], $this->bearer($token));
        $this->assertSame(200, $listed->status);
        $this->assertSame('Pack Premium', $listed->payload['data'][0]['name']);
    }

    public function test_upload_rejects_unsupported_file_types_and_accepts_documents(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();

        $badFile = tempnam(sys_get_temp_dir(), 'onekana-bad-');
        file_put_contents($badFile, 'bad');

        $rejected = $this->request('POST', '/api/uploads', [], $this->bearer($token), [
            'file' => [
                'name' => 'script.exe',
                'type' => 'application/x-msdownload',
                'tmp_name' => $badFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($badFile),
            ],
        ]);

        $this->assertSame(422, $rejected->status);

        $goodFile = tempnam(sys_get_temp_dir(), 'onekana-pdf-');
        file_put_contents($goodFile, '%PDF-1.4');

        $accepted = $this->request('POST', '/api/uploads', ['path' => 'documents/contrat.pdf'], $this->bearer($token), [
            'file' => [
                'name' => 'contrat.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $goodFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($goodFile),
            ],
        ]);

        $this->assertSame(201, $accepted->status);
        $this->assertArrayHasKey('publicUrl', $accepted->payload['data']);

        $stored = dirname(__DIR__, 2).'/public/storage/'.$accepted->payload['data']['path'];
        if (is_file($stored)) {
            unlink($stored);
        }
    }

    public function test_security_headers_are_added(): void
    {
        $response = $this->request('GET', '/api/system/summary');

        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $response->headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy']);
    }
}
