<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Http\Request;
use App\Repository\Record\Client;

final class ClientAuthenticator
{
    /**
     * client_secret_basic, client_secret_post, atau public client (PKCE).
     *
     * @throws OAuthException invalid_client (401)
     */
    public function authenticate(Request $request, bool $requireConfidential = false): Client
    {
        throw new \LogicException('belum diimplementasi');
    }
}
