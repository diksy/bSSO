<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOidcProvider;
use App\Federation\FederationState;
use App\Federation\NormalizedProfile;
use Firebase\JWT\JWT;

/**
 * Apple (OIDC): client_secret berupa JWT ES256 yang dibuat dari kunci .p8 (PEM terenkripsi di client_secret_enc;
 * team_id & key_id di extra_config), response_mode=form_post. Nama hanya dikirim pada login pertama lewat
 * pending_request['user']['name'] (disimpan FederationController::formPostCallback). Email privat relay tetap
 * dianggap terverifikasi karena Apple menjaminnya (email_verified boleh boolean atau string).
 */
final class AppleDriver extends AbstractOidcProvider
{
    private const AUDIENCE = 'https://appleid.apple.com';
    private const SECRET_TTL = 300;

    protected function clientSecret(): string
    {
        $pem = $this->ctx->clientSecret($this->config);
        $teamId = $this->config->extraConfig['team_id'] ?? null;
        $keyId = $this->config->extraConfig['key_id'] ?? null;
        if ($pem === '' || !is_string($teamId) || $teamId === '' || !is_string($keyId) || $keyId === '') {
            throw new \App\Federation\FederationException('Konfigurasi Apple (kunci .p8, team_id, key_id) belum lengkap');
        }
        $now = $this->ctx->clock->now()->getTimestamp();
        try {
            return JWT::encode(
                ['iss' => $teamId, 'iat' => $now, 'exp' => $now + self::SECRET_TTL, 'aud' => self::AUDIENCE, 'sub' => $this->config->clientId],
                $pem,
                'ES256',
                $keyId,
            );
        } catch (\Throwable) {
            throw new \App\Federation\FederationException('Client secret Apple tidak dapat dibuat'); // tanpa detail (bisa memuat kunci)
        }
    }

    protected function authorizeParams(): array
    {
        return ['response_mode' => 'form_post'];
    }

    protected function mapProfileWithState(array $claims, FederationState $state): NormalizedProfile
    {
        $p = $this->mapProfile($claims);
        $n = $state->pendingRequest['user']['name'] ?? null;
        $name = null;
        if (is_array($n)) {
            $parts = array_filter([Flag::str($n['firstName'] ?? null), Flag::str($n['lastName'] ?? null)]);
            $name = $parts === [] ? null : implode(' ', $parts);
        }

        return new NormalizedProfile($p->providerUserId, $p->email, $p->emailVerified, $name, null, $p->raw);
    }

    protected function mapProfile(array $data): NormalizedProfile
    {
        $email = Flag::str($data['email'] ?? null);

        return new NormalizedProfile(
            (string) Flag::str($data['sub'] ?? null),
            $email,
            $email !== null && Flag::isTrue($data['email_verified'] ?? null),
            null,
            null,
            ['sub' => $data['sub'] ?? null, 'is_private_email' => Flag::isTrue($data['is_private_email'] ?? null)],
        );
    }
}
