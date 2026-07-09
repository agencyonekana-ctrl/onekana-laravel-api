<?php

namespace Onekana\Api\Http;

final class Request
{
    private ?array $json = null;
    private array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly string $ip,
    ) {}

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/'.trim($uri, '/'),
            $_GET,
            $_POST,
            $_FILES,
            array_change_key_case($headers, CASE_LOWER),
            file_get_contents('php://input') ?: '',
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        );
    }

    public static function fake(string $method, string $path, array $body = [], array $headers = [], array $files = [], array $query = [], string $ip = '127.0.0.1'): self
    {
        return new self(strtoupper($method), '/'.trim($path, '/'), $query, $body, $files, array_change_key_case($headers, CASE_LOWER), json_encode($body) ?: '', $ip);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = array_merge($this->json(), $this->body);

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? $this->header('authorization');
        if (! $header || ! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    private function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $decoded = json_decode($this->rawBody, true);
        $this->json = is_array($decoded) ? $decoded : [];

        return $this->json;
    }
}
