<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:array{0:class-string,1:string}}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $req): ?Response
    {
        foreach ($this->routes as [$method, $regex, $handler]) {
            if ($method === $req->method && preg_match($regex, $req->path, $m) === 1) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $params = array_map('rawurldecode', $params);
                return (new $handler[0]())->{$handler[1]}($req, $params);
            }
        }
        return null;
    }
}
