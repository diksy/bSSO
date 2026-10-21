<?php

declare(strict_types=1);

namespace App\Security;

final class Totp
{
    public function generateSecret(): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function provisioningUri(string $secret, string $label): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return int|null step yang lolos (jendela ±1), null jika gagal atau step <= $lastStep */
    public function verify(string $secret, string $code, ?int $lastStep): ?int
    {
        throw new \LogicException('belum diimplementasi');
    }
}
