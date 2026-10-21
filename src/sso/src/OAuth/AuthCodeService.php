<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\AuthCodeRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\Record\AuthCode;
use App\Repository\Record\Client;
use App\Repository\Record\SsoSession;
use App\Repository\SessionClientRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;

final class AuthCodeService
{
    public const TTL = 60;

    public function __construct(
        private readonly AuthCodeRepository $codes,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly SessionClientRepository $sessionClients,
        private readonly SsoSessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly Pkce $pkce,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
    ) {
    }

    /** @return string code mentah (hash disimpan, TTL 60 detik) */
    public function issue(AuthorizeRequest $request, SsoSession $session): string
    {
        $raw = self::b64url(random_bytes(32));
        $now = $this->clock->now();
        $this->db->transaction(function () use ($request, $session, $raw, $now): void {
            $this->codes->insert(new AuthCode(
                self::hash($raw),
                $request->client->id,
                $session->userId,
                $session->sid,
                $request->redirectUri,
                $request->scope,
                $request->nonce,
                $request->codeChallenge,
                'S256',
                $now->modify('+' . self::TTL . ' seconds'),
                null,
            ));
            $this->sessionClients->add($session->sid, $request->client->id, $now);
        });
        $this->audit->record('authorize.code_issued', $session->userId, $session->userId, $request->client->id);

        return $raw;
    }

    /**
     * Reuse code: cabut family via auth_code_hash dan audit token.code_reuse.
     *
     * @throws OAuthException invalid_grant
     */
    public function redeem(string $code, Client $client, string $redirectUri, string $verifier): AuthCode
    {
        if ($code === '' || strlen($code) > 128) {
            throw self::invalid();
        }
        $hash = self::hash($code);
        $now = $this->clock->now();

        // Hasil dikembalikan dari transaksi (bukan exception) agar pencabutan akibat reuse tidak ikut rollback.
        /** @var AuthCode|string $result */
        $result = $this->db->transaction(function () use ($hash, $client, $redirectUri, $verifier, $now): AuthCode|string {
            $record = $this->codes->findByHashForUpdate($hash);
            if ($record === null || !hash_equals((string) $record->codeHash, $hash)) {
                return 'invalid';
            }
            if ($record->clientId !== $client->id) {
                return 'invalid';
            }
            if ($record->usedAt !== null) {
                $this->refreshTokens->revokeByAuthCodeHash($hash, $now);

                return 'reuse';
            }
            if ($record->expiresAt <= $now || $record->redirectUri !== $redirectUri || $record->codeChallengeMethod !== 'S256') {
                return 'invalid';
            }
            if (!$this->pkce->verifyS256($verifier, $record->codeChallenge)) {
                return 'invalid';
            }
            $session = $this->sessions->findBySid($record->sessionSid);
            if ($session === null || $session->revokedAt !== null || $session->expiresAt <= $now) {
                return 'invalid';
            }
            $user = $this->users->findById($record->userId);
            if ($user === null || $user->status !== 'active') {
                return 'invalid';
            }
            if (!$this->codes->markUsed($hash, $now)) {
                return 'reuse';
            }

            return $record;
        });

        if ($result === 'reuse') {
            $this->audit->record('token.code_reuse', null, null, $client->id);
            throw self::invalid();
        }
        if (!$result instanceof AuthCode) {
            throw self::invalid();
        }

        return $result;
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function invalid(): OAuthException
    {
        return new OAuthException('invalid_grant', 'Authorization code tidak valid, kedaluwarsa, atau sudah dipakai');
    }
}
