<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOidcProvider;
use App\Federation\NormalizedProfile;

/** LinkedIn (OIDC): email_verified bisa boolean atau string "true"; selain itu tidak terverifikasi. */
final class LinkedInDriver extends AbstractOidcProvider
{
    protected function mapProfile(array $data): NormalizedProfile
    {
        $email = Flag::str($data['email'] ?? null);

        return new NormalizedProfile(
            (string) Flag::str($data['sub'] ?? null),
            $email,
            $email !== null && Flag::isTrue($data['email_verified'] ?? null),
            Flag::str($data['name'] ?? null),
            Flag::str($data['picture'] ?? null),
            ['sub' => $data['sub'] ?? null],
        );
    }
}
