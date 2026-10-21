<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Federation\AbstractOidcProvider;
use App\Federation\FederationState;
use App\Federation\NormalizedProfile;

final class FakeOidcDriver extends AbstractOidcProvider
{
    protected function mapProfile(array $claims): NormalizedProfile
    {
        return new NormalizedProfile(
            (string) $claims['sub'],
            isset($claims['email']) ? (string) $claims['email'] : null,
            ($claims['email_verified'] ?? false) === true,
            isset($claims['name']) ? (string) $claims['name'] : null,
            null,
            ['sub' => $claims['sub'], 'email' => $claims['email'] ?? null, 'access_token' => 'jangan-simpan'],
        );
    }
}
