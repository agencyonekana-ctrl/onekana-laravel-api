<?php

namespace Tests\Feature;

use Onekana\Api\Support\Clock;
use Tests\ApiTestCase;

final class NativeApiTest extends ApiTestCase
{
    public function test_admin_can_login_and_get_me_payload(): void
    {
        $this->seedAdmin();
        $login = $this->login();

        $this->assertSame(200, $login->status);
        $this->assertSame('bearer', $login->payload['data']['token_type']);
        $this->assertSame('ONEKANA', $login->payload['data']['user']['tenant']['name']);
        $this->assertContains('admin', $login->payload['data']['user']['roles']);
        $this->assertStringContainsString('HttpOnly', $login->headers['Set-Cookie']);
        $this->assertStringContainsString('SameSite=Strict', $login->headers['Set-Cookie']);

        $me = $this->request('GET', '/api/auth/me', [], $this->bearer($login->payload['data']['access_token']));
        $this->assertSame(200, $me->status);
        $this->assertSame(getenv('ADMIN_EMAIL'), $me->payload['data']['email']);
        $this->assertContains('sales.view', $me->payload['data']['permissions']);
        $this->assertContains('sales.manage', $me->payload['data']['permissions']);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $this->seedAdmin();
        $this->pdo->exec('UPDATE users SET is_active = 0');
        $this->assertSame(401, $this->login()->status);
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
        $this->assertSame(429, $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => 'wrong-password',
        ])->status);
    }

    public function test_public_register_route_is_not_available_and_me_requires_token(): void
    {
        $this->assertSame(404, $this->request('POST', '/api/auth/register')->status);
        $this->assertSame(401, $this->request('GET', '/api/auth/me')->status);
    }

    public function test_refresh_cookie_rotates_and_logout_invalidates_session(): void
    {
        $this->seedAdmin();
        $login = $this->login();
        $oldToken = $login->payload['data']['access_token'];

        $refresh = $this->request('POST', '/api/auth/refresh', [], $this->refreshCookie($login));
        $this->assertSame(200, $refresh->status);
        $this->assertNotSame($oldToken, $refresh->payload['data']['access_token']);
        $this->assertSame(401, $this->request('POST', '/api/auth/refresh', [], $this->refreshCookie($login))->status);

        $newToken = $refresh->payload['data']['access_token'];
        $logout = $this->request('POST', '/api/auth/logout', [], [...$this->bearer($newToken), ...$this->refreshCookie($refresh)]);
        $this->assertSame(200, $logout->status);
        $this->assertSame('Deconnexion reussie.', $logout->payload['data']['message']);
        $this->assertSame(401, $this->request('GET', '/api/auth/me', [], $this->bearer($newToken))->status);
        $this->assertSame(401, $this->request('POST', '/api/auth/refresh', [], $this->refreshCookie($refresh))->status);
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
    }

    public function test_protected_resource_crud_requires_jwt(): void
    {
        $this->seedAdmin();
        $this->assertSame(401, $this->request('GET', '/api/packs')->status);

        $token = $this->loginToken();
        $created = $this->request('POST', '/api/packs', ['name' => 'Pack Premium'], $this->bearer($token));
        $this->assertSame(201, $created->status);
        $this->assertSame('Pack Premium', $created->payload['data']['name']);
    }

    public function test_agency_write_routes_are_not_available_in_pilot(): void
    {
        $this->seedAdmin();
        $headers = $this->bearer($this->loginToken());
        $this->assertSame(404, $this->request('POST', '/api/agency/campaigns', [], $headers)->status);
        $this->assertSame(404, $this->request('DELETE', '/api/agency/contacts/1', [], $headers)->status);
    }

    public function test_private_upload_validates_mime_and_requires_authenticated_download(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();

        $badFile = tempnam(sys_get_temp_dir(), 'onekana-bad-');
        file_put_contents($badFile, 'not a pdf');
        $rejected = $this->request('POST', '/api/files', [], $this->bearer($token), [
            'file' => ['name' => 'document.pdf', 'type' => 'application/pdf', 'tmp_name' => $badFile, 'error' => UPLOAD_ERR_OK, 'size' => filesize($badFile)],
        ]);
        $this->assertSame(422, $rejected->status);

        $goodFile = tempnam(sys_get_temp_dir(), 'onekana-pdf-');
        file_put_contents($goodFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
        $accepted = $this->request('POST', '/api/files', [], $this->bearer($token), [
            'file' => ['name' => 'contrat.pdf', 'type' => 'application/pdf', 'tmp_name' => $goodFile, 'error' => UPLOAD_ERR_OK, 'size' => filesize($goodFile)],
        ]);
        $this->assertSame(201, $accepted->status);
        $this->assertArrayHasKey('fileId', $accepted->payload['data']);
        $this->assertArrayNotHasKey('publicUrl', $accepted->payload['data']);

        $id = $accepted->payload['data']['fileId'];
        $this->assertSame(401, $this->request('GET', '/api/files/'.$id)->status);
        $download = $this->request('GET', '/api/files/'.$id, [], $this->bearer($token));
        $this->assertSame(200, $download->status);
        $this->assertSame('application/pdf', $download->headers['Content-Type']);
        $this->assertNotNull($download->body);

        $this->assertSame(204, $this->request('DELETE', '/api/files/'.$id, [], $this->bearer($token))->status);
    }

    public function test_health_and_security_headers_are_available(): void
    {
        $live = $this->request('GET', '/health/live');
        $this->assertSame(200, $live->status);
        $this->assertSame('ok', $live->payload['data']['status']);

        $response = $this->request('GET', '/api/system/summary');
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $response->headers['X-Frame-Options']);
        $this->assertArrayHasKey('Content-Security-Policy', $response->headers);
        $this->assertArrayHasKey('X-Request-Id', $response->headers);
    }

    private function login()
    {
        return $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => getenv('ADMIN_PASSWORD'),
        ]);
    }
}
