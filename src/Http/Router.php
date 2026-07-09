<?php

namespace Onekana\Api\Http;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '/'.trim($pattern, '/'),
            'regex' => $this->regex($pattern),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function match(Request $request): array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            if (preg_match($route['regex'], $request->path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return [$route, $params];
            }
        }

        throw new HttpException(404, 'Route not found.');
    }

    private function regex(string $pattern): string
    {
        $pattern = '/'.trim($pattern, '/');
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[0-9]+)', $pattern);

        return '#^'.$regex.'$#';
    }
}
