<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\Agency\AgencyApiException;
use Onekana\Api\Support\Env;

$basePath = dirname(__DIR__);
Env::load($basePath);
$baseUrl = rtrim((string) Env::get('AGENCY_API_BASE_URL', ''), '/');
$authRequired = Env::bool('AGENCY_API_AUTH_REQUIRED', true);

if ($baseUrl === '') {
    fwrite(STDERR, "AGENCY_API_BASE_URL is required.\n");
    exit(1);
}

function remoteStatus(string $url): int
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => Env::int('AGENCY_API_TIMEOUT', 15),
        ],
    ]);
    @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('#HTTP/\\S+\\s+(\\d{3})#', $statusLine, $matches);

    return (int) ($matches[1] ?? 0);
}

$corePaths = [
    '/users.php?action=get_all',
    '/campaigns.php?action=get_all',
    '/contacts.php',
];
$geographicPaths = [
    '/geographic.php?entity=communes&action=get_all',
    '/geographic.php?entity=points_chauds&action=get_all',
    '/geographic.php?entity=trajets&action=get_all',
];
$failed = false;

foreach ($corePaths as $path) {
    $status = remoteStatus($baseUrl.$path);
    $valid = $authRequired ? in_array($status, [401, 403], true) : $status >= 200 && $status < 300;
    printf("%s direct %s %s\n", $valid ? 'PASS' : 'FAIL', $status ?: 'NO_RESPONSE', $path);
    $failed = $failed || ! $valid;
}

foreach ($geographicPaths as $path) {
    $status = remoteStatus($baseUrl.$path);
    $available = $authRequired ? in_array($status, [401, 403], true) : $status >= 200 && $status < 300;
    printf("%s geography %s %s\n", $available ? 'PASS' : 'INFO', $status ?: 'NO_RESPONSE', $path);
}

try {
    $client = AgencyApiClient::fromEnv();
} catch (AgencyApiException $exception) {
    fwrite(STDERR, "FAIL Agency client configuration: {$exception->getMessage()}\n");
    exit(1);
}

$requiredChecks = [
    'users' => fn () => $client->users(),
    'campaigns' => fn () => $client->campaigns(),
    'contacts' => fn () => $client->contacts(),
];
foreach ($requiredChecks as $name => $check) {
    try {
        $result = $check();
        $count = isset($result['data']) && is_array($result['data']) ? count($result['data']) : 0;
        printf("PASS proxied %s (%d results)\n", $name, $count);
    } catch (AgencyApiException $exception) {
        printf("FAIL proxied %s (%s)\n", $name, $exception->getMessage());
        $failed = true;
    }
}

foreach (['communes', 'points_chauds', 'trajets'] as $entity) {
    try {
        $result = $client->geographic($entity);
        $count = isset($result['data']) && is_array($result['data']) ? count($result['data']) : 0;
        printf("PASS proxied %s (%d results)\n", $entity, $count);
    } catch (AgencyApiException $exception) {
        printf("INFO proxied %s unavailable (%s)\n", $entity, $exception->getMessage());
    }
}

exit($failed ? 1 : 0);
