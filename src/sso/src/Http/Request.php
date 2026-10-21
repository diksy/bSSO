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
        return self::fromArrays($_SERVER, $_GET, $_POST, $_COOKIE);
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param array<string,mixed> $cookie
     */
    public static function fromArrays(array $server, array $get = [], array $post = [], array $cookie = []): self
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        if (
            !isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])
            && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])
        ) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');

        $cookies = [];
        foreach ($cookie as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '0.0.0.0';

        return new self($method, $path, $get, $post, $headers, $cookies, [], $remote);
    }

    /** Alamat langsung peer; header X-Forwarded-For sengaja tidak dipercaya. */
    public function ip(): string
    {
        return $this->remoteAddr;
    }

    /** Ambil dari body lalu query; string saja. */
    public function input(string $key, ?string $default = null): ?string
    {
        foreach ([$this->body, $this->query] as $source) {
            if (isset($source[$key]) && is_string($source[$key])) {
                return $source[$key];
            }
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer +([A-Za-z0-9\-._~+\/]+=*)$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->cookies,
            $attributes,
            $this->remoteAddr,
        );
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
