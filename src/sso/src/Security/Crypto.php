<?php

declare(strict_types=1);

namespace App\Security;

final class Crypto
{
    /** @return string '<versi>:<base64(nonce+ciphertext)>' memakai versi keyring 'current' */
    public function encrypt(string $plain): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function decrypt(string $ciphertext): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** HMAC-SHA256 dengan secrets.php['hmac_key'], hex. */
    public function hmac(string $data): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function reencrypt(string $ciphertext): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
