<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\NormalizedProfile;

/**
 * X (OAuth2 + PKCE): /2/users/me membungkus data di "data" dan tidak memberi email, jadi email null
 * (alur "lengkapi email" dengan verifikasi tautan). Token endpoint memakai Basic: extra_config.token_auth = basic.
 */
final class XDriver extends AbstractOAuth2Provider
{
    protected function mapProfile(array $userinfo): NormalizedProfile
    {
        $d = is_array($userinfo['data'] ?? null) ? $userinfo['data'] : [];

        return new NormalizedProfile(
            (string) Flag::str($d['id'] ?? null),
            null,
            false,
            Flag::str($d['name'] ?? null) ?? Flag::str($d['username'] ?? null),
            Flag::str($d['profile_image_url'] ?? null),
            ['id' => $d['id'] ?? null, 'username' => $d['username'] ?? null],
        );
    }
}
