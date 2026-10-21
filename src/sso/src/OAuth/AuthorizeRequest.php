<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;

final class AuthorizeRequest
{
    public function __construct(
        public readonly Client $client,
        public readonly string $redirectUri,
        public readonly string $scope,
        public readonly ?string $state,
        public readonly ?string $nonce,
        public readonly string $codeChallenge,
    ) {
    }

    /**
     * @param array<string,mixed> $q
     * @throws InvalidRedirectException klien/redirect_uri tidak valid (jangan redirect)
     * @throws OAuthException kesalahan lain (redirect error ke klien)
     */
    public static function fromQuery(array $q, ClientRepository $clients, ClientRedirectUriRepository $uris): self
    {
        throw new \LogicException('belum diimplementasi');
    }
}
