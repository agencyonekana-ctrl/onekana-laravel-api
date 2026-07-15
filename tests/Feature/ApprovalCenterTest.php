<?php

namespace Tests\Feature;

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\App;
use Onekana\Api\Repositories\ApprovalRepository;
use Onekana\Api\Repositories\UserRepository;
use Tests\ApiTestCase;

final class ApprovalCenterTest extends ApiTestCase
{
    public function test_import_is_idempotent_and_never_writes_to_agency(): void
    {
        $this->seedAdmin();
        $this->app = new App(dirname(__DIR__), $this->pdo, $this->agencyClient());
        $token = $this->loginToken();

        $first = $this->request('POST', '/api/admin/cases/import', [], $this->bearer($token));
        $this->assertSame(200, $first->status);
        $this->assertSame(2, $first->payload['data']['created'], json_encode($first->payload));
        $this->assertSame([], $first->payload['data']['unavailable']);

        $second = $this->request('POST', '/api/admin/cases/import', [], $this->bearer($token));
        $this->assertSame(0, $second->payload['data']['created']);
        $this->assertSame(2, $second->payload['data']['existing']);

        $listed = $this->request('GET', '/api/admin/cases', [], $this->bearer($token), [], ['status' => 'pending']);
        $this->assertSame(200, $listed->status);
        $this->assertCount(2, $listed->payload['data']);
        $this->assertSame(2, $listed->payload['meta']['total']);
        $this->assertSame(['agency_campaign', 'agency_request'], array_values(array_unique(array_column($listed->payload['data'], 'resourceType'))));
    }

    public function test_case_assignment_transition_history_and_optimistic_locking(): void
    {
        $this->seedAdmin();
        $this->app = new App(dirname(__DIR__), $this->pdo, $this->agencyClient());
        $token = $this->loginToken();
        $this->request('POST', '/api/admin/cases/import', [], $this->bearer($token));
        $cases = $this->request('GET', '/api/admin/cases', [], $this->bearer($token));
        $case = $cases->payload['data'][0];
        $adminId = (string) $this->pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();

        $assigned = $this->request('PATCH', '/api/admin/cases/'.$case['id'], [
            'assignedTo' => $adminId,
            'priority' => 'urgent',
            'version' => $case['version'],
        ], $this->bearer($token));
        $this->assertSame(200, $assigned->status);
        $this->assertSame('urgent', $assigned->payload['data']['priority']);
        $this->assertSame($adminId, $assigned->payload['data']['assignedTo']);

        $stale = $this->request('PATCH', '/api/admin/cases/'.$case['id'], ['priority' => 'low', 'version' => $case['version']], $this->bearer($token));
        $this->assertSame(409, $stale->status);

        $claimed = $this->request('POST', '/api/admin/cases/'.$case['id'].'/transition', ['status' => 'in_review', 'version' => $assigned->payload['data']['version']], $this->bearer($token));
        $this->assertSame(200, $claimed->status);
        $approved = $this->request('POST', '/api/admin/cases/'.$case['id'].'/transition', ['status' => 'approved', 'version' => $claimed->payload['data']['version']], $this->bearer($token));
        $this->assertSame(200, $approved->status);
        $this->assertSame('approved', $approved->payload['data']['status']);
        $this->assertSame('local_only', $approved->payload['data']['syncStatus']);

        $comment = $this->request('POST', '/api/admin/cases/'.$case['id'].'/comments', ['body' => 'Contrôle administratif terminé.'], $this->bearer($token));
        $this->assertSame(201, $comment->status);
        $detail = $this->request('GET', '/api/admin/cases/'.$case['id'], [], $this->bearer($token));
        $this->assertNotEmpty($detail->payload['data']['events']);
        $this->assertCount(1, $detail->payload['data']['comments']);
    }

    public function test_rejection_requires_a_reason_and_manual_flagging_is_unique(): void
    {
        $this->seedAdmin();
        $token = $this->loginToken();
        $body = ['resourceType' => 'document', 'externalId' => '44', 'title' => 'Contrat client', 'priority' => 'high'];
        $created = $this->request('POST', '/api/admin/cases', $body, $this->bearer($token));
        $this->assertSame(201, $created->status);
        $duplicate = $this->request('POST', '/api/admin/cases', $body, $this->bearer($token));
        $this->assertSame(200, $duplicate->status);
        $this->assertSame($created->payload['data']['id'], $duplicate->payload['data']['id']);

        $claimed = $this->request('POST', '/api/admin/cases/'.$created->payload['data']['id'].'/transition', ['status' => 'in_review', 'version' => $created->payload['data']['version']], $this->bearer($token));
        $rejected = $this->request('POST', '/api/admin/cases/'.$created->payload['data']['id'].'/transition', ['status' => 'rejected', 'version' => $claimed->payload['data']['version']], $this->bearer($token));
        $this->assertSame(422, $rejected->status);
    }

    public function test_routes_require_an_authenticated_approval_permission(): void
    {
        $response = $this->request('GET', '/api/admin/cases');
        $this->assertSame(401, $response->status);
    }

    public function test_commercial_supervisor_can_decide_sales_but_not_user_accounts(): void
    {
        $this->seedAdmin();
        $users = new UserRepository($this->pdo);
        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE `key` = 'sales_supervisor'")->fetchColumn();
        $user = $users->createInvited(1, 'Responsable commercial', 'sales@example.test', $roleId);
        $users->updatePassword((int) $user['id'], 'SalesPassword123');
        $login = $this->request('POST', '/api/auth/login', ['email' => 'sales@example.test', 'password' => 'SalesPassword123']);
        $token = $login->payload['data']['access_token'];

        $contact = $this->request('POST', '/api/admin/cases', ['resourceType' => 'agency_contact', 'externalId' => 'sales-1', 'title' => 'Demande commerciale'], $this->bearer($token));
        $contactReview = $this->request('POST', '/api/admin/cases/'.$contact->payload['data']['id'].'/transition', ['status' => 'in_review', 'version' => $contact->payload['data']['version']], $this->bearer($token));
        $contactDecision = $this->request('POST', '/api/admin/cases/'.$contact->payload['data']['id'].'/transition', ['status' => 'approved', 'version' => $contactReview->payload['data']['version']], $this->bearer($token));
        $this->assertSame(200, $contactDecision->status);

        $account = $this->request('POST', '/api/admin/cases', ['resourceType' => 'agency_user', 'externalId' => 'user-1', 'title' => 'Compte utilisateur'], $this->bearer($token));
        $accountReview = $this->request('POST', '/api/admin/cases/'.$account->payload['data']['id'].'/transition', ['status' => 'in_review', 'version' => $account->payload['data']['version']], $this->bearer($token));
        $accountDecision = $this->request('POST', '/api/admin/cases/'.$account->payload['data']['id'].'/transition', ['status' => 'approved', 'version' => $accountReview->payload['data']['version']], $this->bearer($token));
        $this->assertSame(403, $accountDecision->status);
    }

    public function test_import_keeps_available_resources_when_one_agency_domain_fails(): void
    {
        $this->seedAdmin();
        $transport = static function (string $method, string $url): array {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/users.php') return ['status' => 503, 'payload' => ['message' => 'Unavailable']];
            if ($path === '/contacts.php') return ['status' => 200, 'payload' => ['contacts' => [['id' => 12, 'contact_person' => 'Contact disponible', 'etape_achat' => 'qualification']]]];
            if ($path === '/campaigns.php') return ['status' => 200, 'payload' => ['campaigns' => []]];
            return ['status' => 404, 'payload' => []];
        };
        $client = new AgencyApiClient('https://partial.example.test', '', '', 5, $transport, true, false);
        $this->app = new App(dirname(__DIR__), $this->pdo, $client);
        $token = $this->loginToken();
        $response = $this->request('POST', '/api/admin/cases/import', [], $this->bearer($token));

        $this->assertSame(200, $response->status);
        $this->assertContains('users', $response->payload['data']['unavailable']);
        $this->assertSame(1, $response->payload['data']['created']);
        $this->assertSame(1, $response->payload['data']['indexed']);
    }

    public function test_cases_are_isolated_by_tenant_and_auditors_are_read_only(): void
    {
        $this->seedAdmin();
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at, updated_at) VALUES (:name, :slug, :created_at, :updated_at)')->execute(['name' => 'Autre entreprise', 'slug' => 'autre', 'created_at' => $now, 'updated_at' => $now]);
        $tenantId = (int) $this->pdo->lastInsertId();
        $approvals = new ApprovalRepository($this->pdo);
        $subject = $approvals->upsertSubject($tenantId, ['sourceSystem' => 'agency', 'resourceType' => 'agency_contact', 'externalId' => 'foreign-1', 'title' => 'Dossier autre tenant', 'snapshot' => []]);
        $foreign = $approvals->createCase($tenantId, (int) $subject['id'], 'normal', null, gmdate('Y-m-d H:i:s', time() + 3600), null, 'imported');

        $adminToken = $this->loginToken();
        $list = $this->request('GET', '/api/admin/cases', [], $this->bearer($adminToken));
        $this->assertSame(0, $list->payload['meta']['total']);
        $this->assertSame(404, $this->request('GET', '/api/admin/cases/'.$foreign['id'], [], $this->bearer($adminToken))->status);

        $users = new UserRepository($this->pdo);
        $auditorRole = (int) $this->pdo->query("SELECT id FROM roles WHERE `key` = 'auditor'")->fetchColumn();
        $auditor = $users->createInvited(1, 'Auditeur', 'auditor@example.test', $auditorRole);
        $users->updatePassword((int) $auditor['id'], 'AuditorPassword123');
        $login = $this->request('POST', '/api/auth/login', ['email' => 'auditor@example.test', 'password' => 'AuditorPassword123']);
        $auditorToken = $login->payload['data']['access_token'];
        $this->assertSame(200, $this->request('GET', '/api/admin/cases', [], $this->bearer($auditorToken))->status);
        $this->assertSame(403, $this->request('POST', '/api/admin/cases', ['resourceType' => 'document', 'externalId' => 'forbidden', 'title' => 'Interdit'], $this->bearer($auditorToken))->status);
    }

    private function agencyClient(): AgencyApiClient
    {
        $transport = function (string $method, string $url): array {
            $this->assertSame('GET', $method, 'L’import ne doit effectuer aucune écriture Agency.');
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/users.php') return ['status' => 200, 'payload' => ['users' => [['user_id' => 'u-1', 'email' => 'client@example.test', 'first_name' => 'Amina', 'status' => 'pending']]]];
            if ($path === '/contacts.php') return ['status' => 200, 'payload' => ['contacts' => [['id' => 7, 'company_name' => 'Entreprise Test', 'contact_person' => 'Marc', 'etape_achat' => 'prospect']]]];
            if ($path === '/campaigns.php') return ['status' => 200, 'payload' => ['campaigns' => [['id' => 9, 'name' => 'Campagne Test', 'status' => 'submitted']]]];
            return ['status' => 404, 'payload' => []];
        };
        return new AgencyApiClient('https://approval-'.bin2hex(random_bytes(4)).'.example.test', '', '', 5, $transport, true, false);
    }
}
