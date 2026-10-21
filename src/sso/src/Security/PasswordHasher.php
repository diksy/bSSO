<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordHasher
{
    /** Argon2id, fallback bcrypt. */
    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algo());
    }

    public function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo());
    }

    /** Hash tiruan untuk menyamakan waktu respons saat email tidak ada (anti-enumerasi). */
    public function dummyHash(): string
    {
        static $dummy = null;
        $dummy ??= $this->hash(bin2hex(random_bytes(16)));

        return $dummy;
    }

    private function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
