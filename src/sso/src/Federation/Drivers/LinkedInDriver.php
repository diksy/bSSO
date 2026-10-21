<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOidcProvider;
use App\Federation\NormalizedProfile;

final class LinkedInDriver extends AbstractOidcProvider
{
    protected function mapProfile(array $data): NormalizedProfile
    {
        throw new \LogicException('belum diimplementasi');
    }
}
