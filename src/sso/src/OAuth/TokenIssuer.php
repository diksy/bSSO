<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\Record\Client;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Security\KeyManager;
use App\Support\Clock;
use App\Support\Urls;

final class TokenIssuer
{
    public const ID_TOKEN_TTL = 900;
    public const LOGOUT_TOKEN_TTL = 120;

    public function __construct(
        private readonly KeyManager $keys,
        private readonly Urls $urls,
        private readonly Clock $clock,
    ) {
    }

    public function issuer(): string
    {
        return rtrim($this->urls->abs('/'), '/');
    }

    public function idToken(User $user, Client $client, SsoSession $session, string $scope, ?string $nonce): string
    {
        $now = $this->clock->now()->getTimestamp();
        $amr = $session->authMethod === 'password' ? ['pwd'] : ['fed'];
        if ($session->mfaPassedAt !== null) {
            $amr[] = 'mfa';
        }
        $claims = [
            'iss' => $this->issuer(),
            'sub' => $user->uuid,
            'aud' => $client->clientId,
            'exp' => $now + self::ID_TOKEN_TTL,
            'iat' => $now,
            'auth_time' => $session->createdAt->getTimestamp(),
            'sid' => $session->sid,
            'amr' => $amr,
        ];
        if ($nonce !== null && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }
        $claims += self::profileClaims($user, $scope);

        return $this->keys->sign($claims, 'JWT');
    }

    /** typ at+jwt (RFC 9068); $user null untuk client_credentials (sub = client_id). */
    public function accessToken(?User $user, Client $client, string $scope, ?string $sid): string
    {
        $now = $this->clock->now()->getTimestamp();
        $claims = [
            'iss' => $this->issuer(),
            'sub' => $user === null ? $client->clientId : $user->uuid,
            'aud' => $client->clientId,
            'exp' => $now + $client->accessTokenTtl,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(16)),
            'scope' => $scope,
            'client_id' => $client->clientId,
        ];
        if ($sid !== null) {
            $claims['sid'] = $sid;
        }

        return $this->keys->sign($claims, 'at+jwt');
    }

    /** typ logout+jwt, tanpa nonce, exp +2 menit. */
    public function logoutToken(Client $client, string $userUuid, string $sid): string
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->keys->sign([
            'iss' => $this->issuer(),
            'sub' => $userUuid,
            'aud' => $client->clientId,
            'iat' => $now,
            'exp' => $now + self::LOGOUT_TOKEN_TTL,
            'jti' => bin2hex(random_bytes(16)),
            'sid' => $sid,
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()],
        ], 'logout+jwt');
    }

    /**
     * Claim profil sesuai scope (dipakai id_token dan /userinfo).
     *
     * @return array<string,mixed>
     */
    public static function profileClaims(User $user, string $scope): array
    {
        $out = [];
        if (Scopes::has($scope, 'email') && $user->email !== null) {
            $out['email'] = $user->email;
            $out['email_verified'] = $user->emailVerifiedAt !== null;
        }
        if (Scopes::has($scope, 'profile')) {
            if ($user->name !== null) {
                $out['name'] = $user->name;
            }
            if ($user->avatarUrl !== null) {
                $out['picture'] = $user->avatarUrl;
            }
        }

        return $out;
    }
}
