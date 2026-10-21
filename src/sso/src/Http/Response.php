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
        throw new \LogicException('belum diimplementasi');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        throw new \LogicException('belum diimplementasi');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function withHeader(string $name, string $value): self
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function withCookie(Cookie $cookie): self
    {
        throw new \LogicException('belum diimplementasi');
    }
}
