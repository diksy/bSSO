<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers nama header huruf kecil
     * @param array<string,string> $cookies
     * @param array<string,mixed> $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        private readonly array $attributes = [],
        private readonly string $remoteAddr = '0.0.0.0',
    ) {
    }

    public static function fromGlobals(): self
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function ip(): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Ambil dari body lalu query; string saja. */
    public function input(string $key, ?string $default = null): ?string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function bearerToken(): ?string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function withAttribute(string $name, mixed $value): self
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function attribute(string $name): mixed
    {
        throw new \LogicException('belum diimplementasi');
    }
}
