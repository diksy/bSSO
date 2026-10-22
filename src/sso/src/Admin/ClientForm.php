<?php

declare(strict_types=1);

namespace App\Admin;

use App\OAuth\ClientCreator;
use App\OAuth\Scopes;
use App\Support\UrlAllowlist;

/**
 * Validasi form klien admin (tambah/ubah). redirect_uri: https, tanpa fragment/wildcard/userinfo, exact;
 * http://localhost hanya bila $allowLocalhost (mode debug/dev). backchannel_logout_uri wajib lolos UrlAllowlist (anti-SSRF).
 */
final class ClientForm
{
    public const MAX_URIS = 20;
    private const GRANTS = ['authorization_code', 'refresh_token', 'client_credentials'];

    public function __construct(private readonly UrlAllowlist $allowlist = new UrlAllowlist(), private readonly bool $allowLocalhost = false)
    {
    }

    /**
     * @param array<string,mixed> $body
     * @return array{name: string, type: string, grants: list<string>, scopes: string, accessTtl: int, refreshTtl: int, login: list<string>, logout: list<string>, backchannel: ?string}
     * @throws \InvalidArgumentException pesan aman ditampilkan
     */
    public function parse(array $body, ?string $fixedType = null): array
    {
        $name = trim(self::str($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Nama wajib diisi (maks. 100 karakter).');
        }
        $type = $fixedType ?? self::str($body['type'] ?? '');
        if (!in_array($type, ['confidential', 'public'], true)) {
            throw new \InvalidArgumentException('Tipe klien harus confidential atau public.');
        }
        $grants = [];
        foreach (is_array($body['grants'] ?? null) ? $body['grants'] : [] as $g) {
            if (!is_string($g) || !in_array($g, self::GRANTS, true)) {
                throw new \InvalidArgumentException('Grant tidak dikenal.');
            }
            $grants[$g] = $g;
        }
        $grants = array_values($grants);
        if ($grants === []) {
            throw new \InvalidArgumentException('Pilih minimal satu grant.');
        }
        if ($type === 'public' && in_array('client_credentials', $grants, true)) {
            throw new \InvalidArgumentException('Klien public tidak boleh memakai client_credentials.');
        }
        if (in_array('refresh_token', $grants, true) && !in_array('authorization_code', $grants, true)) {
            throw new \InvalidArgumentException('refresh_token memerlukan authorization_code.');
        }
        $scopeList = Scopes::split(self::str($body['scopes'] ?? ''));
        if ($scopeList === [] || strlen(implode(' ', $scopeList)) > 255) {
            throw new \InvalidArgumentException('Scope tidak valid.');
        }
        $access = self::int($body['access_ttl'] ?? '900');
        $refresh = self::int($body['refresh_ttl'] ?? '2592000');
        if ($access < 60 || $access > 86400 || $refresh < 3600 || $refresh > 31536000) {
            throw new \InvalidArgumentException('TTL di luar rentang (access 60-86400 detik, refresh 3600-31536000 detik).');
        }
        $login = self::lines($body['redirect_login'] ?? '');
        $logout = self::lines($body['redirect_logout'] ?? '');
        if ($login === [] && in_array('authorization_code', $grants, true)) {
            throw new \InvalidArgumentException('Minimal satu redirect URI login.');
        }
        foreach ([...$login, ...$logout] as $uri) {
            $this->assertRedirect($uri);
        }
        $bc = trim(self::str($body['backchannel'] ?? ''));
        if ($bc !== '' && !$this->allowlist->isAllowed($bc)) {
            throw new \InvalidArgumentException('Back-channel logout URI harus https (port 443) ke host publik yang dapat di-resolve.');
        }

        return [
            'name' => $name, 'type' => $type, 'grants' => $grants, 'scopes' => implode(' ', $scopeList),
            'accessTtl' => $access, 'refreshTtl' => $refresh, 'login' => $login, 'logout' => $logout,
            'backchannel' => $bc === '' ? null : $bc,
        ];
    }

    /** @throws \InvalidArgumentException */
    public function assertRedirect(string $uri): void
    {
        $parts = parse_url($uri);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($scheme === 'http' && !($local && $this->allowLocalhost)) {
            throw new \InvalidArgumentException('Redirect URI wajib https (http://localhost hanya di mode pengembangan): ' . substr($uri, 0, 80));
        }
        ClientCreator::assertUri($uri);
    }

    /** @return list<string> */
    private static function lines(mixed $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', self::str($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[$line] = $line;
            }
        }
        if (count($out) > self::MAX_URIS) {
            throw new \InvalidArgumentException('Maksimal ' . self::MAX_URIS . ' URI per daftar.');
        }

        return array_values($out);
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function int(mixed $v): int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/', trim($v)) === 1 ? (int) trim($v) : -1;
    }
}
