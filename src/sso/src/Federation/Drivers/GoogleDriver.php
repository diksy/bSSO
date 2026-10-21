<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

use App\Federation\AbstractOidcProvider;
use App\Federation\NormalizedProfile;

/** Google (OIDC): email dianggap terverifikasi hanya bila klaim email_verified = true. */
final class GoogleDriver extends AbstractOidcProvider
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
            ['sub' => $data['sub'] ?? null, 'hd' => $data['hd'] ?? null],
        );
    }
}
