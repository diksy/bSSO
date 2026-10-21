<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\Record\Client;
use App\Repository\Record\RefreshToken;
use App\Repository\Record\User;
use App\Repository\RefreshTokenRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Session\SessionTerminator;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;

final class RefreshTokenService
{
    public function __construct(
        private readonly RefreshTokenRepository $tokens,
        private readonly SsoSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly SessionTerminator $terminator,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
    ) {
    }

    /** @return string token mentah (hash disimpan) */
    public function issue(
        Client $client,
        User $user,
        ?string $sessionSid,
        string $scope,
        ?string $familyId,
        ?string $authCodeHash,
    ): string {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->tokens->insert(new RefreshToken(
            self::hash($raw),
            $familyId ?? self::uuid4(),
            $authCodeHash,
            $client->id,
            $user->id,
            $sessionSid,
            $scope,
            $now,
            $now->modify('+' . $client->refreshTokenTtl . ' seconds'),
            null,
            null,
        ));

        return $raw;
    }

    /**
     * FOR UPDATE dalam transaksi; reuse: cabut family + sesi, audit token.reuse_detected.
     *
     * @throws OAuthException invalid_grant
     */
    public function rotate(string $token, Client $client): RotatedRefresh
    {
        if ($token === '' || strlen($token) > 128) {
            throw self::invalid();
        }
        $hash = self::hash($token);
        $now = $this->clock->now();
        $endSid = null;

        // Hasil dikembalikan (bukan exception) supaya pencabutan family saat reuse tidak ikut rollback.
        /** @var RotatedRefresh|string $result */
        $result = $this->db->transaction(function () use ($hash, $client, $now, &$endSid): RotatedRefresh|string {
            $rt = $this->tokens->findByHashForUpdate($hash);
            if ($rt === null || !hash_equals($rt->tokenHash, $hash) || $rt->clientId !== $client->id) {
                return 'invalid';
            }
            if ($rt->usedAt !== null) {
                $this->tokens->revokeFamily($rt->familyId, $now);
                $endSid = $rt->sessionSid;

                return 'reuse';
            }
            if ($rt->revokedAt !== null || $rt->expiresAt <= $now) {
                return 'invalid';
            }
            $user = $this->users->findById($rt->userId);
            if ($user === null || $user->status !== 'active') {
                $this->tokens->revokeFamily($rt->familyId, $now);

                return 'invalid';
            }
            if ($rt->sessionSid !== null) {
                $session = $this->sessions->findBySid($rt->sessionSid);
                if ($session === null || $session->revokedAt !== null || $session->expiresAt <= $now) {
                    $this->tokens->revokeFamily($rt->familyId, $now);

                    return 'invalid';
                }
            }
            if (!$this->tokens->markUsed($hash, $now)) {
                $this->tokens->revokeFamily($rt->familyId, $now);
                $endSid = $rt->sessionSid;

                return 'reuse';
            }
            $new = $this->issue($client, $user, $rt->sessionSid, $rt->scope, $rt->familyId, $rt->authCodeHash);

            return new RotatedRefresh($new, $rt->userId, $rt->sessionSid, $rt->scope);
        });

        if ($result === 'reuse') {
            if ($endSid !== null) {
                $this->terminator->endSession($endSid, 'token.reuse_detected');
            }
            $this->audit->record('token.reuse_detected', null, null, $client->id);
            throw self::invalid();
        }
        if (!$result instanceof RotatedRefresh) {
            throw self::invalid();
        }

        return $result;
    }

    /** Pencabutan RFC 7009: seluruh family; token milik klien lain atau tak dikenal diabaikan diam-diam. */
    public function revoke(string $token, Client $client): void
    {
        if ($token === '' || strlen($token) > 128) {
            return;
        }
        $rt = $this->tokens->findByHash(self::hash($token));
        if ($rt === null || $rt->clientId !== $client->id) {
            return;
        }
        $this->tokens->revokeFamily($rt->familyId, $this->clock->now());
        $this->audit->record('token.revoked', $rt->userId, $rt->userId, $client->id);
    }

    /** Token aktif milik klien (introspeksi), atau null. */
    public function inspect(string $token, Client $client): ?RefreshToken
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $rt = $this->tokens->findByHash(self::hash($token));
        $now = $this->clock->now();
        if ($rt === null || $rt->clientId !== $client->id || $rt->usedAt !== null || $rt->revokedAt !== null || $rt->expiresAt <= $now) {
            return null;
        }
        $user = $this->users->findById($rt->userId);
        if ($user === null || $user->status !== 'active') {
            return null;
        }
        if ($rt->sessionSid !== null) {
            $session = $this->sessions->findBySid($rt->sessionSid);
            if ($session === null || $session->revokedAt !== null || $session->expiresAt <= $now) {
                return null;
            }
        }

        return $rt;
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private static function invalid(): OAuthException
    {
        return new OAuthException('invalid_grant', 'Refresh token tidak valid, kedaluwarsa, atau sudah dipakai');
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}
