<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Klien HTTP keluar. Setiap URL lolos UrlAllowlist (https, bukan IP internal); IP hasil resolusi disematkan
 * (CURLOPT_RESOLVE) sehingga DNS rebinding tidak mengubah tujuan. Redirect TIDAK diikuti. TLS verify wajib,
 * timeout 5 s (request) / 3 s (postMany), respons dibatasi 1 MiB.
 */
final class HttpClient
{
    public const MAX_BYTES = 1048576;
    private const METHODS = ['GET', 'POST'];

    /**
     * @param ?\Closure(array<string,array<string,mixed>>): array<string,HttpResult> $transport pengganti curl (tes);
     *        menerima permintaan tervalidasi (method, url, headers, body, host, ip, timeout)
     */
    public function __construct(
        private readonly UrlAllowlist $allowlist = new UrlAllowlist(),
        private readonly ?\Closure $transport = null,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
    }

    /** @param array<string,string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResult
    {
        return $this->postMany(['r' => ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body]], 5)['r'];
    }

    /**
     * Paralel via curl_multi.
     *
     * @param array<string,array{method: string, url: string, headers?: array<string,string>, body?: ?string}> $requests
     * @return array<string,HttpResult> kunci sama dengan $requests
     */
    public function postMany(array $requests, int $timeout = 3): array
    {
        $timeout = max(1, min($timeout, 10));
        $results = [];
        $valid = [];
        foreach ($requests as $key => $req) {
            $prepared = $this->prepare($req, $timeout);
            if ($prepared instanceof HttpResult) {
                $results[$key] = $prepared;
            } else {
                $valid[$key] = $prepared;
            }
        }
        if ($valid !== []) {
            $done = $this->transport !== null ? ($this->transport)($valid) : $this->curlMulti($valid);
            foreach (array_keys($valid) as $key) {
                $results[$key] = $done[$key] ?? new HttpResult(0, '', [], 'no_result');
            }
        }
        // Urutan sama dengan input.
        $ordered = [];
        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * Opsi curl tetap (diuji): tanpa redirect, hanya https, TLS verify, tanpa proxy, IP tersemat.
     *
     * @param array<string,mixed> $p hasil prepare()
     * @return array<int,mixed>
     */
    public static function curlOptions(array $p): array
    {
        $headerLines = [];
        foreach ($p['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $opts = [
            CURLOPT_URL => $p['url'],
            CURLOPT_CUSTOMREQUEST => $p['method'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$p['host'] . ':443:' . $p['ip']],
            CURLOPT_CONNECTTIMEOUT => $p['timeout'],
            CURLOPT_TIMEOUT => $p['timeout'],
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'BikininSSO/1.0',
        ];
        if ($p['body'] !== null) {
            $opts[CURLOPT_POSTFIELDS] = $p['body'];
        }

        return $opts;
    }

    /** Tambah potongan ke buffer; 0 (curl membatalkan transfer) bila total melewati batas ukuran. */
    public static function accumulate(string &$buffer, string $chunk, int $max): int
    {
        if (strlen($buffer) + strlen($chunk) > $max) {
            return 0;
        }
        $buffer .= $chunk;

        return strlen($chunk) === 0 ? 1 : strlen($chunk);
    }

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>|HttpResult
     */
    private function prepare(array $req, int $timeout): array|HttpResult
    {
        $method = strtoupper((string) ($req['method'] ?? ''));
        $url = (string) ($req['url'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            return new HttpResult(0, '', [], 'method_not_allowed');
        }
        $target = $this->allowlist->resolve($url);
        if ($target === null) {
            return new HttpResult(0, '', [], 'url_blocked');
        }
        $headers = [];
        foreach ((array) ($req['headers'] ?? []) as $name => $value) {
            if (
                !is_string($name) || !is_string($value) || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || preg_match('/[\r\n\x00]/', $value) === 1
            ) {
                return new HttpResult(0, '', [], 'bad_header');
            }
            $headers[$name] = $value;
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => isset($req['body']) ? (string) $req['body'] : null,
            'host' => $target['host'],
            'ip' => $target['ip'],
            'timeout' => $timeout,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $valid
     * @return array<string,HttpResult>
     */
    private function curlMulti(array $valid): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $bodies = [];
        $heads = [];
        $tooBig = [];
        foreach ($valid as $key => $p) {
            $ch = curl_init();
            $bodies[$key] = '';
            $heads[$key] = [];
            $tooBig[$key] = false;
            $max = $this->maxBytes;
            curl_setopt_array($ch, self::curlOptions($p) + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$bodies, &$tooBig, $key, $max): int {
                    $taken = self::accumulate($bodies[$key], $chunk, $max);
                    $tooBig[$key] = $tooBig[$key] || $taken === 0;

                    return $taken;
                },
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$heads, $key): int {
                    if (count($heads[$key]) < 50 && str_contains($line, ':')) {
                        [$n, $v] = explode(':', $line, 2);
                        $heads[$key][strtolower(trim($n))] = mb_substr(trim($v), 0, 512);
                    }

                    return strlen($line);
                },
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        $running = 0;
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0 && curl_multi_select($multi, 0.5) === -1) {
                usleep(20000);
            }
        } while ($running > 0);

        $results = [];
        foreach ($handles as $key => $ch) {
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = null;
            if ($tooBig[$key]) {
                $error = 'response_too_large';
            } elseif ($errno !== 0) {
                $error = 'curl_' . $errno;
            }
            $results[$key] = new HttpResult($error === null ? $status : 0, $error === null ? $bodies[$key] : '', $heads[$key], $error);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $results;
    }
}
