<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Validasi input provider admin: extra_config per driver (daftar putih kunci; emails_url GitHub sengaja tidak ada,
 * endpoint dipatok di kode), versi Graph API Facebook, dan berkas kunci .p8 Apple (PEM EC P-256).
 */
final class ProviderConfig
{
    public const P8_MAX_BYTES = 4096;
    public const DEFAULT_GRAPH_VERSION = 'v23.0';

    /** slug => kunci extra_config yang boleh diubah admin */
    private const ALLOWED = [
        'apple' => ['team_id', 'key_id'],
        'facebook' => ['graph_version'],
        'x' => ['token_auth'],
    ];

    /**
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function parseExtra(string $slug, string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        if (strlen($json) > 2000) {
            throw new \InvalidArgumentException('extra_config terlalu panjang.');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException('extra_config harus berupa objek JSON.');
        }
        $allowed = self::ALLOWED[$slug] ?? [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Kunci extra_config tidak diizinkan untuk provider ini: ' . substr((string) $key, 0, 40));
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Nilai extra_config harus string.');
            }
            $ok = match ($key) {
                'team_id', 'key_id' => preg_match('/^[A-Za-z0-9]{6,20}$/', $value) === 1,
                'graph_version' => preg_match('/^v\d{1,2}\.\d{1,2}$/', $value) === 1,
                default => $value === 'basic', // token_auth
            };
            if (!$ok) {
                throw new \InvalidArgumentException("Nilai extra_config.$key tidak valid.");
            }
        }

        return $data;
    }

    /**
     * URL Facebook sesuai versi Graph API.
     *
     * @return array{authorize_url: string, token_url: string, userinfo_url: string}
     */
    public static function facebookUrls(string $version): array
    {
        return [
            'authorize_url' => "https://www.facebook.com/$version/dialog/oauth",
            'token_url' => "https://graph.facebook.com/$version/oauth/access_token",
            'userinfo_url' => "https://graph.facebook.com/$version/me?fields=id,name,email,picture",
        ];
    }

    /**
     * Validasi isi berkas .p8: ukuran, PEM PKCS#8 tanpa data lain, kunci EC kurva prime256v1 (ES256). Kembalikan PEM bersih.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateP8(string $pem): string
    {
        $pem = trim(str_replace("\r\n", "\n", $pem));
        if ($pem === '' || strlen($pem) > self::P8_MAX_BYTES) {
            throw new \InvalidArgumentException('Berkas .p8 kosong atau lebih dari ' . self::P8_MAX_BYTES . ' byte.');
        }
        if (preg_match('/\A-----BEGIN PRIVATE KEY-----\n[A-Za-z0-9+\/=\n]+\n-----END PRIVATE KEY-----\z/', $pem) !== 1) { // gitleaks:allow
            throw new \InvalidArgumentException('Format .p8 harus PEM "PRIVATE KEY" (PKCS#8) tanpa isi lain.');
        }
        $key = @openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new \InvalidArgumentException('Kunci .p8 harus kunci EC kurva P-256 (prime256v1) yang valid.');
        }

        return $pem . "\n";
    }
}
