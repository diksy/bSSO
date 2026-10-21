<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\NormalizedProfile;

final class FakeOAuth2Driver extends AbstractOAuth2Provider
{
    protected function mapProfile(array $userinfo): NormalizedProfile
    {
        return new NormalizedProfile(
            (string) ($userinfo['id'] ?? ''),
            isset($userinfo['email']) ? (string) $userinfo['email'] : null,
            ($userinfo['verified'] ?? false) === true,
            isset($userinfo['name']) ? (string) $userinfo['name'] : null,
            null,
            ['id' => $userinfo['id'] ?? null],
        );
    }
}
