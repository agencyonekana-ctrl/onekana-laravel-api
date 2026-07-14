<?php

namespace Onekana\Api\Controllers;

use Onekana\Api\Http\Response;
use Onekana\Api\Support\Env;
use PDO;

final class HealthController
{
    public function __construct(private readonly PDO $pdo) {}

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

        $ready = $database === 'ok';
        return Response::json(['data' => [
            'status' => $ready ? 'ready' : 'degraded',
            'database' => $database,
            'agency' => Env::bool('AGENCY_API_ENABLED', false) ? 'configured' : 'disabled',
        ]], $ready ? 200 : 503);
    }
}
