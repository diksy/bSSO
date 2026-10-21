<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOAuth2Provider;
use App\Federation\NormalizedProfile;

/** Discord (OAuth2): terverifikasi hanya bila field verified === true. Avatar dari CDN dengan id/hash tervalidasi. */
final class DiscordDriver extends AbstractOAuth2Provider
{
    protected function mapProfile(array $userinfo): NormalizedProfile
    {
        $id = Flag::str($userinfo['id'] ?? null) ?? '';
        $hash = Flag::str($userinfo['avatar'] ?? null);
        $avatar = $hash !== null && preg_match('/^\d{1,25}$/', $id) === 1 && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $hash) === 1
            ? 'https://cdn.discordapp.com/avatars/' . $id . '/' . $hash . '.png' : null;
        $email = Flag::str($userinfo['email'] ?? null);

        return new NormalizedProfile(
            $id,
            $email,
            $email !== null && ($userinfo['verified'] ?? null) === true,
            Flag::str($userinfo['global_name'] ?? null) ?? Flag::str($userinfo['username'] ?? null),
            $avatar,
            ['id' => $id, 'username' => $userinfo['username'] ?? null],
        );
    }
}
