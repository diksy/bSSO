<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\NormalizedProfile;

/** Facebook (OAuth2): Graph API tidak menjamin email terverifikasi, jadi emailVerified selalu false. */
final class FacebookDriver extends AbstractOAuth2Provider
{
    protected function mapProfile(array $userinfo): NormalizedProfile
    {
        $pic = is_array($userinfo['picture'] ?? null) && is_array($userinfo['picture']['data'] ?? null)
            ? Flag::str($userinfo['picture']['data']['url'] ?? null) : null;

        return new NormalizedProfile(
            (string) Flag::str($userinfo['id'] ?? null),
            Flag::str($userinfo['email'] ?? null),
            false,
            Flag::str($userinfo['name'] ?? null),
            $pic,
            ['id' => $userinfo['id'] ?? null],
        );
    }
}
