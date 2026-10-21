<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Http\Request;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;
use App\Security\PasswordHasher;

final class ClientAuthenticator
{
    public function __construct(private readonly ClientRepository $clients, private readonly PasswordHasher $hasher)
    {
    }

    /**
     * Mengambil kredensial klien (Basic atau body) tanpa memverifikasi; untuk rate limit.
     *
     * @return array{0: ?string, 1: ?string, 2: string} [client_id, secret, metode 'basic'|'post'|'none']
     */
    public static function credentials(Request $request): array
    {
        $header = $request->headers['authorization'] ?? '';
        if (preg_match('/^Basic +([A-Za-z0-9+\/]+=*)$/i', $header, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret), 'basic'];
            }

            return [null, null, 'basic'];
        }
        $id = $request->body['client_id'] ?? null;
        $secret = $request->body['client_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            return [is_string($id) ? $id : null, $secret, 'post'];
        }

        return [is_string($id) && $id !== '' ? $id : null, null, 'none'];
    }

    /**
     * client_secret_basic, client_secret_post, atau public client (PKCE).
     *
     * @throws OAuthException invalid_client (401)
     */
    public function authenticate(Request $request, bool $requireConfidential = false): Client
    {
        [$id, $secret, $method] = self::credentials($request);
        $hasBasic = $method === 'basic';
        if ($hasBasic && is_string($request->body['client_secret'] ?? null) && $request->body['client_secret'] !== '') {
            throw new OAuthException('invalid_request', 'Gunakan satu metode autentikasi klien saja');
        }
        if ($id === null || $id === '' || strlen($id) > 80) {
            throw new OAuthException('invalid_client', 'Autentikasi klien gagal', 401);
        }
        // Basic + client_id di body harus konsisten.
        $bodyId = $request->body['client_id'] ?? null;
        if ($hasBasic && is_string($bodyId) && $bodyId !== $id) {
            throw new OAuthException('invalid_client', 'Autentikasi klien gagal', 401);
        }

        $client = $this->clients->findByClientId($id);
        $hash = $client?->clientSecretHash;
        if ($client === null || !$client->isEnabled) {
            // Waktu seragam: tetap menjalankan verifikasi hash tiruan.
            $this->hasher->verify($secret ?? '', $this->hasher->dummyHash());
            throw new OAuthException('invalid_client', 'Autentikasi klien gagal', 401);
        }

        if ($client->type === 'confidential') {
            if ($secret === null || $secret === '' || $hash === null || !$this->hasher->verify($secret, $hash)) {
                if ($hash === null || $secret === null) {
                    $this->hasher->verify('', $this->hasher->dummyHash());
                }
                throw new OAuthException('invalid_client', 'Autentikasi klien gagal', 401);
            }

            return $client;
        }

        // Public client: tanpa secret (PKCE yang mengamankan); tidak boleh mengirim secret atau dipakai di endpoint confidential.
        if ($requireConfidential || $hash !== null || ($secret !== null && $secret !== '')) {
            throw new OAuthException('invalid_client', 'Autentikasi klien gagal', 401);
        }

        return $client;
    }
}
