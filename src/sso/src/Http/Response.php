<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string,string> $headers
     * @param list<Cookie> $cookies
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self($status))->withHeader('Location', $url);
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException('Header tidak valid');
        }
        $headers = $this->headers;
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return new self($this->status, $this->body, $headers, $this->cookies);
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function withCookie(Cookie $cookie): self
    {
        return new self($this->status, $this->body, $this->headers, [...$this->cookies, $cookie]);
    }
}
