<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;

/**
 * OAuth2 murni: tukar code (PKCE bila ada), lalu panggil userinfo_url dengan access token.
 * Semua URL berasal dari konfigurasi provider (authorize_url, token_url, userinfo_url) dan lolos HttpClient/UrlAllowlist.
 */
abstract class AbstractOAuth2Provider implements ProviderInterface
{
    public function __construct(protected readonly IdentityProvider $config, protected readonly ProviderContext $ctx)
    {
    }

    public function authorizationUrl(string $state, ?string $nonce, ?string $pkceChallenge): string
    {
        if ($this->config->authorizeUrl === null) {
            throw new FederationException('authorize_url belum dikonfigurasi');
        }
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->ctx->callbackUrl($this->config),
            'scope' => $this->config->scopes,
            'state' => $state,
        ] + $this->authorizeParams();
        if ($pkceChallenge !== null) {
            $params['code_challenge'] = $pkceChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return ProviderContext::authorizeUrl($this->config->authorizeUrl, $params);
    }

    public function handleCallback(array $params, FederationState $state): NormalizedProfile
    {
        if (isset($params['error'])) {
            throw new FederationException('Provider mengembalikan kesalahan');
        }
        $code = $params['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new FederationException('Kode otorisasi tidak ada');
        }
        if ($this->config->tokenUrl === null || $this->config->userinfoUrl === null) {
            throw new FederationException('Endpoint provider belum dikonfigurasi');
        }
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->ctx->callbackUrl($this->config),
        ];
        if ($state->codeVerifier !== null) {
            $fields['code_verifier'] = $state->codeVerifier;
        }
        $tokens = $this->ctx->tokenRequest($this->config, $this->config->tokenUrl, $fields, $this->ctx->clientSecret($this->config));
        $access = $tokens['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw new FederationException('Provider tidak mengembalikan access token');
        }

        return self::normalize($this->mapProfile($this->fetchUserinfo($access)));
    }

    /**
     * @return array<string,mixed>
     */
    protected function fetchUserinfo(string $accessToken): array
    {
        return $this->ctx->getJson((string) $this->config->userinfoUrl, ['Authorization' => 'Bearer ' . $accessToken]);
    }

    /** @return array<string,string> parameter tambahan URL otorisasi (mis. response_mode) */
    protected function authorizeParams(): array
    {
        return [];
    }

    /** @param array<string,mixed> $userinfo */
    abstract protected function mapProfile(array $userinfo): NormalizedProfile;

    private static function normalize(NormalizedProfile $p): NormalizedProfile
    {
        return ProfileGuard::check($p);
    }
}
