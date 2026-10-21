<?php

declare(strict_types=1);

namespace App\Security;

final class RateLimiter
{
    /** 5 gagal per email / 20 gagal per IP dalam 15 menit. */
    public function loginBlocked(string $email, string $ip): bool
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @param string $identifier email, 'token:<client_id>', atau 'mfa:<user_id>' */
    public function record(string $identifier, string $ip, bool $success): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** 60 request per klien per menit. */
    public function tokenAllowed(string $clientId): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
