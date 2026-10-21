<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\ClientRepository;
use App\Repository\Record\User;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Security\KeyManager;
use App\Support\Clock;

/** Verifikasi access token JWT: tanda tangan RS256 + kid, typ at+jwt, iss, exp, lalu status user/sesi/klien di DB. */
final class AccessTokenVerifier
{
    public function __construct(
        private readonly KeyManager $keys,
        private readonly TokenIssuer $issuer,
        private readonly UserRepository $users,
        private readonly SsoSessionRepository $sessions,
        private readonly ClientRepository $clients,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{claims: array<string,mixed>, user: ?User}|null null bila token tidak valid/tidak aktif
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($header) || ($header['typ'] ?? null) !== 'at+jwt') {
            return null;
        }
        try {
            $claims = $this->keys->verify($jwt);
        } catch (\Throwable) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        $clientId = $claims['client_id'] ?? null;
        $scope = $claims['scope'] ?? null;
        if (
            ($claims['iss'] ?? null) !== $this->issuer->issuer() || !is_string($sub) || !is_string($clientId)
            || !is_string($scope) || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $this->clock->now()->getTimestamp()
        ) {
            return null;
        }
        $client = $this->clients->findByClientId($clientId);
        if ($client === null || !$client->isEnabled) {
            return null;
        }
        $sid = $claims['sid'] ?? null;
        if ($sid === null) {
            // client_credentials: sub = client_id.
            return $sub === $clientId ? ['claims' => $claims, 'user' => null] : null;
        }
        if (!is_string($sid)) {
            return null;
        }
        $session = $this->sessions->findBySid($sid);
        $now = $this->clock->now();
        if ($session === null || $session->revokedAt !== null || $session->expiresAt <= $now) {
            return null;
        }
        $user = $this->users->findById($session->userId);
        if ($user === null || $user->status !== 'active' || !hash_equals($user->uuid, $sub)) {
            return null;
        }

        return ['claims' => $claims, 'user' => $user];
    }
}
