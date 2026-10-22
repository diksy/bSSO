<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HttpClient;
use App\Support\HttpResult;
use App\Support\UrlAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** UrlAllowlist + HttpClient dengan resolver DNS dan transport palsu (tanpa jaringan nyata). */
final class SsrfTest extends TestCase
{
    private const DNS = [
        'public.example.test' => ['93.184.216.34'],
        'loopback.example.test' => ['127.0.0.1'],
        'meta.example.test' => ['169.254.169.254'],
        'ten.example.test' => ['10.0.0.5'],
        'mixed.example.test' => ['93.184.216.34', '10.0.0.1'],
        'v6loop.example.test' => ['::1'],
        'mapped.example.test' => ['::ffff:127.0.0.1'],
        'ula.example.test' => ['fd00::1'],
        'linklocal6.example.test' => ['fe80::1'],
    ];

    private function allowlist(): UrlAllowlist
    {
        return new UrlAllowlist(static fn (string $h): array => self::DNS[$h] ?? []);
    }

    /** @return iterable<string,array{string}> */
    public static function blockedUrls(): iterable
    {
        $urls = [
            'http://public.example.test/bcl', 'https://127.0.0.1/bcl', 'https://169.254.169.254/latest/meta-data',
            'https://10.1.2.3/x', 'https://172.16.0.1/', 'https://192.168.1.1/', 'https://0.0.0.0/', 'https://[::1]/',
            'https://[::ffff:127.0.0.1]/', 'https://[::2]/', 'https://[fd00::1]/', 'https://localhost/', 'https://foo.localhost/',
            'https://2130706433/', 'https://0x7f.0.0.1/', 'https://user:pw@public.example.test/', 'https://public.example.test:8443/',
            'https://loopback.example.test/', 'https://meta.example.test/', 'https://ten.example.test/', 'https://mixed.example.test/',
            'https://v6loop.example.test/', 'https://mapped.example.test/', 'https://ula.example.test/', 'https://linklocal6.example.test/',
            'https://unknown.example.test/', 'ftp://public.example.test/', 'file:///etc/passwd', 'gopher://public.example.test/',
            "https://public.example.test/a\r\nHost: evil", 'https://internal.internal/', 'https://100.64.0.1/', '', '//public.example.test/',
        ];
        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('blockedUrls')]
    public function testBlocked(string $url): void
    {
        self::assertFalse($this->allowlist()->isAllowed($url));
    }

    public function testPublicHttpsAllowedAndPinned(): void
    {
        $r = $this->allowlist()->resolve('https://public.example.test/bcl');
        self::assertSame(['host' => 'public.example.test', 'port' => 443, 'ip' => '93.184.216.34'], $r);
        self::assertTrue($this->allowlist()->isAllowed('https://public.example.test:443/x?y=1'));
        self::assertTrue($this->allowlist()->isAllowed('https://93.184.216.34/x'));
    }

    public function testBlockedRequestNeverReachesTransport(): void
    {
        $calls = 0;
        $http = new HttpClient($this->allowlist(), static function (array $reqs) use (&$calls): array {
            $calls++;

            return [];
        });
        foreach (['https://127.0.0.1/', 'https://169.254.169.254/', 'https://ten.example.test/', 'http://public.example.test/'] as $url) {
            $res = $http->request('POST', $url, [], 'x=1');
            self::assertSame(0, $res->status);
            self::assertSame('url_blocked', $res->error);
        }
        self::assertSame(0, $calls);
    }

    public function testDnsRebindingResolvedOnceAndPinned(): void
    {
        $lookups = 0;
        $allow = new UrlAllowlist(static function (string $h) use (&$lookups): array {
            // Pertama publik (lolos cek), berikutnya internal (serangan rebinding).
            return ++$lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });
        $seen = null;
        $http = new HttpClient($allow, static function (array $reqs) use (&$seen): array {
            $seen = $reqs;

            return ['r' => new HttpResult(204, '')];
        });
        $res = $http->request('POST', 'https://rebind.example.test/bcl', [], 'a=b');
        self::assertSame(204, $res->status);
        self::assertSame(1, $lookups, 'resolusi hanya sekali, tidak diulang oleh klien');
        self::assertSame('93.184.216.34', $seen['r']['ip']);
        // Opsi curl menyematkan host ke IP yang sudah divalidasi.
        $opts = HttpClient::curlOptions($seen['r']);
        self::assertSame(['rebind.example.test:443:93.184.216.34'], $opts[CURLOPT_RESOLVE]);
    }

    public function testCurlOptionsHardening(): void
    {
        $opts = HttpClient::curlOptions([
            'method' => 'POST', 'url' => 'https://public.example.test/x', 'headers' => ['A' => 'b'], 'body' => 'q=1',
            'host' => 'public.example.test', 'ip' => '93.184.216.34', 'timeout' => 3,
        ]);
        self::assertFalse($opts[CURLOPT_FOLLOWLOCATION], 'redirect tidak diikuti (redirect ke host internal)');
        self::assertSame(0, $opts[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(3, $opts[CURLOPT_TIMEOUT]);
        self::assertSame(3, $opts[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame('', $opts[CURLOPT_PROXY]);
    }

    public function testResponseSizeLimit(): void
    {
        $buf = '';
        self::assertSame(5, HttpClient::accumulate($buf, 'abcde', 8));
        self::assertSame(0, HttpClient::accumulate($buf, 'fghi', 8), 'melewati batas → transfer dibatalkan');
        self::assertSame('abcde', $buf);
    }

    public function testHeaderInjectionAndMethodRejected(): void
    {
        $http = new HttpClient($this->allowlist(), static fn (array $r): array => []);
        self::assertSame('bad_header', $http->request('POST', 'https://public.example.test/', ['X' => "a\r\nB: c"])->error);
        self::assertSame('method_not_allowed', $http->request('DELETE', 'https://public.example.test/')->error);
    }
}
