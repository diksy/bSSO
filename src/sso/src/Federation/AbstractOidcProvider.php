<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * OIDC: discovery (cache 24 jam), tukar code dengan PKCE, validasi id_token ketat:
 * alg dipin ke RS256 (none/HS* ditolak), tanda tangan lewat JWKS provider (kid tak dikenal -> ambil ulang JWKS sekali),
 * iss, aud (+azp), exp, nonce. Semua URL dari konfigurasi provider/discovery-nya dan lolos HttpClient/UrlAllowlist.
 */
abstract class AbstractOidcProvider implements ProviderInterface
{
    private const DISCOVERY_TTL = 86400;
    private const JWKS_TTL = 3600;
    private const LEEWAY = 60;
    private const MAX_ID_TOKEN = 16384;

    public function __construct(protected readonly IdentityProvider $config, protected readonly ProviderContext $ctx)
    {
    }

    public function authorizationUrl(string $state, ?string $nonce, ?string $pkceChallenge): string
    {
        if ($nonce === null || $nonce === '') {
            throw new FederationException('nonce wajib untuk OIDC');
        }
        $endpoint = $this->config->authorizeUrl ?? $this->discoveryValue('authorization_endpoint');
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->ctx->callbackUrl($this->config),
            'scope' => $this->config->scopes,
            'state' => $state,
            'nonce' => $nonce,
        ] + $this->authorizeParams();
        if ($pkceChallenge !== null) {
            $params['code_challenge'] = $pkceChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return ProviderContext::authorizeUrl($endpoint, $params);
    }

    /** Tukar code (PKCE), validasi id_token: signature JWKS, iss, aud, exp, nonce. */
    public function handleCallback(array $params, FederationState $state): NormalizedProfile
    {
        if (isset($params['error'])) {
            throw new FederationException('Provider mengembalikan kesalahan');
        }
        $code = $params['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            throw new FederationException('Kode otorisasi tidak ada');
        }
        if ($state->nonce === null || $state->nonce === '') {
            throw new FederationException('State tanpa nonce');
        }
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->ctx->callbackUrl($this->config),
        ];
        if ($state->codeVerifier !== null) {
            $fields['code_verifier'] = $state->codeVerifier;
        }
        $tokens = $this->ctx->tokenRequest(
            $this->config,
            $this->config->tokenUrl ?? $this->discoveryValue('token_endpoint'),
            $fields,
            $this->clientSecret(),
        );
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '' || strlen($idToken) > self::MAX_ID_TOKEN) {
            throw new FederationException('Provider tidak mengembalikan id_token');
        }
        $claims = $this->validateIdToken($idToken, $state->nonce);

        return ProfileGuard::check($this->mapProfileWithState($claims, $state));
    }

    /** Secret klien; Apple menimpanya dengan JWT ES256 buatan sendiri. */
    protected function clientSecret(): string
    {
        return $this->ctx->clientSecret($this->config);
    }

    /** @return list<string> algoritma id_token yang diizinkan (dipin, tak pernah none/HS*) */
    protected function allowedAlgs(): array
    {
        return ['RS256'];
    }

    /** @return array<string,string> parameter tambahan URL otorisasi (mis. response_mode=form_post pada Apple) */
    protected function authorizeParams(): array
    {
        return [];
    }

    /**
     * Cocokkan iss. Default: sama persis dengan extra_config.issuer atau issuer pada discovery.
     * Microsoft (endpoint common) menimpa dengan pola tenant.
     *
     * @param array<string,mixed> $claims
     */
    protected function issuerMatches(string $iss, array $claims): bool
    {
        $expected = $this->config->extraConfig['issuer'] ?? $this->discoveryValue('issuer');

        return is_string($expected) && $expected !== '' && hash_equals($expected, $iss);
    }

    /**
     * @param array<string,mixed> $claims
     */
    protected function mapProfileWithState(array $claims, FederationState $state): NormalizedProfile
    {
        return $this->mapProfile($claims);
    }

    /** @param array<string,mixed> $claims */
    abstract protected function mapProfile(array $claims): NormalizedProfile;

    /**
     * @return array<string,mixed> claims tervalidasi
     * @throws FederationException
     */
    protected function validateIdToken(string $jwt, string $expectedNonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new FederationException('id_token tidak valid');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        if (!is_string($alg) || !in_array($alg, $this->allowedAlgs(), true)) {
            throw new FederationException('Algoritma id_token tidak diizinkan');
        }
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $key = $this->findKey($kid, $alg, false) ?? $this->findKey($kid, $alg, true);
        if ($key === null) {
            throw new FederationException('Kunci penandatangan id_token tidak dikenal');
        }

        $prevLeeway = JWT::$leeway;
        $prevTs = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $this->ctx->clock->now()->getTimestamp();
        try {
            $decoded = JWT::decode($jwt, $key);
        } catch (\Throwable) {
            throw new FederationException('Tanda tangan atau masa berlaku id_token tidak valid');
        } finally {
            JWT::$leeway = $prevLeeway;
            JWT::$timestamp = $prevTs;
        }
        /** @var array<string,mixed> $claims */
        $claims = json_decode((string) json_encode($decoded), true);

        $iss = $claims['iss'] ?? null;
        if (!is_string($iss) || !$this->issuerMatches($iss, $claims)) {
            throw new FederationException('Penerbit (iss) id_token tidak cocok');
        }
        $aud = $claims['aud'] ?? null;
        $audList = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($this->config->clientId, $audList, true)) {
            throw new FederationException('Audiens (aud) id_token tidak cocok');
        }
        if (count($audList) > 1 && ($claims['azp'] ?? null) !== $this->config->clientId) {
            throw new FederationException('azp id_token tidak cocok');
        }
        if (!is_int($claims['exp'] ?? null)) {
            throw new FederationException('id_token tanpa exp');
        }
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new FederationException('Nonce id_token tidak cocok');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new FederationException('id_token tanpa sub');
        }

        return $claims;
    }

    private function findKey(?string $kid, string $alg, bool $refresh): ?Key
    {
        $jwks = $this->ctx->cached(
            'jwks:' . $this->config->id . ':' . $this->jwksUri(),
            self::JWKS_TTL,
            fn (): array => $this->ctx->getJson($this->jwksUri()),
            $refresh,
        );
        $candidates = [];
        foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
            if (
                !is_array($jwk) || ($jwk['kty'] ?? null) !== 'RSA' || isset($jwk['use']) && $jwk['use'] !== 'sig'
                || isset($jwk['alg']) && $jwk['alg'] !== $alg
            ) {
                continue;
            }
            if ($kid !== null && ($jwk['kid'] ?? null) !== $kid) {
                continue;
            }
            $candidates[] = $jwk;
        }
        // Tanpa kid di header hanya diterima bila JWKS punya tepat satu kunci yang cocok.
        if (count($candidates) !== 1) {
            return null;
        }
        try {
            return JWK::parseKey($candidates[0] + ['alg' => $alg], $alg);
        } catch (\Throwable) {
            return null;
        }
    }

    private function jwksUri(): string
    {
        $uri = $this->config->extraConfig['jwks_uri'] ?? $this->discoveryValue('jwks_uri');

        return is_string($uri) ? $uri : throw new FederationException('jwks_uri tidak tersedia');
    }

    private function discoveryValue(string $key): string
    {
        if ($this->config->discoveryUrl === null) {
            throw new FederationException('discovery_url belum dikonfigurasi');
        }
        $url = $this->config->discoveryUrl;
        $doc = $this->ctx->cached(
            'disc:' . $this->config->id . ':' . $url,
            self::DISCOVERY_TTL,
            fn (): array => $this->ctx->getJson($url),
        );
        $value = $doc[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new FederationException('Dokumen discovery provider tidak lengkap');
        }

        return $value;
    }
}
