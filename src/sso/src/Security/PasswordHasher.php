<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordHasher
{
    /** Argon2id, fallback bcrypt. */
    public function hash(string $plain): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function verify(string $plain, string $hash): bool
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function needsRehash(string $hash): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
