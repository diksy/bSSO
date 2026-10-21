<?php

declare(strict_types=1);

namespace App\Federation;

interface ProviderInterface
{
    public function authorizationUrl(string $state, ?string $nonce, ?string $pkceChallenge): string;

    /**
     * @param array<string,mixed> $params
     * @throws FederationException
     */
    public function handleCallback(array $params, FederationState $state): NormalizedProfile;
}
