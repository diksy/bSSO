<?php

declare(strict_types=1);

namespace App\Http;

final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $expires = 0,
        public readonly string $path = '/',
        public readonly bool $secure = true,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
    }
}
