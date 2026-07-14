<?php

namespace Tests\Feature;

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\App;
use Tests\ApiTestCase;

final class AgencyApiTest extends ApiTestCase
{
    public function test_agency_routes_require_admin_session(): void
    {
        $response = $this->request('GET', '/api/agency/contacts');

        $this->assertSame(401, $response->status);
    }

    public function test_disabled_agency_service_returns_clean_error(): void
    {
        $this->seedAdmin();
        $this->app = new App(dirname(__DIR__), $this->pdo, new AgencyApiClient('https://agency.example.test', '', '', 5, null, false, false));
        $token = $this->loginToken();

        $response = $this->request('GET', '/api/agency/summary', [], $this->bearer($token));

        $this->assertSame(503, $response->status);
        $this->assertSame('Integration Agency en attente de validation du fournisseur.', $response->payload['message']);
    }

    public function test_disabled_agency_service_does_not_call_the_external_provider(): void
    {
        $transport = function (): array {
            $this->fail('The external Agency provider must not be called while integration is disabled.');
        };
        $client = new AgencyApiClient('https://agency.example.test', 'service@example.test', 'test-password', 5, $transport, false);

        try {
            $client->contacts();
            $this->fail('Expected the disabled Agency integration to reject the request.');
        } catch (\Onekana\Api\Agency\AgencyApiException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('Integration Agency en attente de validation du fournisseur.', $exception->getMessage());
        }
    }

    public function test_temporary_read_only_mode_proxies_data_without_requesting_a_service_token(): void
    {
        $transport = function (string $method, string $url, array $headers): array {
            $this->assertSame('GET', $method);
            $this->assertSame('/users.php', parse_url($url, PHP_URL_PATH));
            $this->assertArrayNotHasKey('Authorization', $headers);

            return ['status' => 200, 'payload' => ['users' => [['user_id' => 7, 'email' => 'reader@example.test']]]];
        };
        $client = new AgencyApiClient('https://agency.example.test', '', '', 5, $transport, true, false);

        $result = $client->users();

        $this->assertSame('reader@example.test', $result['data'][0]['email']);
    }

    public function test_temporary_read_only_mode_rejects_remote_writes(): void
    {
        $transport = function (): array {
            $this->fail('A remote write must not be attempted without service authentication.');
        };
        $client = new AgencyApiClient('https://agency.example.test', '', '', 5, $transport, true, false);

        $this->expectException(\Onekana\Api\Agency\AgencyApiException::class);
        $client->updateContact(5, ['etape_achat' => 'qualification']);
    }

    public function test_agency_contacts_are_proxied_without_returning_service_token(): void
    {
        $this->seedAdmin();
        $this->app = new App(dirname(__DIR__), $this->pdo, $this->fakeAgencyClient());
        $token = $this->loginToken();

        $response = $this->request('GET', '/api/agency/contacts', [], $this->bearer($token));

        $this->assertSame(200, $response->status);
        $this->assertSame('Vodacom Congo', $response->payload['data'][0]['company_name']);
        $this->assertArrayNotHasKey('token', $response->payload);
        $this->assertArrayNotHasKey('access_token', $response->payload);
    }

    public function test_agency_summary_composes_counts(): void
    {
        $this->seedAdmin();
        $this->app = new App(dirname(__DIR__), $this->pdo, $this->fakeAgencyClient());
        $token = $this->loginToken();

        $response = $this->request('GET', '/api/agency/summary', [], $this->bearer($token));

        $this->assertSame(200, $response->status);
        $this->assertSame(1, $response->payload['data']['users']);
        $this->assertSame(1, $response->payload['data']['campaigns']);
        $this->assertSame(1, $response->payload['data']['contacts']);
        $this->assertSame(1, $response->payload['data']['communes']);
        $this->assertSame(1, $response->payload['data']['pointsChauds']);
        $this->assertSame(1, $response->payload['data']['trajets']);
    }

    private function fakeAgencyClient(): AgencyApiClient
    {
        $transport = function (string $method, string $url, array $headers, ?array $body, int $timeout): array {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            if ($path === '/auth.php' && ($query['action'] ?? '') === 'login') {
                return ['status' => 200, 'payload' => ['token' => 'test-agency-token']];
            }

            if (! isset($headers['Authorization']) || $headers['Authorization'] !== 'Bearer test-agency-token') {
                return ['status' => 401, 'payload' => ['success' => false, 'error' => 'Unauthorized']];
            }

            if ($path === '/users.php') {
                return ['status' => 200, 'payload' => ['success' => true, 'users' => [[
                    'id' => 1,
                    'user_id' => '48291039',
                    'email' => 'client@example.test',
                    'role' => 'client',
                ]], 'total' => 1, 'limit' => 50, 'offset' => 0]];
            }

            if ($path === '/campaigns.php') {
                return ['status' => 200, 'payload' => ['success' => true, 'campaigns' => [[
                    'id' => 10,
                    'name' => 'Campagne Test',
                    'status' => 'active',
                ]]]];
            }

            if ($path === '/contacts.php') {
                if ($method === 'PUT') {
                    return ['status' => 200, 'payload' => ['success' => true, 'contact' => ['id' => (int) ($query['id'] ?? 1), ...($body ?? [])]]];
                }

                return ['status' => 200, 'payload' => ['success' => true, 'contacts' => [[
                    'id' => 5,
                    'company_name' => 'Vodacom Congo',
                    'contact_person' => 'Marc Kabamba',
                    'emails' => ['marc@example.test'],
                    'etape_achat' => 'prospect',
                ]], 'total' => 1, 'limit' => 20, 'offset' => 0]];
            }

            if ($path === '/geographic.php') {
                $entity = (string) ($query['entity'] ?? '');
                return ['status' => 200, 'payload' => [[
                    'id' => 1,
                    'name' => $entity,
                ]]];
            }

            return ['status' => 404, 'payload' => ['success' => false, 'error' => 'Not found']];
        };

        return new AgencyApiClient('https://agency.example.test', 'service@example.test', 'test-password', 5, $transport);
    }
}
