<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;
use App\Security\Crypto;
use App\Support\Clock;
use App\Support\HttpClient;
use App\Support\Urls;

/**
 * Layanan bersama untuk driver federasi: HTTP keluar (HttpClient + UrlAllowlist, anti-SSRF), dekripsi secret provider,
 * URL callback, dan cache berkas (discovery 24 jam, JWKS). Tidak pernah mencatat secret, kode, atau token ke log;
 * pesan FederationException bersifat generik (tanpa isi respons provider).
 */
final class ProviderContext
{
    /** @var array<string,array{at: int, data: array<string,mixed>}> */
    private array $memory = [];

    public function __construct(
        public readonly HttpClient $http,
        private readonly Crypto $crypto,
        public readonly Clock $clock,
        private readonly Urls $urls,
        private readonly ?string $cacheDir = null,
    ) {
    }

    public function callbackUrl(IdentityProvider $config): string
    {
        if (!$this->urls->available()) {
            throw new FederationException('base_url belum dikonfigurasi');
        }

        return $this->urls->abs('/federation/' . rawurlencode($config->slug) . '/callback');
    }

    /**
     * URL otorisasi untuk redirect browser: wajib https tanpa userinfo/kontrol karakter. Param tambahan di-encode RFC 3986.
     *
     * @param array<string,string> $params
     */
    public static function authorizeUrl(string $endpoint, array $params): string
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint) === 1
        ) {
            throw new FederationException('Endpoint otorisasi provider tidak valid');
        }

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Secret klien terdekripsi (kosong untuk klien publik). */
    public function clientSecret(IdentityProvider $config): string
    {
        if ($config->clientSecretEnc === null) {
            return '';
        }
        try {
            return $this->crypto->decrypt($config->clientSecretEnc);
        } catch (\Throwable) {
            throw new FederationException('Secret provider tidak dapat dibaca');
        }
    }

    /**
     * POST form ke token endpoint. Autentikasi klien: body (default) atau Basic bila extra_config.token_auth = basic.
     *
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    public function tokenRequest(IdentityProvider $config, string $url, array $fields, string $secret): array
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if (($config->extraConfig['token_auth'] ?? null) === 'basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode($config->clientId) . ':' . rawurlencode($secret));
        } else {
            $fields['client_id'] = $config->clientId;
            if ($secret !== '') {
                $fields['client_secret'] = $secret;
            }
        }

        return $this->decode($this->http->request('POST', $url, $headers, http_build_query($fields, '', '&', PHP_QUERY_RFC3986)), true);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->decode($this->http->request('GET', $url, $headers + ['Accept' => 'application/json']), false);
    }

    /**
     * Cache berumur $ttl detik (berkas di cacheDir bila ada, selain itu memori proses).
     *
     * @param callable(): array<string,mixed> $loader
     * @return array<string,mixed>
     */
    public function cached(string $key, int $ttl, callable $loader, bool $refresh = false): array
    {
        $id = hash('sha256', $key);
        $now = $this->clock->now()->getTimestamp();
        if (!$refresh) {
            $hit = $this->memory[$id] ?? $this->readFile($id);
            if ($hit !== null && $hit['at'] + $ttl > $now) {
                return $hit['data'];
            }
        }
        $data = $loader();
        $this->memory[$id] = ['at' => $now, 'data' => $data];
        $this->writeFile($id, $this->memory[$id]);

        return $data;
    }

    /** @return array<string,mixed> */
    private function decode(\App\Support\HttpResult $result, bool $allowForm): array
    {
        if ($result->error !== null) {
            throw new FederationException('Provider tidak dapat dihubungi');
        }
        if ($result->status < 200 || $result->status >= 300) {
            throw new FederationException('Provider menolak permintaan');
        }
        $data = json_decode($result->body, true);
        if (!is_array($data) && $allowForm) {
            parse_str($result->body, $data); // beberapa provider lama membalas application/x-www-form-urlencoded
        }
        if (!is_array($data) || $data === []) {
            throw new FederationException('Respons provider tidak valid');
        }

        return $data;
    }

    /** @return ?array{at: int, data: array<string,mixed>} */
    private function readFile(string $id): ?array
    {
        if ($this->cacheDir === null) {
            return null;
        }
        $file = $this->cacheDir . '/fed-' . $id . '.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || !is_int($json['at'] ?? null) || !is_array($json['data'] ?? null)) {
            return null;
        }

        return ['at' => $json['at'], 'data' => $json['data']];
    }

    /** @param array{at: int, data: array<string,mixed>} $entry */
    private function writeFile(string $id, array $entry): void
    {
        if ($this->cacheDir === null) {
            return;
        }
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            return;
        }
        $tmp = tempnam($this->cacheDir, 'fed');
        if ($tmp === false) {
            return;
        }
        chmod($tmp, 0600);
        if (file_put_contents($tmp, json_encode($entry)) === false || !rename($tmp, $this->cacheDir . '/fed-' . $id . '.json')) {
            @unlink($tmp);
        }
    }
}
