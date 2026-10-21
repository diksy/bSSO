<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string|array<int,mixed>>}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler [Controller::class, 'metode'], metode (Request): Response
     * @param list<string|array<int,mixed>> $middleware   class-string, atau [class-string, ...argumen konstruktor]
     */
    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}|[^{]+/',
            static fn (array $m): string => isset($m[1]) ? '(?P<' . $m[1] . '>[^/]+)' : preg_quote($m[0], '#'),
            $path,
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @throws HttpException 404 jika tak ada rute, 405 jika method tak cocok */
    public function match(Request $request): RouteMatch
    {
        $path = $request->path === '/' ? '/' : rtrim($request->path, '/');
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return new RouteMatch($route['handler'], $params, $route['middleware']);
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }
}
