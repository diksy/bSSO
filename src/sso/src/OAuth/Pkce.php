<?php

declare(strict_types=1);

namespace App\OAuth;

final class Pkce
{
    public function verifyS256(string $verifier, string $challenge): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
