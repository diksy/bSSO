<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\Record\AuthCode;
use App\Repository\Record\Client;
use App\Repository\Record\SsoSession;

final class AuthCodeService
{
    /** @return string code mentah (hash disimpan, TTL 60 detik) */
    public function issue(AuthorizeRequest $request, SsoSession $session): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * Reuse code: cabut family via auth_code_hash dan audit token.code_reuse.
     *
     * @throws OAuthException invalid_grant
     */
    public function redeem(string $code, Client $client, string $redirectUri, string $verifier): AuthCode
    {
        throw new \LogicException('belum diimplementasi');
    }
}
