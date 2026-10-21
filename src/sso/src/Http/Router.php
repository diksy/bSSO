<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /**
     * @param array{0: class-string, 1: string} $handler [Controller::class, 'metode'], metode (Request): Response
     * @param list<string|array<int,mixed>> $middleware   class-string, atau [class-string, ...argumen konstruktor]
     */
    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @throws HttpException 404 jika tak ada rute, 405 jika method tak cocok */
    public function match(Request $request): RouteMatch
    {
        throw new \LogicException('belum diimplementasi');
    }
}
