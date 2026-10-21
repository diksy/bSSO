<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;

final class AuthorizeRequest
{
    private const MAX_STATE = 512;
    private const MAX_NONCE = 128;

    public function __construct(
        public readonly Client $client,
        public readonly string $redirectUri,
        public readonly string $scope,
        public readonly ?string $state,
        public readonly ?string $nonce,
        public readonly string $codeChallenge,
    ) {
    }

    /**
     * @param array<string,mixed> $q
     * @throws InvalidRedirectException klien/redirect_uri tidak valid (jangan redirect)
     * @throws OAuthException kesalahan lain (redirect error ke klien)
     */
    public static function fromQuery(array $q, ClientRepository $clients, ClientRedirectUriRepository $uris): self
    {
        $str = static fn (string $k): ?string => isset($q[$k]) && is_string($q[$k]) ? $q[$k] : null;

        // 1) client_id dan redirect_uri: kegagalan TIDAK boleh me-redirect (RFC 6749 4.1.2.1).
        $clientId = $str('client_id');
        if ($clientId === null || $clientId === '' || strlen($clientId) > 80) {
            throw new InvalidRedirectException('client');
        }
        $client = $clients->findByClientId($clientId);
        if ($client === null || !$client->isEnabled) {
            throw new InvalidRedirectException('client');
        }
        $redirectUri = $str('redirect_uri');
        if ($redirectUri === null || $redirectUri === '' || strlen($redirectUri) > 500 || !$uris->exists($client->id, $redirectUri, 'login')) {
            throw new InvalidRedirectException('redirect_uri');
        }

        // 2) Dari sini redirect_uri sah: kesalahan dikembalikan lewat redirect ke klien.
        $state = $str('state');
        $fail = static fn (string $error, string $description): OAuthException => new OAuthException(
            $error,
            $description,
            400,
            $redirectUri,
            $state !== null && strlen($state) <= self::MAX_STATE ? $state : null,
        );

        if ($state !== null && strlen($state) > self::MAX_STATE) {
            throw $fail('invalid_request', 'state terlalu panjang');
        }
        if ($str('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Hanya response_type=code yang didukung');
        }
        if (!in_array('authorization_code', $client->allowedGrants, true)) {
            throw $fail('unauthorized_client', 'Klien tidak boleh memakai authorization code');
        }

        $requested = $str('scope');
        if ($requested === null || trim($requested) === '') {
            $scope = implode(' ', Scopes::split($client->allowedScopes));
        } else {
            $tokens = Scopes::split($requested);
            $scope = (new Scopes())->intersect($requested, $client->allowedScopes);
            // Ada token cacat atau di luar izin klien: tolak (tidak diam-diam dikurangi).
            if ($tokens === [] || count($tokens) !== count(Scopes::split($scope))) {
                throw $fail('invalid_scope', 'Scope tidak valid atau tidak diizinkan untuk klien ini');
            }
        }
        if ($scope === '') {
            throw $fail('invalid_scope', 'Klien tidak punya scope yang diizinkan');
        }

        // PKCE S256 wajib untuk semua klien; tidak ada downgrade ke plain atau tanpa PKCE.
        $challenge = $str('code_challenge');
        if ($challenge === null || $challenge === '') {
            throw $fail('invalid_request', 'code_challenge wajib (PKCE)');
        }
        if ($str('code_challenge_method') !== 'S256') {
            throw $fail('invalid_request', 'code_challenge_method harus S256');
        }
        if (!Pkce::validChallenge($challenge)) {
            throw $fail('invalid_request', 'code_challenge tidak valid');
        }

        $nonce = $str('nonce');
        if ($nonce !== null && ($nonce === '' || strlen($nonce) > self::MAX_NONCE)) {
            throw $fail('invalid_request', 'nonce tidak valid');
        }

        return new self($client, $redirectUri, $scope, $state, $nonce, $challenge);
    }
}
