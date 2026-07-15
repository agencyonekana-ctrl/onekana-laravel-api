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

    public function test_password_reset_is_generic_single_use_and_revokes_sessions(): void
    {
        $this->seedAdmin();
        $login = $this->login();
        $oldAccessToken = $login->payload['data']['access_token'];
        $oldRefreshCookie = $this->refreshCookie($login);

        $unknown = $this->request('POST', '/api/auth/forgot-password', ['email' => 'unknown@example.test']);
        $known = $this->request('POST', '/api/auth/forgot-password', ['email' => getenv('ADMIN_EMAIL')]);
        $this->assertSame(200, $unknown->status);
        $this->assertSame($unknown->payload['data']['message'], $known->payload['data']['message']);
        $this->assertCount(1, $this->mailer->messages);

        $token = $this->mailer->latestToken();
        $reset = $this->request('POST', '/api/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPassword12345',
        ]);
        $this->assertSame(200, $reset->status);
        $this->assertSame(422, $this->request('POST', '/api/auth/reset-password', [
            'token' => $token,
            'password' => 'AnotherPassword123',
        ])->status);
        $this->assertSame(401, $this->request('GET', '/api/auth/me', [], $this->bearer($oldAccessToken))->status);
        $this->assertSame(401, $this->request('POST', '/api/auth/refresh', [], $oldRefreshCookie)->status);
        $this->assertSame(200, $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => 'NewPassword12345',
        ])->status);
    }

    public function test_admin_can_invite_and_manage_users_without_disabling_self(): void
    {
        $this->seedAdmin();
        $headers = $this->bearer($this->loginToken());
        $roles = $this->request('GET', '/api/admin/roles', [], $headers);
        $this->assertSame(200, $roles->status);
        $roleId = $roles->payload['data'][0]['id'];

        $created = $this->request('POST', '/api/admin/users', [
            'name' => 'Responsable Finance',
            'email' => 'finance@example.test',
            'roleId' => $roleId,
        ], $headers);
        $this->assertSame(201, $created->status);
        $this->assertCount(1, $this->mailer->messages);
        $this->assertTrue($this->mailer->messages[0]['invitation']);

        $users = $this->request('GET', '/api/admin/users', [], $headers);
        $this->assertSame(200, $users->status);
        $this->assertCount(2, $users->payload['data']);

        $updated = $this->request('PATCH', '/api/admin/users/'.$created->payload['data']['id'], [
            'isActive' => false,
        ], $headers);
        $this->assertSame(200, $updated->status);
        $this->assertFalse($updated->payload['data']['isActive']);

        $adminId = $this->pdo->query("SELECT id FROM users WHERE email = 'admin.testing@example.test'")->fetchColumn();
        $this->assertSame(422, $this->request('PATCH', '/api/admin/users/'.$adminId, ['isActive' => false], $headers)->status);
        $nonAdminRole = array_values(array_filter($roles->payload['data'], fn (array $role) => $role['key'] !== 'admin'))[0];
        $this->assertSame(422, $this->request('PATCH', '/api/admin/users/'.$adminId, ['roleId' => $nonAdminRole['id']], $headers)->status);
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

    public function test_finance_invoice_payment_and_trial_balance_are_relational_and_balanced(): void
    {
        $this->seedAdmin();
        $headers = $this->bearer($this->loginToken());
        $accounts = [];
        foreach ([
            ['code' => '411100', 'label' => 'Clients', 'type' => 'asset'],
            ['code' => '701100', 'label' => 'Ventes OOH', 'type' => 'income'],
            ['code' => '443100', 'label' => 'Taxe collectée', 'type' => 'liability'],
            ['code' => '521100', 'label' => 'Banque', 'type' => 'asset'],
            ['code' => '571100', 'label' => 'Wallet', 'type' => 'asset'],
            ['code' => '621100', 'label' => 'Charges', 'type' => 'expense'],
        ] as $account) {
            $response = $this->request('POST', '/api/accounting/accounts', $account, $headers);
            $this->assertSame(201, $response->status);
            $accounts[$account['code']] = $response->payload['data']['id'];
        }
        foreach ([['code' => 'VT', 'name' => 'Ventes', 'type' => 'vente'], ['code' => 'BQ', 'name' => 'Banque', 'type' => 'banque']] as $journal) {
            $this->assertSame(201, $this->request('POST', '/api/accounting/journals', $journal, $headers)->status);
        }
        $settings = $this->request('PUT', '/api/accounting/settings', [
            'salesAccountId' => $accounts['701100'], 'receivableAccountId' => $accounts['411100'],
            'taxAccountId' => $accounts['443100'], 'bankAccountId' => $accounts['521100'],
            'walletAccountId' => $accounts['571100'], 'expenseAccountId' => $accounts['621100'],
        ], $headers);
        $this->assertTrue($settings->payload['data']['configured']);

        $invoice = $this->request('POST', '/api/invoices', [
            'number' => 'FAC-TEST-001', 'clientName' => 'Client Test', 'tax' => '16.00',
            'lines' => [['description' => 'Campagne OOH', 'quantity' => '2.00', 'unitPrice' => '50.00']],
        ], $headers);
        $this->assertSame('116.00', $invoice->payload['data']['total']);
        $issued = $this->request('POST', '/api/invoices/'.$invoice->payload['data']['id'].'/issue', [], $headers);
        $this->assertSame('issued', $issued->payload['data']['status']);

        $payment = $this->request('POST', '/api/payments', [
            'invoiceId' => $invoice->payload['data']['id'], 'amount' => '116.00',
            'method' => 'bank', 'reference' => 'PAY-TEST-001', 'idempotencyKey' => 'PAY-TEST-001',
        ], $headers);
        $this->assertSame(201, $payment->status);
        $duplicate = $this->request('POST', '/api/payments', [
            'invoiceId' => $invoice->payload['data']['id'], 'amount' => '116.00',
            'method' => 'bank', 'reference' => 'PAY-TEST-001', 'idempotencyKey' => 'PAY-TEST-001',
        ], $headers);
        $this->assertSame($payment->payload['data']['id'], $duplicate->payload['data']['id']);

        $balance = $this->request('GET', '/api/accounting/trial-balance', [], $headers);
        $this->assertSame(200, $balance->status);
        foreach ($balance->payload['data'] as $line) {
            $this->assertIsString($line['debit']);
            $this->assertIsString($line['credit']);
        }
        $entries = $this->request('GET', '/api/accounting/entries', [], $headers);
        $this->assertCount(2, $entries->payload['data']);
        foreach ($entries->payload['data'] as $entry) $this->assertSame($entry['totalDebit'], $entry['totalCredit']);
    }

    public function test_finance_entries_are_immutable_reversible_and_closed_periods_reject_writes(): void
    {
        $this->seedAdmin();
        $headers = $this->bearer($this->loginToken());
        $debit = $this->request('POST', '/api/accounting/accounts', ['code' => '521200', 'label' => 'Banque test', 'type' => 'asset'], $headers)->payload['data']['id'];
        $credit = $this->request('POST', '/api/accounting/accounts', ['code' => '701200', 'label' => 'Ventes test', 'type' => 'income'], $headers)->payload['data']['id'];
        $journal = $this->request('POST', '/api/accounting/journals', ['code' => 'OD', 'name' => 'Opérations diverses', 'type' => 'od'], $headers)->payload['data']['id'];
        $entry = $this->request('POST', '/api/accounting/entries', ['date' => '2026-07-15', 'journalId' => $journal, 'reference' => 'OD-001', 'label' => 'Test', 'lines' => [['accountId' => $debit, 'debit' => '10.00', 'credit' => '0.00'], ['accountId' => $credit, 'debit' => '0.00', 'credit' => '10.00']]], $headers);
        $this->assertSame(201, $entry->status);
        $this->assertSame(404, $this->request('PATCH', '/api/accounting/entries/'.$entry->payload['data']['id'], ['label' => 'Altéré'], $headers)->status);
        $reversal = $this->request('POST', '/api/accounting/entries/'.$entry->payload['data']['id'].'/reverse', ['reason' => 'Correction'], $headers);
        $this->assertSame(200, $reversal->status);

        $periods = $this->request('GET', '/api/accounting/periods', [], $headers)->payload['data'];
        $this->assertSame(200, $this->request('POST', '/api/accounting/periods/'.$periods[0]['id'].'/close', [], $headers)->status);
        $blocked = $this->request('POST', '/api/accounting/entries', ['date' => '2026-07-16', 'journalId' => $journal, 'label' => 'Bloqué', 'lines' => [['accountId' => $debit, 'debit' => '1.00', 'credit' => '0.00'], ['accountId' => $credit, 'debit' => '0.00', 'credit' => '1.00']]], $headers);
        $this->assertSame(422, $blocked->status);
        $this->assertSame(422, $this->request('DELETE', '/api/accounting/accounts/'.$debit, [], $headers)->status);
    }

    public function test_finance_migration_preserves_legacy_json_rows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec("INSERT INTO tenants (name) VALUES ('ONEKANA')");
        $pdo->exec('CREATE TABLE accounting_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, payload TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO accounting_accounts (tenant_id, payload) VALUES (1, '{\"code\":\"411\"}')");
        \Onekana\Api\Database\Migrations\V006RelationalFinance::up($pdo);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM legacy_accounting_accounts')->fetchColumn());
        $columns = $pdo->query('PRAGMA table_info(accounting_accounts)')->fetchAll();
        $this->assertContains('code', array_column($columns, 'name'));
        $this->assertNotContains('payload', array_column($columns, 'name'));
    }

    private function login()
    {
        return $this->request('POST', '/api/auth/login', [
            'email' => getenv('ADMIN_EMAIL'),
            'password' => getenv('ADMIN_PASSWORD'),
        ]);
    }
}
