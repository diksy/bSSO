<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\NormalizedProfile;

final class DiscordDriver extends AbstractOAuth2Provider
{
    protected function mapProfile(array $data): NormalizedProfile
    {
        throw new \LogicException('belum diimplementasi');
    }
}
