<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\Record\Client;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;

final class TokenIssuer
{
    public function idToken(User $user, Client $client, SsoSession $session, string $scope, ?string $nonce): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** typ at+jwt (RFC 9068); $user null untuk client_credentials (sub = client_id). */
    public function accessToken(?User $user, Client $client, string $scope, ?string $sid): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** typ logout+jwt, tanpa nonce, exp +2 menit. */
    public function logoutToken(Client $client, string $userUuid, string $sid): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
