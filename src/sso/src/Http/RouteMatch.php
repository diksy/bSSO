<?php

declare(strict_types=1);

namespace App\Http;

final class RouteMatch
{
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string,string> $params
     * @param list<string|array<int,mixed>> $middleware
     */
    public function __construct(
        public readonly array $handler,
        public readonly array $params = [],
        public readonly array $middleware = [],
    ) {
    }
}
