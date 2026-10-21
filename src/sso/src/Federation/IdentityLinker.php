<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\IdentityProvider;

final class IdentityLinker
{
    /** Lima cabang requirements §5. */
    public function resolve(NormalizedProfile $profile, IdentityProvider $provider, FederationState $state): LinkResult
    {
        throw new \LogicException('belum diimplementasi');
    }
}
