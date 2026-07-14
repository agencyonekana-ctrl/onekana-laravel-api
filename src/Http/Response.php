<?php

namespace Onekana\Api\Http;

use Onekana\Api\Support\Json;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $payload = [],
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {}

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, $payload, ['Content-Type' => 'application/json; charset=utf-8', ...$headers]);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public static function binary(string $body, string $mimeType, string $filename): self
    {
        $safeName = str_replace(["\r", "\n", '"'], '', basename($filename));
        return new self(200, [], [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store, max-age=0',
        ], $body);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->payload, [...$this->headers, ...$headers], $this->body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header($key.': '.$value);
        }

        if ($this->body !== null) {
            echo $this->body;
        } elseif ($this->status !== 204) {
            echo Json::encode($this->payload);
        }
    }
}
