<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\Record\Client;
use App\Repository\Record\User;

final class RefreshTokenService
{
    /** @return string token mentah (hash disimpan) */
    public function issue(
        Client $client,
        User $user,
        ?string $sessionSid,
        string $scope,
        ?string $familyId,
        ?string $authCodeHash,
    ): string {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * FOR UPDATE dalam transaksi; reuse: cabut family + sesi, audit token.reuse_detected.
     *
     * @throws OAuthException invalid_grant
     */
    public function rotate(string $token, Client $client): RotatedRefresh
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function revoke(string $token, Client $client): void
    {
        throw new \LogicException('belum diimplementasi');
    }
}
