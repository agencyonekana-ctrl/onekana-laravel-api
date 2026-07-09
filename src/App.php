<?php

namespace Onekana\Api;

use Onekana\Api\Auth\AuthManager;
use Onekana\Api\Auth\JwtService;
use Onekana\Api\Auth\RateLimiter;
use Onekana\Api\Auth\TokenRevocationStore;
use Onekana\Api\Controllers\AuthController;
use Onekana\Api\Controllers\ResourceController;
use Onekana\Api\Controllers\SystemController;
use Onekana\Api\Controllers\UploadController;
use Onekana\Api\Database\Connection;
use Onekana\Api\Http\HttpException;
use Onekana\Api\Http\Request;
use Onekana\Api\Http\Response;
use Onekana\Api\Http\Router;
use Onekana\Api\Repositories\ResourceRepository;
use Onekana\Api\Repositories\UserRepository;
use Onekana\Api\Support\Env;
use PDO;
use Throwable;

final class App
{
    private Router $router;
    private AuthManager $auth;
    private UserRepository $users;

    public function __construct(private readonly string $basePath, ?PDO $pdo = null)
    {
        Env::load($basePath);
        if ($pdo) {
            Connection::reset($pdo);
        }

        $pdo = Connection::pdo();
        $this->router = new Router();
        $this->users = new UserRepository($pdo);
        $jwt = new JwtService();
        $revocations = new TokenRevocationStore($pdo);
        $this->auth = new AuthManager($jwt, $revocations, $this->users);
        $resources = new ResourceRepository($pdo);

        $authController = new AuthController($this->auth, $jwt, new RateLimiter($pdo), $this->users);
        $resourceController = new ResourceController($resources);
        $systemController = new SystemController($resources);
        $uploadController = new UploadController($basePath);

        $this->routes($authController, $resourceController, $systemController, $uploadController);
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                return $this->decorate(Response::noContent(), $request);
            }

            [$route, $params] = $this->router->match($request);
            foreach ($route['middleware'] as $middleware) {
                $this->applyMiddleware($middleware, $request);
            }

            $response = $route['handler']($request, $params);

            return $this->decorate($response, $request);
        } catch (HttpException $exception) {
            $payload = ['message' => $exception->getMessage()];
            if ($exception->errors !== []) {
                $payload['errors'] = $exception->errors;
            }

            return $this->decorate(Response::json($payload, $exception->status), $request);
        } catch (Throwable $exception) {
            $debug = Env::bool('APP_DEBUG', false);

            return $this->decorate(Response::json([
                'message' => $debug ? $exception->getMessage() : 'Server error.',
            ], 500), $request);
        }
    }

    private function routes(AuthController $auth, ResourceController $resources, SystemController $system, UploadController $upload): void
    {
        $this->router->add('POST', '/api/auth/login', fn (Request $request) => $auth->login($request));
        $this->router->add('GET', '/api/auth/me', fn (Request $request) => $auth->me($request), ['auth']);
        $this->router->add('POST', '/api/auth/refresh', fn (Request $request) => $auth->refresh($request), ['auth']);
        $this->router->add('POST', '/api/auth/logout', fn (Request $request) => $auth->logout($request), ['auth']);

        $this->router->add('GET', '/api/system/summary', fn (Request $request) => $system->summary($request), ['system']);
        $this->router->add('GET', '/api/system/campaigns', fn (Request $request) => $system->list($request, 'campaigns'), ['system']);
        $this->router->add('GET', '/api/system/contact-messages', fn (Request $request) => $system->list($request, 'contactMessages'), ['system']);
        $this->router->add('GET', '/api/system/ooh/availability', fn (Request $request) => $system->availability($request), ['system']);
        $this->router->add('GET', '/api/system/notifications', fn (Request $request) => $system->list($request, 'notifications'), ['system']);
        $this->router->add('GET', '/api/system/roadmap', fn (Request $request) => $system->list($request, 'roadmap'), ['system']);
        $this->router->add('GET', '/api/system/finance-summary', fn (Request $request) => $system->financeSummary($request), ['system']);

        $this->router->add('POST', '/api/uploads', fn (Request $request) => $upload->store($request), ['auth', 'module:sales', 'permission:sales.view']);
        $this->router->add('PUT', '/api/notifications/{id}/read', fn (Request $request, array $params) => $resources->markNotificationRead($request, (int) $params['id']), ['auth', 'module:dashboard', 'permission:dashboard.view']);

        foreach ($this->resourceMap() as $uri => [$resource, $module, $permission]) {
            $middleware = ['auth', "module:{$module}", "permission:{$permission}"];
            $this->router->add('GET', "/api/{$uri}", fn (Request $request) => $resources->index($request, $resource), $middleware);
            $this->router->add('POST', "/api/{$uri}", fn (Request $request) => $resources->store($request, $resource), $middleware);
            $this->router->add('GET', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->show($request, $resource, (int) $params['id']), $middleware);
            $this->router->add('PUT', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->update($request, $resource, (int) $params['id']), $middleware);
            $this->router->add('PATCH', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->update($request, $resource, (int) $params['id']), $middleware);
            $this->router->add('DELETE', "/api/{$uri}/{id}", fn (Request $request, array $params) => $resources->destroy($request, $resource, (int) $params['id']), $middleware);
        }
    }

    private function applyMiddleware(string $middleware, Request $request): void
    {
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
        ];

        if ($origin && $this->isAllowedOrigin($origin)) {
            $cors['Access-Control-Allow-Origin'] = $origin;
            $cors['Vary'] = 'Origin';
        }

        return $response->withHeaders([
            ...$cors,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
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
        return [
            'documents' => ['documents', 'sales', 'sales.view'],
            'reservations' => ['reservations', 'sales', 'sales.view'],
            'reservation-types' => ['reservationTypes', 'sales', 'sales.view'],
            'packs' => ['packsCommerciaux', 'sales', 'sales.view'],
            'options' => ['optionsComplementaires', 'sales', 'sales.view'],
            'contact-messages' => ['contactMessages', 'sales', 'sales.view'],
            'campaign-types' => ['campaignTypes', 'sales', 'sales.view'],
            'campaign-prices' => ['campaignPrices', 'sales', 'sales.view'],
            'communes' => ['communes', 'sales', 'sales.view'],
            'quartiers' => ['quartiers', 'sales', 'sales.view'],
            'points-chauds' => ['pointsChauds', 'sales', 'sales.view'],
            'transport-routes' => ['transportRoutes', 'sales', 'sales.view'],
            'route-coordinates' => ['routeCoordinates', 'sales', 'sales.view'],
            'agenda-events' => ['agendaEvents', 'sales', 'sales.view'],
            'ooh/campaigns' => ['oohCampaigns', 'sales', 'sales.view'],
            'ooh/campaign-lines' => ['oohCampaignLines', 'sales', 'sales.view'],
            'ooh/sites' => ['oohSites', 'inventory', 'inventory.view'],
            'ooh/supports' => ['oohSupports', 'inventory', 'inventory.view'],
            'ooh/emplacements' => ['oohEmplacements', 'inventory', 'inventory.view'],
            'ooh/assets' => ['oohAssets', 'inventory', 'inventory.view'],
            'ooh/pricing-rules' => ['oohPricingRules', 'inventory', 'inventory.view'],
            'ooh/tasks' => ['oohTasks', 'inventory', 'inventory.view'],
            'employees' => ['employees', 'team', 'team.view'],
            'schedules' => ['schedules', 'team', 'team.view'],
            'materials' => ['materials', 'team', 'team.view'],
            'material-types' => ['materialTypes', 'team', 'team.view'],
            'job-titles' => ['jobTitles', 'team', 'team.view'],
            'employee-statuses' => ['employeeStatuses', 'team', 'team.view'],
            'departments' => ['departments', 'administration', 'administration.view'],
            'notifications' => ['notifications', 'dashboard', 'dashboard.view'],
            'roadmap' => ['roadmap', 'dashboard', 'dashboard.view'],
            'accounting/accounts' => ['accountingAccounts', 'finance', 'finance.view'],
            'accounting/journals' => ['accountingJournals', 'finance', 'finance.view'],
            'accounting/entries' => ['accountingEntries', 'finance', 'finance.view'],
            'accounting/trial-balance' => ['trialBalance', 'finance', 'finance.view'],
            'wallet/accounts' => ['walletAccounts', 'finance', 'finance.view'],
            'wallet/transactions' => ['walletTransactions', 'finance', 'finance.view'],
            'invoices' => ['invoices', 'finance', 'finance.view'],
            'payments' => ['payments', 'finance', 'finance.view'],
        ];
    }
}
