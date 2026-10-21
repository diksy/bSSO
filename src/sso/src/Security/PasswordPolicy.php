<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordPolicy
{
    public const MIN = 12;
    public const MAX = 256;

    /** @return ?string pesan galat, null bila lolos */
    public static function check(string $password): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::MIN) {
            return 'Kata sandi minimal ' . self::MIN . ' karakter.';
        }
        if ($len > self::MAX) {
            return 'Kata sandi terlalu panjang.';
        }

        return null;
    }
}
