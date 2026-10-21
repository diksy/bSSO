<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;
use App\Security\PasswordHasher;
use App\Support\Clock;
use App\Support\Database;

/** Pembuatan klien OAuth (dipakai bin/create-client.php). Secret hanya dikembalikan sekali; DB menyimpan password_hash. */
final class ClientCreator
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientRedirectUriRepository $uris,
        private readonly PasswordHasher $hasher,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $redirectUris
     * @param list<string> $postLogoutUris
     * @param list<string>|null $grants
     * @return array{client: Client, secret: ?string}
     * @throws \InvalidArgumentException
     */
    public function create(
        string $name,
        string $type,
        array $redirectUris,
        array $postLogoutUris = [],
        ?array $grants = null,
        string $scopes = 'openid profile email offline_access',
        ?string $backchannelUri = null,
        int $accessTtl = 900,
        int $refreshTtl = 2592000,
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Nama wajib (maks. 100 karakter)');
        }
        if (!in_array($type, ['confidential', 'public'], true)) {
            throw new \InvalidArgumentException('type harus confidential atau public');
        }
        if ($redirectUris === []) {
            throw new \InvalidArgumentException('Minimal satu redirect URI');
        }
        foreach ([...$redirectUris, ...$postLogoutUris] as $uri) {
            self::assertUri($uri);
        }
        if ($backchannelUri !== null && !str_starts_with($backchannelUri, 'https://')) {
            throw new \InvalidArgumentException('backchannel_logout_uri harus https');
        }
        $grants ??= $type === 'confidential'
            ? ['authorization_code', 'refresh_token', 'client_credentials']
            : ['authorization_code', 'refresh_token'];
        foreach ($grants as $g) {
            if (!in_array($g, ['authorization_code', 'refresh_token', 'client_credentials'], true)) {
                throw new \InvalidArgumentException('Grant tidak dikenal: ' . $g);
            }
        }
        if ($type === 'public' && in_array('client_credentials', $grants, true)) {
            throw new \InvalidArgumentException('Public client tidak boleh client_credentials');
        }
        $scopeList = Scopes::split($scopes);
        if ($scopeList === []) {
            throw new \InvalidArgumentException('Scope tidak valid');
        }
        if ($accessTtl < 60 || $accessTtl > 86400 || $refreshTtl < 3600 || $refreshTtl > 31536000) {
            throw new \InvalidArgumentException('TTL di luar rentang');
        }

        $secret = $type === 'confidential' ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=') : null;
        $clientId = 'cl_' . bin2hex(random_bytes(12));

        /** @var Client $client */
        $client = $this->db->transaction(function () use (
            $clientId,
            $secret,
            $name,
            $type,
            $grants,
            $scopeList,
            $accessTtl,
            $refreshTtl,
            $backchannelUri,
            $redirectUris,
            $postLogoutUris
        ): Client {
            $client = $this->clients->create(
                $clientId,
                $secret === null ? null : $this->hasher->hash($secret),
                $name,
                $type,
                $grants,
                implode(' ', $scopeList),
                $accessTtl,
                $refreshTtl,
                $backchannelUri,
                $this->clock->now(),
            );
            foreach (array_unique($redirectUris) as $uri) {
                $this->uris->add($client->id, $uri, 'login');
            }
            foreach (array_unique($postLogoutUris) as $uri) {
                $this->uris->add($client->id, $uri, 'post_logout');
            }

            return $client;
        });

        return ['client' => $client, 'secret' => $secret];
    }

    /** https wajib (http hanya localhost/127.0.0.1 untuk pengembangan); tanpa fragment, wildcard, atau userinfo. */
    public static function assertUri(string $uri): void
    {
        $parts = parse_url($uri);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if (
            !is_array($parts) || strlen($uri) > 500 || $host === '' || str_contains($uri, '#') || str_contains($uri, '*')
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $uri) === 1
            || !($scheme === 'https' || ($scheme === 'http' && $local))
        ) {
            throw new \InvalidArgumentException('URI tidak valid (wajib https, tanpa fragment/wildcard): ' . substr($uri, 0, 80));
        }
    }
}
