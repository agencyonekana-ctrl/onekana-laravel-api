<?php

namespace Onekana\Api;

use Onekana\Api\Agency\AgencyApiClient;
use Onekana\Api\Auth\AuthManager;
use Onekana\Api\Auth\JwtService;
use Onekana\Api\Auth\RateLimiter;
use Onekana\Api\Auth\RefreshTokenStore;
use Onekana\Api\Auth\TokenRevocationStore;
use Onekana\Api\Controllers\AgencyController;
use Onekana\Api\Controllers\AuthController;
use Onekana\Api\Controllers\GeographicReviewController;
use Onekana\Api\Controllers\HealthController;
use Onekana\Api\Controllers\MediaController;
use Onekana\Api\Controllers\PrivateFileController;
use Onekana\Api\Controllers\ResourceController;
use Onekana\Api\Controllers\SystemController;
use Onekana\Api\Database\Connection;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Http\Router;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Repositories\GeographicReviewRepository;
use Onekana\Api\Repositories\MediaRepository;
use Onekana\Api\Repositories\PrivateFileRepository;
use Onekana\Api\Repositories\UserRepository;
use Onekana\Api\Support\Env;
use Onekana\Api\Support\AuditLogger;
use Onekana\Api\Support\Json;
use PDO;
use Throwable;

final class App
{
    private Router $router;
    private AuthManager $auth;
    private UserRepository $users;
    private RateLimiter $rateLimiter;
    private AuditLogger $auditLogger;

    public function __construct(private readonly string $basePath, ?PDO $pdo = null, ?AgencyApiClient $agency = null)
    {
        Env::load($basePath);
        if ($pdo) {
            Connection::reset($pdo);
        }

        $pdo = Connection::pdo();
        $this->router = new Router();
        $this->users = new UserRepository($pdo);
        $this->rateLimiter = new RateLimiter($pdo);
        $this->auditLogger = new AuditLogger($pdo);
        $jwt = new JwtService();
        $revocations = new TokenRevocationStore($pdo);
        $this->auth = new AuthManager($jwt, $revocations, $this->users);
        $resources = new ResourceRepository($pdo);
        $mediaRepository = new MediaRepository($pdo);
        $privateFiles = new PrivateFileRepository($pdo);

        $authController = new AuthController($this->auth, $jwt, $this->rateLimiter, new RefreshTokenStore($pdo), $this->users);
        $resourceController = new ResourceController($resources, $mediaRepository, $basePath, $privateFiles);
        $systemController = new SystemController($resources);
        $agencyController = new AgencyController($agency ?? AgencyApiClient::fromEnv());
        $geographicReviewController = new GeographicReviewController(new GeographicReviewRepository($pdo));
        $mediaController = new MediaController($basePath, $mediaRepository, $resources, $this->users);
        $privateFileController = new PrivateFileController($basePath, $privateFiles);
        $healthController = new HealthController($pdo);

        $this->routes($authController, $resourceController, $systemController, $agencyController, $geographicReviewController, $mediaController, $privateFileController, $healthController);
    }

    public function handle(Request $request): Response
    {
        $requestId = (string) $request->header('X-Request-Id', '');
        if (! preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(12));
        }
        $request->set('request_id', $requestId);

        try {
            if ($request->method === 'OPTIONS') {
                return $this->decorate(Response::noContent(), $request);
            }

            [$route, $params] = $this->router->match($request);
            foreach ($route['middleware'] as $middleware) {
                $this->applyMiddleware($middleware, $request);
            }

            $response = $route['handler']($request, $params);

            return $this->finalize($response, $request, $requestId);
        } catch (HttpException $exception) {
            $payload = ['message' => $exception->getMessage()];
            if ($exception->errors !== []) {
                $payload['errors'] = $exception->errors;
            }

            return $this->finalize(Response::json($payload, $exception->status), $request, $requestId);
        } catch (Throwable $exception) {
            $debug = Env::bool('APP_DEBUG', false);
            error_log(Json::encode([
                'level' => 'error',
                'event' => 'unhandled_exception',
                'request_id' => $requestId,
                'path' => $request->path,
                'message' => $exception->getMessage(),
            ]));

            return $this->finalize(Response::json([
                'message' => $debug ? $exception->getMessage() : 'Server error.',
            ], 500), $request, $requestId);
        }
    }

    private function routes(AuthController $auth, ResourceController $resources, SystemController $system, AgencyController $agency, GeographicReviewController $geographicReviews, MediaController $media, PrivateFileController $privateFiles, HealthController $health): void
    {
        $this->router->add('GET', '/health/live', fn () => $health->live());
        $this->router->add('GET', '/health/ready', fn () => $health->ready());
        $this->router->add('GET', '/api/health/live', fn () => $health->live());
        $this->router->add('GET', '/api/health/ready', fn () => $health->ready());

        $this->router->add('POST', '/api/auth/login', fn (Request $request) => $auth->login($request), ['origin']);
        $this->router->add('GET', '/api/auth/me', fn (Request $request) => $auth->me($request), ['auth']);
        $this->router->add('POST', '/api/auth/refresh', fn (Request $request) => $auth->refresh($request), ['origin', 'rate:refresh:20:60']);
        $this->router->add('POST', '/api/auth/logout', fn (Request $request) => $auth->logout($request), ['origin', 'rate:logout:20:60']);

        $this->router->add('GET', '/api/system/summary', fn (Request $request) => $system->summary($request), ['system']);
        $this->router->add('GET', '/api/system/campaigns', fn (Request $request) => $system->list($request, 'campaigns'), ['system']);
        $this->router->add('GET', '/api/system/contact-messages', fn (Request $request) => $system->list($request, 'contactMessages'), ['system']);
        $this->router->add('GET', '/api/system/ooh/availability', fn (Request $request) => $system->availability($request), ['system']);
        $this->router->add('GET', '/api/system/notifications', fn (Request $request) => $system->list($request, 'notifications'), ['system']);
        $this->router->add('GET', '/api/system/roadmap', fn (Request $request) => $system->list($request, 'roadmap'), ['system']);
        $this->router->add('GET', '/api/system/finance-summary', fn (Request $request) => $system->financeSummary($request), ['system']);

        $fileWrite = ['auth', 'module:operations', 'permission:operations.manage', 'rate:documents:20:60'];
        $this->router->add('POST', '/api/files', fn (Request $request) => $privateFiles->store($request), $fileWrite);
        $this->router->add('POST', '/api/uploads', fn (Request $request) => $privateFiles->store($request), $fileWrite);
        $this->router->add('GET', '/api/files/{id}', fn (Request $request, array $params) => $privateFiles->download($request, (int) $params['id']), ['auth', 'module:operations', 'permission:operations.view', 'rate:documents:120:60']);
        $this->router->add('DELETE', '/api/files/{id}', fn (Request $request, array $params) => $privateFiles->destroy($request, (int) $params['id']), $fileWrite);
        $this->router->add('PUT', '/api/notifications/{id}/read', fn (Request $request, array $params) => $resources->markNotificationRead($request, (int) $params['id']), ['auth', 'module:dashboard', 'permission:dashboard.view']);

        $agencyRead = ['auth', 'module:sales', 'permission:sales.view', 'rate:agency:120:60'];
        $this->router->add('GET', '/api/agency/profile', fn () => $agency->profile(), $agencyRead);
        $this->router->add('GET', '/api/agency/summary', fn () => $agency->summary(), $agencyRead);

        $this->router->add('GET', '/api/agency/users', fn (Request $request) => $agency->users($request), $agencyRead);
        $this->router->add('GET', '/api/agency/users/{id}', fn (Request $request, array $params) => $agency->user((int) $params['id']), $agencyRead);

        $this->router->add('GET', '/api/agency/campaigns', fn (Request $request) => $agency->campaigns($request), $agencyRead);
        $this->router->add('GET', '/api/agency/campaigns/{id}', fn (Request $request, array $params) => $agency->campaign((int) $params['id']), $agencyRead);

        $this->router->add('GET', '/api/agency/contacts', fn (Request $request) => $agency->contacts($request), $agencyRead);
        $this->router->add('GET', '/api/agency/contacts/{id}', fn (Request $request, array $params) => $agency->contact((int) $params['id']), $agencyRead);

        if (Env::bool('ENABLE_GEOGRAPHY', false)) {
            $geographicRead = ['auth', 'module:inventory', 'permission:inventory.view'];
            foreach (['communes', 'points-chauds', 'trajets'] as $entity) {
                $this->router->add('GET', "/api/agency/geographic/{$entity}", fn (Request $request) => $agency->geographic($request, $entity), $geographicRead);
                $this->router->add('GET', "/api/agency/geographic/{$entity}/{id}", fn (Request $request, array $params) => $agency->geographicItem((int) $params['id'], $entity), $geographicRead);
            }

            $this->router->add('GET', '/api/geographic-reviews', fn (Request $request) => $geographicReviews->index($request), $geographicRead);
            foreach (['commune', 'point_chaud', 'trajet'] as $entityType) {
                $this->router->add('PUT', "/api/geographic-reviews/{$entityType}/{externalId}", fn (Request $request, array $params) => $geographicReviews->update($request, $entityType, (int) $params['externalId']), ['auth', 'module:inventory', 'permission:inventory.manage']);
            }
        }

        $this->router->add('GET', '/api/media', fn (Request $request) => $media->index($request), ['auth']);
        $this->router->add('POST', '/api/media', fn (Request $request) => $media->store($request), ['auth', 'rate:media:30:60']);
        $this->router->add('PATCH', '/api/media/{id}', fn (Request $request, array $params) => $media->update($request, (int) $params['id']), ['auth', 'rate:media:60:60']);
        $this->router->add('DELETE', '/api/media/{id}', fn (Request $request, array $params) => $media->destroy($request, (int) $params['id']), ['auth', 'rate:media:60:60']);

        foreach ($this->resourceMap() as $uri => $config) {
            [$resource, $module, $readPermission] = $config;
            $writePermission = $config[3] ?? $readPermission;
            $readOnly = $config[4] ?? false;
            $readMiddleware = ['auth', "module:{$module}", "permission:{$readPermission}"];
            $writeMiddleware = ['auth', "module:{$module}", "permission:{$writePermission}"];
            $this->router->add('GET', "/api/{$uri}", fn (Request $request) => $resources->index($request, $resource), $readMiddleware);
            $this->router->add('GET', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->show($request, $resource, (int) $params['id']), $readMiddleware);
            if (! $readOnly) {
                $this->router->add('POST', "/api/{$uri}", fn (Request $request) => $resources->store($request, $resource), $writeMiddleware);
                $this->router->add('PUT', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->update($request, $resource, (int) $params['id']), $writeMiddleware);
                $this->router->add('PATCH', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->update($request, $resource, (int) $params['id']), $writeMiddleware);
                $this->router->add('DELETE', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->destroy($request, $resource, (int) $params['id']), $writeMiddleware);
            }
        }
    }

    private function applyMiddleware(string $middleware, Request $request): void
    {
        if ($middleware === 'origin') {
            $origin = $request->header('Origin');
            if ($origin && ! $this->isAllowedOrigin($origin)) {
                throw new HttpException(403, 'Origine non autorisee.');
            }
            return;
        }

        if (str_starts_with($middleware, 'rate:')) {
            [, $key, $max, $seconds] = array_pad(explode(':', $middleware), 4, null);
            if (! $this->rateLimiter->throttle((string) $key, $request->ip(), (int) $max, (int) $seconds)) {
                throw new HttpException(429, 'Trop de requetes.');
            }
            return;
        }

        if ($middleware === 'auth') {
            $this->auth->userFromRequest($request);

            return;
        }

        if ($middleware === 'system') {
            $expected = Env::get('SYSTEM_API_TOKEN', '');
            $provided = (string) $request->header('X-System-Token', '');
            if ($expected === '') {
                throw new HttpException(503, 'System API token is not configured.');
            }
            if (! hash_equals($expected, $provided)) {
                throw new HttpException(401, 'Invalid system API token.');
            }

            return;
        }

        if (str_starts_with($middleware, 'module:')) {
            $module = substr($middleware, 7);
            if (! $this->users->hasModule($request->get('user'), $module)) {
                throw new HttpException(403, 'Module non autorise.');
            }

            return;
        }

        if (str_starts_with($middleware, 'permission:')) {
            $permission = substr($middleware, 11);
            if (! $this->users->hasPermission($request->get('user'), $permission)) {
                throw new HttpException(403, 'Acces non autorise.');
            }
        }
    }

    private function decorate(Response $response, Request $request): Response
    {
        $origin = $request->header('Origin');
        $cors = [
            'Access-Control-Allow-Headers' => 'Accept, Content-Type, Authorization, X-System-Token',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Expose-Headers' => 'Content-Disposition, X-Request-Id',
        ];

        if ($origin && $this->isAllowedOrigin($origin)) {
            $cors['Access-Control-Allow-Origin'] = $origin;
            $cors['Access-Control-Allow-Credentials'] = 'true';
            $cors['Vary'] = 'Origin';
        }

        $headers = [
            ...$cors,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self)',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'X-Request-Id' => (string) $request->get('request_id', ''),
        ];
        if (Env::get('APP_ENV', 'production') === 'production') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $response->withHeaders($headers);
    }

    private function finalize(Response $response, Request $request, string $requestId): Response
    {
        $this->auditLogger->record($request, $response->status, $requestId);
        return $this->decorate($response, $request);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if (in_array($origin, Env::list('FRONTEND_URLS'), true)) {
            return true;
        }

        if (Env::get('APP_ENV', 'production') !== 'production') {
            return (bool) preg_match('#^http://(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+|192\.168\.\d+\.\d+):\d+$#', $origin);
        }

        return false;
    }

    private function resourceMap(): array
    {
        $resources = [
            'documents' => ['documents', 'operations', 'operations.view', 'operations.manage'],
            'reservations' => ['reservations', 'sales', 'sales.view', 'sales.manage'],
            'reservation-types' => ['reservationTypes', 'sales', 'sales.view', 'sales.manage'],
            'packs' => ['packsCommerciaux', 'sales', 'sales.view', 'sales.manage'],
            'options' => ['optionsComplementaires', 'sales', 'sales.view', 'sales.manage'],
            'contact-messages' => ['contactMessages', 'sales', 'sales.view', 'sales.manage'],
            'campaign-types' => ['campaignTypes', 'sales', 'sales.view', 'sales.manage'],
            'campaign-prices' => ['campaignPrices', 'sales', 'sales.view', 'sales.manage'],
            'communes' => ['communes', 'inventory', 'inventory.view', 'inventory.manage'],
            'quartiers' => ['quartiers', 'inventory', 'inventory.view', 'inventory.manage'],
            'points-chauds' => ['pointsChauds', 'inventory', 'inventory.view', 'inventory.manage'],
            'transport-routes' => ['transportRoutes', 'inventory', 'inventory.view', 'inventory.manage'],
            'route-coordinates' => ['routeCoordinates', 'inventory', 'inventory.view', 'inventory.manage'],
            'agenda-events' => ['agendaEvents', 'operations', 'operations.view', 'operations.manage'],
            'ooh/campaigns' => ['oohCampaigns', 'sales', 'sales.view', 'sales.manage', true],
            'ooh/campaign-lines' => ['oohCampaignLines', 'sales', 'sales.view', 'sales.manage', true],
            'ooh/sites' => ['oohSites', 'inventory', 'inventory.view', 'inventory.manage'],
            'ooh/supports' => ['oohSupports', 'inventory', 'inventory.view', 'inventory.manage'],
            'ooh/emplacements' => ['oohEmplacements', 'inventory', 'inventory.view', 'inventory.manage'],
            'ooh/pricing-rules' => ['oohPricingRules', 'inventory', 'inventory.view', 'inventory.manage'],
            'ooh/tasks' => ['oohTasks', 'inventory', 'inventory.view', 'inventory.manage'],
            'employees' => ['employees', 'team', 'team.view', 'team.manage'],
            'schedules' => ['schedules', 'operations', 'operations.view', 'operations.manage'],
            'materials' => ['materials', 'team', 'team.view', 'team.manage'],
            'material-types' => ['materialTypes', 'team', 'team.view', 'team.manage'],
            'job-titles' => ['jobTitles', 'team', 'team.view', 'team.manage'],
            'employee-statuses' => ['employeeStatuses', 'team', 'team.view', 'team.manage'],
            'departments' => ['departments', 'administration', 'administration.view', 'administration.manage'],
            'notifications' => ['notifications', 'dashboard', 'dashboard.view', 'dashboard.manage'],
            'roadmap' => ['roadmap', 'dashboard', 'dashboard.view', 'dashboard.manage'],
            'invoices' => ['invoices', 'finance', 'finance.view', 'finance.manage'],
            'payments' => ['payments', 'finance', 'finance.view', 'finance.manage'],
        ];

        if (Env::bool('ENABLE_ADVANCED_FINANCE', false)) {
            $resources += [
                'accounting/accounts' => ['accountingAccounts', 'finance', 'finance.view', 'finance.manage'],
                'accounting/journals' => ['accountingJournals', 'finance', 'finance.view', 'finance.manage'],
                'accounting/entries' => ['accountingEntries', 'finance', 'finance.view', 'finance.manage'],
                'accounting/trial-balance' => ['trialBalance', 'finance', 'finance.view', 'finance.manage'],
                'wallet/accounts' => ['walletAccounts', 'finance', 'finance.view', 'finance.manage'],
                'wallet/transactions' => ['walletTransactions', 'finance', 'finance.view', 'finance.manage'],
            ];
        }

        return $resources;
    }
}
