<?php

namespace Onekana\Api\Http;

use Onekana\Api\Support\Json;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $payload = [],
        public readonly array $headers = [],
    ) {}

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, $payload, ['Content-Type' => 'application/json; charset=utf-8', ...$headers]);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->payload, [...$this->headers, ...$headers]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header($key.': '.$value);
        }

        if ($this->status !== 204) {
            echo Json::encode($this->payload);
        }
    }
}
