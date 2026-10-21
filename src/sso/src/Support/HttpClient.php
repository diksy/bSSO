<?php

declare(strict_types=1);

namespace App\Support;

final class HttpClient
{
    /**
     * TLS verify, timeout 5 s, URL harus lolos UrlAllowlist.
     *
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResult
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * Paralel via curl_multi.
     *
     * @param array<string,array{method: string, url: string, headers?: array<string,string>, body?: ?string}> $requests
     * @return array<string,HttpResult> kunci sama dengan $requests
     */
    public function postMany(array $requests, int $timeout = 3): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
