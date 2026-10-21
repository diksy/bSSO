<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\HttpResult;
use Firebase\JWT\JWT;

/** Provider eksternal palsu (tanpa jaringan): discovery, JWKS, token endpoint, userinfo; merekam permintaan. */
final class FakeIdp
{
    public const ISS = 'https://idp.example.test';
    public const DISCOVERY = 'https://idp.example.test/.well-known/openid-configuration';
    public const JWKS = 'https://idp.example.test/jwks';
    public const TOKEN = 'https://idp.example.test/token';
    public const AUTHORIZE = 'https://idp.example.test/authorize';
    public const USERINFO = 'https://idp.example.test/userinfo';

    public string $kid = 'k1';
    /** @var list<array<string,string>> */
    public array $jwksKeys = [];
    public int $jwksFetches = 0;
    public int $discoveryFetches = 0;
    public ?string $idToken = null;
    /** @var array<string,mixed> */
    public array $userinfo = [];
    /** @var list<array<string,mixed>> */
    public array $tokenRequests = [];
    /** @var list<string> */
    public array $urls = [];
    private \OpenSSLAsymmetricKey $key;
    /** @var array<string,\OpenSSLAsymmetricKey> */
    private static array $keys = [];

    public function __construct(public string $keyName = 'a')
    {
        $this->key = self::$keys[$keyName] ??= self::generate();
        $this->jwksKeys = [$this->jwk($this->kid, $this->key)];
    }

    private static function generate(): \OpenSSLAsymmetricKey
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($k === false) {
            throw new \RuntimeException('openssl_pkey_new gagal');
        }

        return $k;
    }

    /** @return array<string,string> */
    public function jwk(string $kid, \OpenSSLAsymmetricKey $key): array
    {
        $d = openssl_pkey_get_details($key);

        return [
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => JWT::urlsafeB64Encode($d['rsa']['n']), 'e' => JWT::urlsafeB64Encode($d['rsa']['e']),
        ];
    }

    /** Ganti kunci tanda tangan IdP (rotasi): kid baru, JWKS belum memuatnya sampai $publish. */
    public function rotateTo(string $kid, string $keyName, bool $publish): void
    {
        $this->kid = $kid;
        $this->key = self::$keys[$keyName] ??= self::generate();
        if ($publish) {
            $this->jwksKeys = [$this->jwk($kid, $this->key)];
        }
    }

    /**
     * @param array<string,mixed> $override null menghapus klaim
     */
    public function makeIdToken(string $nonce, array $override = [], string $clientId = 'cid'): string
    {
        $now = 1772359200; // 2026-03-01 10:00:00 UTC (FrozenClock Stack)
        $claims = [
            'iss' => self::ISS, 'aud' => $clientId, 'sub' => 'idp-user-1', 'iat' => $now - 10, 'exp' => $now + 600,
            'nonce' => $nonce, 'email' => 'ani@example.test', 'email_verified' => true, 'name' => 'Ani IdP',
        ];
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($claims[$k]);
            } else {
                $claims[$k] = $v;
            }
        }

        return JWT::encode($claims, $this->key, 'RS256', $this->kid);
    }

    /** @param array<string,mixed> $claims */
    public static function unsigned(array $claims): string
    {
        $h = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $p = JWT::urlsafeB64Encode((string) json_encode($claims));

        return $h . '.' . $p . '.';
    }

    /** @return \Closure(array<string,array<string,mixed>>): array<string,HttpResult> */
    public function transport(): \Closure
    {
        return function (array $reqs): array {
            $out = [];
            foreach ($reqs as $key => $r) {
                $url = (string) $r['url'];
                $this->urls[] = $url;
                $out[$key] = $this->answer((string) $r['method'], $url, $r);
            }

            return $out;
        };
    }

    /** @param array<string,mixed> $r */
    private function answer(string $method, string $url, array $r): HttpResult
    {
        $json = static fn (array $d): HttpResult => new HttpResult(200, (string) json_encode($d));
        switch ($url) {
            case self::DISCOVERY:
                $this->discoveryFetches++;

                return $json([
                    'issuer' => self::ISS, 'authorization_endpoint' => self::AUTHORIZE, 'token_endpoint' => self::TOKEN,
                    'jwks_uri' => self::JWKS,
                ]);
            case self::JWKS:
                $this->jwksFetches++;

                return $json(['keys' => $this->jwksKeys]);
            case self::TOKEN:
                parse_str((string) $r['body'], $body);
                $this->tokenRequests[] = ['body' => $body, 'headers' => $r['headers']];

                return $json(['access_token' => 'at-123', 'id_token' => $this->idToken ?? '', 'token_type' => 'Bearer']);
            case self::USERINFO:
                return $this->userinfo === [] ? new HttpResult(401, '') : $json($this->userinfo);
        }

        return new HttpResult(404, '');
    }
}
