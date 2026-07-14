<?php

namespace Onekana\Api\Agency;

use Onekana\Api\Support\Env;

final class AgencyApiClient
{
    private ?string $token = null;

    /**
     * @param null|callable(string,string,array,array|null,int):array{status:int,payload:mixed} $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $password,
        private readonly int $timeout = 15,
        private readonly mixed $transport = null,
        private readonly bool $enabled = true,
        private readonly bool $requiresServiceLogin = true,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            rtrim((string) Env::get('AGENCY_API_BASE_URL', ''), '/'),
            (string) Env::get('AGENCY_API_EMAIL', ''),
            (string) Env::get('AGENCY_API_PASSWORD', ''),
            Env::int('AGENCY_API_TIMEOUT', 15),
            null,
            Env::bool('AGENCY_API_ENABLED', false),
            Env::bool('AGENCY_API_AUTH_REQUIRED', true),
        );
    }

    public function profile(): array
    {
        $payload = $this->request('GET', '/auth.php', ['action' => 'profile']);

        return $this->extractSingle($payload, ['user', 'profile', 'data']);
    }

    public function users(array $query = []): array
    {
        $payload = $this->request('GET', '/users.php', [
            ...$this->paginationQuery($query),
            'action' => isset($query['id']) ? 'get_by_id' : 'get_all',
        ]);

        return $this->listResponse($payload, 'users');
    }

    public function user(string|int $id): array
    {
        $payload = $this->request('GET', '/users.php', ['action' => 'get_by_id', 'id' => $id]);

        return ['data' => $this->extractSingle($payload, ['user', 'data'])];
    }

    public function writeUser(string $action, array $data = [], string|int|null $id = null): array
    {
        $this->assertWritesSupported();
        $query = ['action' => $action];
        if ($id !== null) {
            $query['id'] = $id;
        }

        return $this->singleResponse($this->request('POST', '/users.php', $query, $data), ['user', 'data']);
    }

    public function campaigns(array $query = []): array
    {
        $payload = $this->request('GET', '/campaigns.php', [
            ...$this->cleanQuery($query, ['user_id']),
            'action' => isset($query['id']) ? 'get_by_id' : 'get_all',
        ]);

        return $this->listResponse($payload, 'campaigns');
    }

    public function campaign(string|int $id): array
    {
        $payload = $this->request('GET', '/campaigns.php', ['action' => 'get_by_id', 'id' => $id]);

        return ['data' => $this->extractSingle($payload, ['campaign', 'data'])];
    }

    public function writeCampaign(string $action, array $data = [], string|int|null $id = null): array
    {
        $this->assertWritesSupported();
        $query = ['action' => $action];
        if ($id !== null) {
            $query['id'] = $id;
        }

        return $this->singleResponse($this->request('POST', '/campaigns.php', $query, $data), ['campaign', 'data']);
    }

    public function contacts(array $query = []): array
    {
        $payload = $this->request('GET', '/contacts.php', $this->cleanQuery($query, ['id', 'search', 'etape', 'user_id', 'limit', 'offset']));

        return $this->listResponse($payload, 'contacts');
    }

    public function contact(string|int $id): array
    {
        $payload = $this->request('GET', '/contacts.php', ['id' => $id]);

        return ['data' => $this->extractSingle($payload, ['contact', 'data'])];
    }

    public function createContact(array $data): array
    {
        $this->assertWritesSupported();
        return $this->singleResponse($this->request('POST', '/contacts.php', [], $data), ['contact', 'data']);
    }

    public function updateContact(string|int $id, array $data): array
    {
        $this->assertWritesSupported();
        return $this->singleResponse($this->request('PUT', '/contacts.php', ['id' => $id], ['id' => (int) $id, ...$data]), ['contact', 'data']);
    }

    public function deleteContact(string|int $id): array
    {
        $this->assertWritesSupported();
        return $this->singleResponse($this->request('DELETE', '/contacts.php', ['id' => $id], ['id' => (int) $id]), ['contact', 'data']);
    }

    public function geographic(string $entity, array $query = []): array
    {
        $payload = $this->request('GET', '/geographic.php', [
            ...$this->cleanQuery($query, ['id']),
            'entity' => $entity,
            'action' => isset($query['id']) ? 'get_by_id' : 'get_all',
        ]);

        if (array_is_list($payload)) {
            return ['data' => $payload];
        }

        return $this->listResponse($payload, $entity);
    }

    public function writeGeographic(string $entity, string $action, array $data = [], string|int|null $id = null): array
    {
        $this->assertWritesSupported();
        $query = ['entity' => $entity, 'action' => $action];
        if ($id !== null) {
            $query['id'] = $id;
        }

        return $this->singleResponse($this->request('POST', '/geographic.php', $query, $data), ['data']);
    }

    public function summary(): array
    {
        $this->assertConfigured();

        return [
            'data' => [
                'users' => $this->safeCount(fn () => $this->users()),
                'campaigns' => $this->safeCount(fn () => $this->campaigns()),
                'contacts' => $this->safeCount(fn () => $this->contacts()),
                'communes' => $this->safeCount(fn () => $this->geographic('communes')),
                'pointsChauds' => $this->safeCount(fn () => $this->geographic('points_chauds')),
                'trajets' => $this->safeCount(fn () => $this->geographic('trajets')),
            ],
        ];
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null, bool $authenticated = true): mixed
    {
        $this->assertConfigured();

        $token = $authenticated && $this->requiresServiceLogin ? $this->token() : null;
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $url = $this->url($path, $query);
        $cacheKey = hash('sha256', $url);
        if ($method === 'GET' && ($cached = $this->readCache($cacheKey)) !== null) {
            return $cached;
        }
        if ($this->circuitIsOpen()) {
            throw new AgencyApiException(503, 'Donnees temporairement indisponibles.');
        }

        $result = $this->sendWithRetry($method, $url, $headers, $body);
        $status = (int) ($result['status'] ?? 500);
        $payload = $result['payload'] ?? null;

        if ($status < 200 || $status >= 300) {
            throw new AgencyApiException($this->publicStatus($status), 'Données temporairement indisponibles.');
        }

        if (! is_array($payload)) {
            throw new AgencyApiException(502, 'Données temporairement indisponibles.');
        }

        $this->resetCircuit();
        if ($method === 'GET') {
            $this->writeCache($cacheKey, $payload);
        }

        return $payload;
    }

    private function sendWithRetry(string $method, string $url, array $headers, ?array $body): array
    {
        $attempts = $method === 'GET' ? 2 : 1;
        $result = ['status' => 500, 'payload' => null];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $result = $this->send($method, $url, $headers, $body);
                if ((int) ($result['status'] ?? 500) < 500) {
                    return $result;
                }
            } catch (AgencyApiException $exception) {
                if ($attempt === $attempts) {
                    $this->recordFailure();
                    throw $exception;
                }
            }
            usleep(150000 * $attempt);
        }

        $this->recordFailure();
        return $result;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $result = $this->send('POST', $this->url('/auth.php', ['action' => 'login']), [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], [
            'email' => $this->email,
            'password' => $this->password,
        ]);

        $status = (int) ($result['status'] ?? 500);
        $payload = $result['payload'] ?? [];

        if ($status < 200 || $status >= 300 || ! is_array($payload)) {
            throw new AgencyApiException(503, 'Données temporairement indisponibles.');
        }

        $token = (string) ($payload['token'] ?? $payload['access_token'] ?? $payload['data']['token'] ?? $payload['data']['access_token'] ?? '');
        if ($token === '') {
            throw new AgencyApiException(502, 'Données temporairement indisponibles.');
        }

        return $this->token = $token;
    }

    private function send(string $method, string $url, array $headers, ?array $body): array
    {
        if (is_callable($this->transport)) {
            return ($this->transport)($method, $url, $headers, $body, $this->timeout);
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body !== null ? json_encode($body) : '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->statusFromHeaders($http_response_header ?? []);
        if ($raw === false) {
            throw new AgencyApiException(503, 'Données temporairement indisponibles.');
        }

        $decoded = json_decode($raw, true);

        return ['status' => $status, 'payload' => is_array($decoded) ? $decoded : null];
    }

    private function statusFromHeaders(array $headers): int
    {
        $line = $headers[0] ?? '';
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
            return (int) $matches[1];
        }

        return 200;
    }

    private function url(string $path, array $query = []): string
    {
        $query = array_filter($query, fn (mixed $value) => $value !== null && $value !== '');
        $url = $this->baseUrl.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function assertConfigured(): void
    {
        if (! $this->enabled) {
            throw new AgencyApiException(503, 'Integration Agency en attente de validation du fournisseur.');
        }

        if ($this->baseUrl === '') {
            throw new AgencyApiException(503, 'Données temporairement indisponibles.');
        }

        if (Env::get('APP_ENV', 'production') === 'production' && ! $this->requiresServiceLogin) {
            throw new AgencyApiException(503, 'Integration Agency en attente d une authentification de service securisee.');
        }

        if ($this->requiresServiceLogin && ($this->email === '' || $this->password === '')) {
            throw new AgencyApiException(503, 'Données temporairement indisponibles.');
        }
    }

    private function assertWritesSupported(): void
    {
        if (! $this->requiresServiceLogin) {
            throw new AgencyApiException(503, 'Les modifications Agency necessitent une authentification de service.');
        }
    }

    private function listResponse(array $payload, string $listKey): array
    {
        $data = $payload[$listKey] ?? $payload['data'] ?? $payload;
        if (! is_array($data)) {
            $data = [];
        }

        return [
            'data' => array_is_list($data) ? $data : [$data],
            'meta' => array_filter([
                'total' => $payload['total'] ?? null,
                'limit' => $payload['limit'] ?? null,
                'offset' => $payload['offset'] ?? null,
            ], fn (mixed $value) => $value !== null),
        ];
    }

    private function singleResponse(array $payload, array $keys): array
    {
        return ['data' => $this->extractSingle($payload, $keys)];
    }

    private function extractSingle(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    private function paginationQuery(array $query): array
    {
        return $this->cleanQuery($query, ['id', 'search', 'role', 'limit', 'offset']);
    }

    private function cleanQuery(array $query, array $allowed): array
    {
        return array_intersect_key($query, array_flip($allowed));
    }

    private function publicStatus(int $status): int
    {
        return in_array($status, [400, 401, 403, 404, 409, 422], true) ? $status : 502;
    }

    private function safeCount(callable $callback): ?int
    {
        try {
            $result = $callback();
            return count($result['data'] ?? []);
        } catch (AgencyApiException) {
            return null;
        }
    }

    private function cacheDirectory(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'agency';
    }

    private function readCache(string $key): ?array
    {
        $file = $this->cacheDirectory().DIRECTORY_SEPARATOR.$key.'.json';
        if (! is_file($file) || filemtime($file) < time() - Env::int('AGENCY_API_CACHE_TTL', 30)) {
            return null;
        }
        $payload = json_decode((string) @file_get_contents($file), true);
        return is_array($payload) ? $payload : null;
    }

    private function writeCache(string $key, array $payload): void
    {
        $directory = $this->cacheDirectory();
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            return;
        }
        @file_put_contents($directory.DIRECTORY_SEPARATOR.$key.'.json', json_encode($payload), LOCK_EX);
    }

    private function circuitFile(): string
    {
        return $this->cacheDirectory().DIRECTORY_SEPARATOR.'circuit.json';
    }

    private function circuitIsOpen(): bool
    {
        $state = json_decode((string) @file_get_contents($this->circuitFile()), true);
        return is_array($state)
            && (int) ($state['failures'] ?? 0) >= 3
            && (int) ($state['last_failure'] ?? 0) > time() - Env::int('AGENCY_API_CIRCUIT_SECONDS', 60);
    }

    private function recordFailure(): void
    {
        $directory = $this->cacheDirectory();
        if (! is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $state = json_decode((string) @file_get_contents($this->circuitFile()), true);
        $failures = is_array($state) ? (int) ($state['failures'] ?? 0) + 1 : 1;
        @file_put_contents($this->circuitFile(), json_encode(['failures' => $failures, 'last_failure' => time()]), LOCK_EX);
    }

    private function resetCircuit(): void
    {
        if (is_file($this->circuitFile())) {
            @unlink($this->circuitFile());
        }
    }
}
