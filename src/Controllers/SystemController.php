<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Repositories\ResourceRepository;

final class SystemController
{
    private const EXPOSED = [
        'campaigns' => 'oohCampaigns',
        'contactMessages' => 'contactMessages',
        'notifications' => 'notifications',
        'roadmap' => 'roadmap',
    ];

    public function __construct(private readonly ResourceRepository $resources) {}

    public function summary(Request $request): Response
    {
        $tenantId = $this->queryTenant($request);

        return Response::json(['data' => [
            'contactMessages' => $this->resources->count('contact_messages', $tenantId),
            'campaigns' => $this->resources->count('ooh_campaigns', $tenantId),
            'campaignLines' => $this->resources->count('ooh_campaign_lines', $tenantId),
            'oohSites' => $this->resources->count('ooh_sites', $tenantId),
            'oohSupports' => $this->resources->count('ooh_supports', $tenantId),
            'oohEmplacements' => $this->resources->count('ooh_emplacements', $tenantId),
            'documents' => $this->resources->count('documents', $tenantId),
            'notifications' => $this->resources->count('notifications', $tenantId),
            'roadmap' => $this->resources->count('roadmap', $tenantId),
        ]]);
    }

    public function list(Request $request, string $resource): Response
    {
        if (! isset(self::EXPOSED[$resource])) {
            throw new HttpException(404, 'System resource not found.');
        }

        return Response::json(['data' => $this->resources->list(
            self::EXPOSED[$resource],
            $this->queryTenant($request),
            (int) $request->query('limit', 50)
        )]);
    }

    public function availability(Request $request): Response
    {
        $tenantId = $this->queryTenant($request);

        return Response::json(['data' => [
            'sites' => $this->resources->count('ooh_sites', $tenantId),
            'supports' => $this->resources->count('ooh_supports', $tenantId),
            'emplacements' => $this->resources->count('ooh_emplacements', $tenantId),
            'campaignLines' => $this->resources->count('ooh_campaign_lines', $tenantId),
        ]]);
    }

    public function financeSummary(Request $request): Response
    {
        $tenantId = $this->queryTenant($request);

        return Response::json(['data' => [
            'invoices' => $this->resources->count('invoices', $tenantId),
            'payments' => $this->resources->count('payments', $tenantId),
            'walletAccounts' => $this->resources->count('wallet_accounts', $tenantId),
            'walletTransactions' => $this->resources->count('wallet_transactions', $tenantId),
        ]]);
    }

    private function queryTenant(Request $request): ?int
    {
        $tenantId = $request->query('tenant_id');

        return $tenantId ? (int) $tenantId : null;
    }
}
