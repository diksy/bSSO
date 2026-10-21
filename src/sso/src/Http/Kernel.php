<?php

declare(strict_types=1);

namespace App\Http;

use App\Controller\ErrorController;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\Middleware;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Container;
use App\Support\Logger;

final class Kernel
{
    /** Endpoint yang selalu menjawab JSON saat error. */
    private const JSON_PATHS = ['/token', '/introspect', '/revoke', '/userinfo', '/jwks'];

    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $core = fn (Request $r): Response => $this->guarded(
            $r,
            fn (): Response => $this->container->make(Csrf::class)->process($r, fn (Request $r2): Response => $this->dispatch($r2)),
        );

        return $this->container->make(SecurityHeaders::class)->process($request, $core);
    }

    public function emit(Response $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->status);
            foreach ($response->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($response->cookies as $cookie) {
                setcookie($cookie->name, $cookie->value, [
                    'expires' => $cookie->expires,
                    'path' => $cookie->path,
                    'secure' => $cookie->secure,
                    'httponly' => $cookie->httpOnly,
                    'samesite' => $cookie->sameSite,
                ]);
            }
        }
        echo $response->body;
    }

    /** @param callable(): Response $run */
    private function guarded(Request $request, callable $run): Response
    {
        try {
            return $run();
        } catch (HttpException $e) {
            return $this->errorResponse($request, $e->status);
        } catch (\Throwable $e) {
            $this->logger?->exception($e);

            return $this->errorResponse($request, 500);
        }
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        foreach ($match->params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $request = $request->withAttribute('route.params', $match->params);

        [$class, $method] = $match->handler;
        $controller = fn (Request $r): Response => $this->container->make($class)->$method($r);

        $next = $controller;
        foreach (array_reverse($match->middleware) as $entry) {
            $args = [];
            if (is_array($entry)) {
                $mwClass = (string) array_shift($entry);
                $args = array_values($entry);
            } else {
                $mwClass = $entry;
            }
            $inner = $next;
            $next = function (Request $r) use ($mwClass, $args, $inner): Response {
                /** @var class-string $mwClass */
                $middleware = $this->container->make($mwClass, $args);
                if (!$middleware instanceof Middleware) {
                    throw new \LogicException('Bukan middleware: ' . $mwClass);
                }

                return $middleware->process($r, $inner);
            };
        }

        return $next($request);
    }

    private function errorResponse(Request $request, int $status): Response
    {
        $path = rtrim($request->path, '/');
        if (in_array($path, self::JSON_PATHS, true)) {
            $error = match ($status) {
                404 => 'not_found',
                405 => 'method_not_allowed',
                429 => 'temporarily_unavailable',
                default => $status >= 500 ? 'server_error' : 'invalid_request',
            };

            return Response::json(['error' => $error], $status);
        }
        try {
            return $this->container->make(ErrorController::class)->forStatus($status);
        } catch (\Throwable $e) {
            $this->logger?->exception($e);

            return Response::html('<!doctype html><meta charset="utf-8"><title>Kesalahan</title><p>Terjadi kesalahan.</p>', $status >= 400 ? $status : 500);
        }
    }
}
