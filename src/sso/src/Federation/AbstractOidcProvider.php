<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;

abstract class AbstractOidcProvider implements ProviderInterface
{
    public function __construct(protected readonly IdentityProvider $config)
    {
    }

    public function authorizationUrl(string $state, ?string $nonce, ?string $pkceChallenge): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Tukar code (PKCE), validasi id_token: signature JWKS, iss, aud, exp, nonce. */
    public function handleCallback(array $params, FederationState $state): NormalizedProfile
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @param array<string,mixed> $claims */
    abstract protected function mapProfile(array $claims): NormalizedProfile;
}
