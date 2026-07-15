<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\Response;
use Onekana\Api\Support\Env;
use PDO;

final class HealthController
{
    public function __construct(private readonly PDO $pdo, private readonly string $basePath) {}

    public function live(): Response
    {
        return Response::json(['data' => ['status' => 'ok']]);
    }

    public function ready(): Response
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'unavailable';
        }

        $privatePath = $this->basePath.'/storage/private';
        $storage = is_dir($privatePath) && is_writable($privatePath) ? 'ok' : 'unavailable';
        $configuration = $this->configurationStatus();
        $finance = $this->financeStatus();
        $ready = $database === 'ok' && $storage === 'ok' && $configuration === 'ok' && in_array($finance, ['ok', 'disabled'], true);
        return Response::json(['data' => [
            'status' => $ready ? 'ready' : 'degraded',
            'database' => $database,
            'privateStorage' => $storage,
            'configuration' => $configuration,
            'finance' => $finance,
            'agency' => Env::bool('AGENCY_API_ENABLED', false) ? 'configured' : 'disabled',
        ]], $ready ? 200 : 503);
    }

    private function configurationStatus(): string
    {
        if (Env::get('APP_ENV', 'production') !== 'production') return 'ok';
        $jwt = (string) Env::get('JWT_SECRET', '');
        $frontends = Env::list('FRONTEND_URLS');
        $appUrl = (string) Env::get('APP_URL', '');
        if (strlen($jwt) < 32 || $frontends === [] || ! str_starts_with($appUrl, 'https://')) return 'incomplete';
        if (Env::bool('MAIL_ENABLED', false) && (Env::get('MAIL_HOST', '') === '' || Env::get('MAIL_FROM_ADDRESS', '') === '')) return 'incomplete';
        if (Env::bool('AGENCY_API_ENABLED', false) && Env::get('AGENCY_API_BASE_URL', '') === '') return 'incomplete';
        if (Env::bool('AGENCY_API_ENABLED', false) && Env::bool('AGENCY_API_AUTH_REQUIRED', true) && (Env::get('AGENCY_API_EMAIL', '') === '' || Env::get('AGENCY_API_PASSWORD', '') === '')) return 'incomplete';
        return 'ok';
    }

    private function financeStatus(): string
    {
        if (! Env::bool('ENABLE_ADVANCED_FINANCE', false)) return 'disabled';
        try {
            $total = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
            $configured = (int) $this->pdo->query('SELECT COUNT(*) FROM finance_settings WHERE sales_account_id IS NOT NULL AND receivable_account_id IS NOT NULL AND bank_account_id IS NOT NULL AND wallet_account_id IS NOT NULL AND expense_account_id IS NOT NULL')->fetchColumn();
            return $total === $configured ? 'ok' : 'incomplete';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
